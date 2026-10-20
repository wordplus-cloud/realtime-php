<?php

namespace App\Http\Controllers;

use App\Events\OrderUpdated;
use App\Models\Message;
use App\Models\Order;
use App\Support\Demo;
use App\Support\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use WordPlus\Realtime\Laravel\Facades\Realtime;

class ShopController extends Controller
{
    /** The shop page: the user realtime settings come from @wordplusRealtime, the shop data from here. */
    public function show(Request $request): View
    {
        $me = $request->user();
        $s = $me->shop;
        $order = Order::findOrFail($s * 10000 + 1042);

        return view('shop', [
            'me' => $me,
            'shop' => [
                'me' => $me->toShop(),
                'people' => Shop::people($s)->map->toShop(),
                'order' => ['id' => $order->id, 'number' => 1042, 'customer' => $order->customer_id, 'item' => $order->item, 'status' => $order->status],
                'chat' => ['between' => [$s * 100 + 34, $s * 100 + 42]],
                'product' => ['name' => $order->item, 'picture' => '/products/mugs.webp'],
                'routes' => ['status' => route('orders.status', $order), 'wire' => route('orders.wire', $order), 'messages' => route('chat.messages')],
                'rooms' => Shop::rooms($s),
                'events' => ['order' => 'updated', 'chat' => 'message'],
                'headers' => ['X-CSRF-TOKEN' => csrf_token()],
            ] + (Demo::on() ? ['demo' => Demo::frame($me)] : []),
        ]);
    }

    // #region demo: order
    /**
     * The staff move an order on: saved, then broadcast to its room but the tab that asked, and a notice to its customer
     * alone.
     */
    public function status(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->staff && $request->user()->shop === Shop::of($order->customer_id), 403);
        $order->update($request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]));

        broadcast(new OrderUpdated($order))->toOthers();
        Realtime::toUser($order->customer_id)->emit('notice', ['text' => 'Your order #1042 is '.$order->status.'.']);

        return response()->json(['ok' => true]);
    }
    // #endregion

    /**
     * What the cloud carries of the order update: its room and event as they are, its data sealed with the room
     * key, which WordPlus Cloud never holds. The page shows it beside the update as it opened it.
     */
    public function wire(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->shop === Shop::of($order->customer_id), 403);
        $room = 'orders.'.$order->id;
        $keys = Realtime::keys();

        return response()->json(['room' => $room, 'event' => 'updated', 'data' => $keys->sealJson(['id' => $order->id, 'status' => $order->status], $keys->roomLabel($room))]);
    }

    // #region demo: chat
    /**
     * The chat the app keeps (Message::keep), for one of its two: the newest 50 messages, or with `before` the 50 before
     * that one, oldest first. The chat on the page pages through them. The room fills in only what came since.
     */
    public function messages(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless(in_array($me->id % 100, [34, 42], true), 403);

        $s = $me->shop;
        $query = Message::whereBetween('from_id', [$s * 100, $s * 100 + 99]);
        if (null !== ($before = Message::byChatId((string) $request->query('before', '')))) {
            $query->where('id', '<', $before->id);
        }
        $page = $query->orderByDesc('id')->limit(51)->get();

        return response()->json(['messages' => $page->take(50)->reverse()->values()->map->toChat(), 'more' => $page->count() > 50]);
    }
    // #endregion
}
