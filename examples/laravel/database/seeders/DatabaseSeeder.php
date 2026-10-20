<?php

namespace Database\Seeders;

use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

// The shop people, the order of Ben and the start of the chat of Ann and Ben. Their ids are those of the docs (34, 42, 43): calls and
// the directory take integer ids.
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            [34, 'Ann', 'Support', true],
            [42, 'Ben', 'Customer', false],
            [43, 'Cara', 'Warehouse', true],
        ] as [$id, $name, $role, $staff]) {
            User::forceCreate([
                'id' => $id,
                'name' => $name,
                'role' => $role,
                'staff' => $staff,
                'avatar' => '/avatars/'.strtolower($name).'.svg',
                'email' => strtolower($name).'@shop.example',
                'password' => Str::random(32),
            ]);
        }

        Order::forceCreate(['id' => 1042, 'customer_id' => 42, 'item' => 'Two ceramic mugs', 'status' => 'placed']);

        // What Ann and Ben say next comes by webhook (Message::keep).
        Message::forceCreate(['from_id' => 42, 'text' => 'Hi! Has my order left yet?', 'created_at' => now()->subMinutes(2)]);
        Message::forceCreate(['from_id' => 34, 'text' => 'Hi Ben, it is being packed now. I will let you know.', 'created_at' => now()->subMinute()]);
    }
}
