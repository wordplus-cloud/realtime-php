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

// On a demo, a window may be someone else: its address names their token, and its requests carry it as a bearer.
if (demo()) {
    if (preg_match('#^/as/([\w.-]+)$#', $path, $as) && 'GET' === $method) {
        $path = '/';
        $me = person(demo_user_of($as[1])) ?? $me;
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
        // The visitor address as the proxy in front saw it: the last of X-Forwarded-For, the one it added. The
        // ones before it are whatever the visitor sent.
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $s = demo_open_shop(trim(end($forwarded)) ?: ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        session_regenerate_id(true);
        $_SESSION['user'] = $s * 100 + 34;
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
        'routes' => ['status' => '/orders/'.$order['id'].'/status', 'wire' => '/orders/'.$order['id'].'/wire', 'messages' => '/chat/messages'],
        'rooms' => rooms_of($me['shop']),
        'events' => ['order' => 'updated', 'chat' => 'message'],
        'headers' => new stdClass(),
    ] + (demo() ? ['demo' => demo_frame($me)] : []);
    require __DIR__.'/../templates/page.php';
    exit;
}

http_response_code(404);
