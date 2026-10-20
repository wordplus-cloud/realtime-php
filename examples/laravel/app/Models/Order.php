<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const STATUSES = ['placed', 'packed', 'shipped', 'delivered'];

    protected $fillable = ['status'];

    protected function casts(): array
    {
        return ['customer_id' => 'integer'];
    }
}
