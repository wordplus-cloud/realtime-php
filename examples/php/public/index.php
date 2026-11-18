<?php

// The API demo in plain PHP: sign in as Ann (support), Ben (a customer) or Cara (the warehouse), then watch the order of
// order move on, chat, see who is in the shop and who is online, call them, and meet in the daily meeting.
//
//   cp .env.example .env     # your app key and secret from WordPlus Cloud, and a local key of your own
//   composer install         # wordplus/realtime-php
//   php -S localhost:8000 -t public public/index.php
//
// Behind Apache or nginx, send every request that is not a file in public/ to this one.

declare(strict_types=1);

// The built-in PHP server: the page files as they are.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__.'/../src/app.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// A demo (WORDPLUS_DEMO=1): a shop for each visitor, and windows that are Ben or Cara (demo.php).
if (demo()) {
    require __DIR__.'/../src/demo.php';
    session_name('__Host-shop');
    session_set_cookie_params(['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

// A session for signing in. Requests that only read it close it at once, so the requests of a page do not wait on each other.
session_start(['read_and_close' => !in_array($path, ['/login', '/logout'], true)]);
$me = person($_SESSION['user'] ?? null);

// A new visitor of a demo opening its start page gets their shop there and then, with no redirect to /login and back,
// which a search engine, keeping no cookie, would follow without end.
if (demo() && null === $me && '/' === $path && 'GET' === $method) {
    $me = person(demo_start());
}

// On a demo, a window may be someone else: its address names their token, and its requests carry it as a bearer. No
// search engine keeps a window (noindex): each new shop has new tokens, so it would find new addresses without end.
if (demo()) {
    if (preg_match('#^/as/([\w.-]+)$#', $path, $as) && 'GET' === $method) {
        $path = '/';
        $me = person(demo_user_of($as[1])) ?? $me;
        header('X-Robots-Tag: noindex, nofollow');
    } elseif (null !== ($bearer = demo_user_of(preg_replace('/^Bearer /', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '')))) {
        $me = person($bearer);
    }
}

/** A JSON answer. */
function answer(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

/**
 * The JSON body of the request, for the POST routes of the shop itself. JSON only: a page on another site cannot send it without
 * asking first, as the realtime routes take it, so they need no CSRF token.
 */
function json_body(): array
{
    if (!str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        answer(['ok' => false], 415);
    }

    return json_decode((string) file_get_contents('php://input'), true) ?: [];
}

// The browser files of the SDK, from the package itself: GET /api/realtime/files/{version}/{name}.
if ($realtime->respondFile()) {
    exit;
}

// The browser routes: POST /api/realtime/auth, /calls and /keys, for the user of the session. And the realtime server
// webhook, which it signs itself.
if (preg_match('#^/api/realtime/(auth|calls|keys|webhook)$#', $path, $route)) {
    $realtime->respond($route[1], $me['id'] ?? null);
    exit;
}

if ('/login' === $path) {
    // A new visitor of a demo: a shop of their own, and they are its Ann.
    if (demo()) {
        demo_start();
        header('Location: /', true, 303);
        exit;
    }
    if ('POST' === $method && 0 === (person($_POST['as'] ?? null)['shop'] ?? null)) {
        session_regenerate_id(true);
        $_SESSION['user'] = (int) $_POST['as'];
        header('Location: /', true, 303);
        exit;
    }
    require __DIR__.'/../templates/login.php';
    exit;
}

if ('/logout' === $path && 'POST' === $method) {
    session_destroy();
    header('Location: /login', true, 303);
    exit;
}

// #region demo: members
// GET /api/call-token?to=34&type=video: a call between two people of a shop, asked by <call-button> and <online-list> as a
// call button is pressed. The app's own check, then the token that lets this user call that one.
if ('/api/call-token' === $path && 'GET' === $method) {
    $to = (int) ($_GET['to'] ?? 0);

    // Two people of the same shop, one of them staff: customers never call each other.
    if (null === $me || null === person($to) || $me['id'] === $to || !same_shop($me['id'], $to) || (!$me['staff'] && !is_staff($to))) {
        answer(['error' => 'forbidden'], 403);
    }

    answer(['token' => $realtime->callToken($me['id'], $to, ['type' => $_GET['type'] ?? null])]);
}
// #endregion

// #region demo: meeting
// GET /api/meeting-token?id=daily: the daily meeting of a shop, a group call, asked by <group-call-button> as it is
// pressed. The name comes from the id: meeting:daily for the shop of the guides, meeting:7 for a demo shop.
if ('/api/meeting-token' === $path && 'GET' === $method) {
    $id = (string) ($_GET['id'] ?? '');

    // Everyone in the shop, and nobody else.
    if (null === $me || $me['shop'] !== ('daily' === $id ? 0 : (ctype_digit($id) ? (int) $id : null))) {
        answer(['error' => 'forbidden'], 403);
    }

    answer(['token' => $realtime->groupCallToken($me['id'], 'meeting:'.$id)]);
}
// #endregion

// #region demo: show
// GET /api/show-token?id=mugs: the live show of a shop, asked by <live-stream>, again as each ten-minute token runs out.
// The name comes from the id: show:mugs for the shop of the guides, show:7 for a demo shop.
if ('/api/show-token' === $path && 'GET' === $method) {
    $name = 'show:'.(string) ($_GET['id'] ?? '');

    // 1. A show of a shop here: any other is refused.
    $s = shop_of_room($name);
    if (null === $s || !isset(shops()[(string) $s])) {
        answer(['error' => 'forbidden'], 403);
    }

    // 2. Ann of the shop presents it, and everyone else watches and writes in its chat, signed in or not.
    $host = null !== $me && $s === $me['shop'] && 'Ann' === $me['name'];
    answer(['token' => $realtime->streamToken($me['id'] ?? null, $name, ($host ? ['role' => 'host'] : []) + ['settings' => ['reactions' => true]])]);
}
// #endregion

if (null === $me) {
    'GET' === $method ? header('Location: /login', true, 303) : answer(['error' => 'sign_in'], 401);
    exit;
}

// #region demo: order
// The staff move the order on: saved, then emitted to its room, and a notice to its customer alone. `except` skips the
// tab that made the change.
if (preg_match('#^/orders/(\d+)/status$#', $path, $route) && 'POST' === $method) {
    $body = json_body();
    $status = $body['status'] ?? null;
    $order = order_of($route[1]);
    if (null === $order || !$me['staff'] || !same_shop($me['id'], $order['customer']) || !in_array($status, STATUSES, true)) {
        answer(['ok' => false], 403);
    }

    shops(function (array &$shops) use ($order, $status) {
        $shops[(string) intdiv($order['id'], 10000)]['order']['status'] = $status;
    });
    $realtime->to('order:'.$order['id'])->except($_SERVER['HTTP_X_REALTIME_TAB'] ?? null)->emit('updated', ['id' => $order['id'], 'status' => $status]);
    $realtime->toUser($order['customer'])->emit('notice', ['text' => 'Your order #'.$order['number'].' is '.$status.'.']);

    answer(['ok' => true]);
}
// #endregion

// What the cloud carries of the order update: its room and event as they are, its data sealed with the room key,
// which WordPlus Cloud never holds. The page shows it beside the update as it opened it.
if (preg_match('#^/orders/(\d+)/wire$#', $path, $route) && 'GET' === $method) {
    $order = order_of($route[1]);
    if (null === $order || !same_shop($me['id'], $order['customer'])) {
        answer(['ok' => false], 403);
    }
    $room = 'order:'.$order['id'];
    answer(['room' => $room, 'event' => 'updated', 'data' => $realtime->keys()->sealJson(['id' => $order['id'], 'status' => $order['status']], $realtime->keys()->roomLabel($room))]);
}

// #region demo: chat
// The chat this server keeps, for one of its two: the newest page, or `?before=<id>` the page before that message.
if ('/chat/messages' === $path && 'GET' === $method) {
    if (!in_array($me['id'] % 100, [34, 42], true)) {
        answer(['error' => 'not_in_chat'], 403);
    }
    answer(chat_page(shops()[(string) $me['shop']]['chat'] ?? [], isset($_GET['before']) ? (string) $_GET['before'] : null));
}
// #endregion

// The shop page: the user realtime settings (keys and identity included) and the shop data, rendered in.
if ('/' === $path) {
    $order = shops()[(string) $me['shop']]['order'];
    $head = $realtime->head($me['id']);
    $shop = [
        'me' => $me,
        'people' => people_of($me['shop']),
        'order' => $order,
        'chat' => ['between' => [$me['shop'] * 100 + 34, $me['shop'] * 100 + 42]],
        'product' => ['name' => 'Two ceramic mugs', 'picture' => '/products/mugs.webp'],
        'routes' => [
            'status' => '/orders/'.$order['id'].'/status',
            'wire' => '/orders/'.$order['id'].'/wire',
            'messages' => '/chat/messages',
            // The routes that start a call, the meeting and the show, each with the id of the shop's own.
            'callToken' => '/api/call-token',
            'meetingToken' => '/api/meeting-token?id='.explode(':', rooms_of($me['shop'])['meeting'], 2)[1],
            'showToken' => '/api/show-token?id='.explode(':', rooms_of($me['shop'])['show'], 2)[1],
        ],
        'rooms' => rooms_of($me['shop']),
        'events' => ['order' => 'updated', 'chat' => 'message'],
        'headers' => new stdClass(),
    ] + (demo() ? ['demo' => demo_frame($me)] : []);
    require __DIR__.'/../templates/page.php';
    exit;
}

http_response_code(404);
