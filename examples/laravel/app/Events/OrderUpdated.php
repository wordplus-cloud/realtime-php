<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// #region demo: order
// An order moved on, to its room, orders.1042, as `updated`: sealed with the room key, and kept in its history as
// routes/channels.php says. ShouldBroadcastNow sends it during the request. ShouldBroadcast would send it from your
// queue.
class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->order->id)];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->order->id, 'status' => $this->order->status];
    }
}
// #endregion
