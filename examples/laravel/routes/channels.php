<?php

use App\Models\Order;
use App\Models\User;
use App\Support\Shop;
use Illuminate\Support\Facades\Broadcast;

// Each channel is a room of the same name, and its third argument says what the room is. Every rule checks which
// shop the user is in (App\Support\Shop), so the people of one shop never reach another.

// #region demo: order
// The room of an order, PrivateChannel('orders.1042'): its customer and the shop staff. The last 20 updates kept, which
// the page shows as its timeline.
Broadcast::channel('orders.{order}', fn (User $user, Order $order) => $user->id === $order->customer_id || ($user->staff && $user->shop === Shop::of($order->customer_id)), ['history' => 20]);
// #endregion

// #region demo: chat
// The chat of Ann and Ben, chat.34.42: those two only. They send messages, typing, reactions and read ticks straight to the
// room. The messages and reactions reach this app by webhook, and it keeps the conversation (Message::keep, GET
// /chat/messages). The room keeps its last events for 5 minutes, for a page that reconnects.
Broadcast::channel('chat.{a}.{b}', fn (User $user, string $a, string $b) => in_array((string) $user->id, [$a, $b], true) && Shop::of($a) === Shop::of($b), [
    'history' => 50,
    'emit' => ['message', 'typing', 'reaction', 'read'],
    'webhook' => ['message', 'reaction'],
]);
// #endregion

// #region demo: presence
// Who is in the shop: its people, with their name and role. A callback that answers an array makes the room a presence
// room, as the Laravel presence channels have it.
Broadcast::channel('shop', fn (User $user) => 0 === $user->shop ? ['name' => $user->name, 'role' => $user->role] : false);
Broadcast::channel('shop.{s}', fn (User $user, string $s) => (int) $s === $user->shop ? ['name' => $user->name, 'role' => $user->role] : false);
// #endregion

// #region demo: product
// The product page: anyone, signed in or not, and a live count of who is looking.
Broadcast::channel('product.{id}', fn () => true, ['public' => true, 'count' => true]);
// #endregion

// #region demo: webhooks
// The webhooks this app got, sent on to the shop people as they come (AppServiceProvider), the last 20 kept.
Broadcast::channel('webhooks', fn (User $user) => 0 === $user->shop, ['history' => 20]);
Broadcast::channel('webhooks.{s}', fn (User $user, string $s) => (int) $s === $user->shop, ['history' => 20]);
// #endregion
