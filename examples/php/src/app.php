<?php

// The shop setup, for every request: its environment, the realtime SDK with its rooms and calls and who may use them,
// and the shop people and data. A real app has its users and orders in a database. This one keeps its shops in a
// JSON file.
//
// Shop 0 is the one of this example. A demo (WORDPLUS_DEMO=1, demo.php) opens a shop for each visitor, numbered s, whose people
// are s × 100 + 34, + 42 and + 43 and whose order is s × 10000 + 1042: every rule finds the shop of a user by arithmetic, so
// the people of one shop never reach another.

declare(strict_types=1);

use WordPlus\Realtime\Realtime;

require __DIR__.'/../vendor/autoload.php';

// .env, for the built-in PHP server: a real host sets these in its environment.
if (is_file(__DIR__.'/../.env')) {
    foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $line, $m) && false === getenv($m[1])) {
            putenv($m[1].'='.$m[2]);
        }
    }
}

const PEOPLE = [
    ['id' => 34, 'name' => 'Ann', 'role' => 'Support', 'staff' => true, 'avatar' => '/avatars/ann.svg'],
    ['id' => 42, 'name' => 'Ben', 'role' => 'Customer', 'staff' => false, 'avatar' => '/avatars/ben.svg'],
    ['id' => 43, 'name' => 'Cara', 'role' => 'Warehouse', 'staff' => true, 'avatar' => '/avatars/cara.svg'],
];
const STATUSES = ['placed', 'packed', 'shipped', 'delivered'];

function demo(): bool
{
    return '1' === getenv('WORDPLUS_DEMO');
}

/**
 * A shop as it opens: its order, placed, and the chat as this server keeps it, its first two messages. What Ann and Ben
 * say next comes by webhook (keep_chat).
 */
function open_shop(int $s): array
{
    $now = (int) floor(microtime(true) * 1000);

    return [
        'order' => ['id' => $s * 10000 + 1042, 'number' => 1042, 'customer' => $s * 100 + 42, 'item' => 'Two ceramic mugs', 'status' => 'placed'],
        'chat' => [
            ['id' => 'first-1', 'from' => $s * 100 + 42, 'text' => 'Hi! Has my order left yet?', 'at' => $now - 120000, 'reactions' => new stdClass()],
            ['id' => 'first-2', 'from' => $s * 100 + 34, 'text' => 'Hi Ben, it is being packed now. I will let you know.', 'at' => $now - 60000, 'reactions' => new stdClass()],
        ],
        'opened' => time(),
    ];
}

/**
 * The chat as this server keeps it, from the webhook: a message by the id of its room event, once. A reaction as the
 * one key on it, an empty key taking it back. A real app saves them in its database.
 */
function keep_chat(array &$chat, array $event): void
{
    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $from = (int) ($event['from'] ?? 0);
    $id = $event['event_id'] ?? null;
    if ('message' === $event['event'] && null !== $id && !in_array($id, array_column($chat, 'id'), true)) {
        $chat[] = ['id' => $id, 'from' => $from, 'text' => (string) ($data['text'] ?? ''), 'at' => (int) ($event['at'] ?? 0), 'reactions' => new stdClass()];
    }
    if ('reaction' !== $event['event']) {
        return;
    }
    foreach ($chat as &$message) {
        if ($message['id'] !== ($data['id'] ?? null)) {
            continue;
        }
        $reactions = [];
        foreach ((array) $message['reactions'] as $key => $users) {
            $kept = array_values(array_filter($users, fn ($user) => $user !== $from));
            if ($kept) {
                $reactions[(string) $key] = $kept;
            }
        }
        if (is_string($data['key'] ?? null) && '' !== $data['key']) {
            $reactions[$data['key']][] = $from;
        }
        $message['reactions'] = (object) $reactions;
    }
}

/** A page of the chat, oldest first: the newest 50, or the 50 before the message `$before`. */
function chat_page(array $chat, ?string $before): array
{
    $end = null !== $before ? array_search($before, array_column($chat, 'id'), true) : false;
    $upTo = false === $end ? count($chat) : $end;
    $start = max(0, $upTo - 50);
    // Reactions as an object, by key, even with none: the store JSON reads an empty one back as a list.
    $page = array_map(fn (array $m) => ['reactions' => (object) $m['reactions']] + $m, array_slice($chat, $start, $upTo - $start));

    return ['messages' => $page, 'more' => $start > 0];
}

/**
 * The shops, by number, changed under a lock: `$change` gets them and may alter them. Shop 0 is there unless this is a
 * demo, whose shops belong to its visitors alone.
 */
function shops(?callable $change = null): array
{
    static $read = null;
    if (null !== $read && null === $change) {
        return $read;
    }

    $file = sys_get_temp_dir().'/wordplus-example-shop'.(demo() ? '-demo' : '').'.json';
    $handle = fopen($file, 'c+');
    flock($handle, $change ? LOCK_EX : LOCK_SH);

    $shops = json_decode((string) stream_get_contents($handle), true) ?: (demo() ? [] : ['0' => open_shop(0)]);

    if ($change) {
        $change($shops);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode((object) $shops, JSON_THROW_ON_ERROR));
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $read = $shops;
}

function shop_of(mixed $user): int
{
    return intdiv((int) $user, 100);
}

/** The people of a shop, their ids numbered from that of the shop. */
function people_of(int $s): array
{
    return array_map(fn (array $p) => ['id' => $s * 100 + $p['id'], 'shop' => $s] + $p, PEOPLE);
}

function person(mixed $id): ?array
{
    if (!is_numeric($id) || !isset(shops()[(string) shop_of($id)])) {
        return null;
    }
    foreach (people_of(shop_of($id)) as $p) {
        if ($p['id'] === (int) $id) {
            return $p;
        }
    }

    return null;
}

function is_staff(mixed $id): bool
{
    return (bool) (person($id)['staff'] ?? false);
}

/** Whether they are all people of one shop. */
function same_shop(mixed ...$ids): bool
{
    foreach ($ids as $id) {
        if (null === person($id) || shop_of($id) !== shop_of($ids[0])) {
            return false;
        }
    }

    return true;
}

/** An order by its id, or null. */
function order_of(mixed $id): ?array
{
    $order = shops()[(string) intdiv((int) $id, 10000)]['order'] ?? null;

    return null !== $order && $order['id'] === (int) $id ? $order : null;
}

/** The rooms of a shop, by name: those of shop 0 as the guides name them, those of a demo shop with its number. */
function rooms_of(int $s): array
{
    return [
        'order' => 'order:'.($s * 10000 + 1042),
        'chat' => 'chat:'.($s * 100 + 34).':'.($s * 100 + 42),
        'shop' => $s ? 'shop:'.$s : 'shop',
        'meeting' => $s ? 'meeting:'.$s : 'meeting:daily',
        'product' => $s ? 'product:'.$s : 'product:mugs',
        'webhooks' => $s ? 'webhooks:'.$s : 'webhooks',
        'show' => $s ? 'show:'.$s : 'show:mugs',
    ];
}

/** The shop a room belongs to, from its name. Null for none. */
function shop_of_room(?string $room): ?int
{
    $parts = explode(':', (string) $room);
    [$kind, $first] = [$parts[0], $parts[1] ?? null];
    if ('order' === $kind) {
        return intdiv((int) $first, 10000);
    }
    if ('chat' === $kind) {
        return shop_of($first);
    }
    if (!in_array($kind, ['shop', 'meeting', 'product', 'webhooks', 'show'], true)) {
        return null;
    }

    return null === $first || 'daily' === $first || 'mugs' === $first ? 0 : (ctype_digit($first) ? (int) $first : null);
}

$realtime = Realtime::fromEnvironment(array_filter([
    // What others see of a user: in presence, calls and the directory.
    'profile' => fn ($user) => ($p = person($user)) ? ['name' => $p['name'], 'avatar' => $p['avatar']] : null,
    // WordPlus Cloud unless these say otherwise.
    'server' => getenv('WORDPLUS_REALTIME_SERVER') ?: null,
    'api' => getenv('WORDPLUS_REALTIME_API') ?: null,
]));

// #region demo: order
// The room of an order: its customer and the shop staff. The last 20 updates kept, which the page shows as its timeline.
$realtime->room('order:{id}', function ($user, array $p) {
    $order = order_of($p['id']);

    return null !== $order && ($user === $order['customer'] || (is_staff($user) && same_shop($user, $order['customer'])));
}, ['history' => 20]);
// #endregion

// #region demo: chat
// The chat of Ann and Ben: those two only. They send messages, typing, reactions and read ticks straight to the room. The
// messages and reactions reach this server by webhook, and it keeps the conversation (keep_chat, GET /chat/messages).
// The room keeps its last events for 5 minutes, for a page that reconnects.
$realtime->room('chat:{a}:{b}', fn ($user, array $p) => in_array((string) $user, [$p['a'], $p['b']], true) && same_shop($p['a'], $p['b']), [
    'history' => 50,
    'emit' => ['message', 'typing', 'reaction', 'read'],
    'webhook' => ['message', 'reaction'],
]);
// #endregion

// #region demo: presence
// Who is in the shop: its people, with their name and role.
$member = fn ($user, int $s) => ($p = person($user)) && $p['shop'] === $s ? ['info' => ['name' => $p['name'], 'role' => $p['role']]] : false;
$realtime->room('shop', fn ($user) => $member($user, 0), ['presence' => true]);
$realtime->room('shop:{s}', fn ($user, array $p) => $member($user, (int) $p['s']), ['presence' => true]);
// #endregion

// #region demo: product
// The product page: anyone, signed in or not, and a live count of who is looking.
$realtime->room('product:{id}', null, ['public' => true, 'count' => true]);
// #endregion

// #region demo: webhooks
// The webhooks this server got, sent on to the shop people as they come (below), the last 20 kept.
$realtime->room('webhooks', fn ($user) => 0 === (person($user)['shop'] ?? null), ['history' => 20]);
$realtime->room('webhooks:{s}', fn ($user, array $p) => (int) $p['s'] === (person($user)['shop'] ?? null), ['history' => 20]);
// #endregion

// #region demo: members
// Calls between two people, support:34:42: the two it names, one of them staff. Either of them may bring more staff in,
// and the call goes on as a group call: the one adding calls the staff member about it, who takes the call from them.
$realtime->calls('support:{a}:{b}', function ($user, $with, array $p) {
    $pair = [(int) $p['a'], (int) $p['b']];
    if (!same_shop($user, $with, ...$pair)) {
        return false;
    }

    if (in_array($user, $pair, true) && in_array($with, $pair, true)) {
        return is_staff($user) || is_staff($with);
    }

    return (in_array($user, $pair, true) && is_staff($with)) || (is_staff($user) && in_array($with, $pair, true));
}, [
    // Whom the call screen offers to add: the shop staff not in the call.
    'people' => fn ($user, string $call) => array_values(array_map(fn (array $p) => $p['id'], array_filter(
        people_of(shop_of($user)),
        fn (array $p) => $p['staff'] && !in_array($p['id'], array_map('intval', array_slice(explode(':', $call), 1)), true),
    ))),
]);
// #endregion

// #region demo: meeting
// The daily meeting: a group call for everyone in the shop.
$realtime->groupCalls('meeting:{id}', fn ($user, array $p) => (person($user)['shop'] ?? null) === ('daily' === $p['id'] ? 0 : (int) $p['id']));
// #endregion

// #region demo: show
// The shop live show, show:mugs: Ann presents it, and everyone else watches and writes in its chat, signed in or not.
$realtime->streams('show:{id}', function ($user, array $p) {
    $s = shop_of_room('show:'.$p['id']);
    if (null === $s || !isset(shops()[(string) $s])) {
        return false;
    }

    return $s === (person($user)['shop'] ?? null) && 'Ann' === person($user)['name'] ? 'host' : 'viewer';
}, ['reactions' => true]);
// #endregion

// #region demo: webhooks
// What WordPlus Cloud tells this server by webhook (set https://<your server>/api/realtime/webhook on your app page in
// WordPlus Cloud): a message or reaction from a page, someone coming online, the live show going live … each sent on to
// its shop webhooks room. A live stream event names its stream (show:mugs) where the others name a room.
$realtime->on('*', function (array $event) use ($realtime) {
    $room = $event['room'] ?? $event['stream'] ?? null;
    $s = null !== $room ? shop_of_room($room) : shop_of($event['user'] ?? 0);
    if (null === $s || !isset(shops()[(string) $s]) || str_starts_with((string) $room, 'webhooks')) {
        return;
    }
    // #region demo: chat
    if ('emit' === $event['type'] && rooms_of($s)['chat'] === $room) {
        shops(function (array &$shops) use ($s, $event) {
            keep_chat($shops[(string) $s]['chat'], $event);
        });
    }
    // #endregion
    $type = 'emit' === $event['type'] ? $event['event'] : $event['type'];
    $realtime->to(rooms_of($s)['webhooks'])->emit('webhook', ['type' => $type, 'room' => $room, 'user' => $event['user'] ?? $event['from'] ?? null, 'at' => $event['at'] ?? null]);
});
// #endregion
