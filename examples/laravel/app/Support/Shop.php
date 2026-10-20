<?php

namespace App\Support;

use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shops, by number. Shop 0 is the one DatabaseSeeder makes: Ann 34, Ben 42, Cara 43 and order 1042. A demo
 * (WORDPLUS_DEMO=1, App\Support\Demo) opens one for each visitor, numbered s, in the same tables: its people
 * s × 100 + 34, + 42 and + 43, its order s × 10000 + 1042. Every rule finds the shop of a user by arithmetic, so the people of one
 * shop never reach another.
 */
final class Shop
{
    /** The shop of a user, from their id. */
    public static function of(int|string|null $id): int
    {
        return intdiv((int) $id, 100);
    }

    /** The people of a shop, in its order. */
    public static function people(int $s): Collection
    {
        return User::whereBetween('id', [$s * 100, $s * 100 + 99])->orderBy('id')->get();
    }

    public static function exists(int $s): bool
    {
        return User::whereKey($s * 100 + 34)->exists();
    }

    /** A new shop, numbered `$s`: its people, its order, placed, and the first two messages of the chat. */
    public static function open(int $s): void
    {
        DB::transaction(function () use ($s) {
            foreach ([[34, 'Ann', 'Support', true], [42, 'Ben', 'Customer', false], [43, 'Cara', 'Warehouse', true]] as [$id, $name, $role, $staff]) {
                User::forceCreate([
                    'id' => $s * 100 + $id,
                    'name' => $name,
                    'role' => $role,
                    'staff' => $staff,
                    'avatar' => '/avatars/'.strtolower($name).'.svg',
                    'email' => strtolower($name).'+'.$s.'@shop.example',
                    'password' => Str::random(32),
                ]);
            }
            Order::forceCreate(['id' => $s * 10000 + 1042, 'customer_id' => $s * 100 + 42, 'item' => 'Two ceramic mugs', 'status' => 'placed']);
            // What Ann and Ben say next comes by webhook (Message::keep).
            Message::forceCreate(['from_id' => $s * 100 + 42, 'text' => 'Hi! Has my order left yet?', 'created_at' => now()->subMinutes(2)]);
            Message::forceCreate(['from_id' => $s * 100 + 34, 'text' => 'Hi Ben, it is being packed now. I will let you know.', 'created_at' => now()->subMinute()]);
        });
    }

    /** The rooms of a shop, by name: those of shop 0 as the guides name them, those of a demo shop with its number. */
    public static function rooms(int $s): array
    {
        return [
            'order' => 'orders.'.($s * 10000 + 1042),
            'chat' => 'chat.'.($s * 100 + 34).'.'.($s * 100 + 42),
            'shop' => $s ? 'shop.'.$s : 'shop',
            'meeting' => $s ? 'meeting:'.$s : 'meeting:daily',
            'product' => $s ? 'product.'.$s : 'product.mugs',
            'webhooks' => $s ? 'webhooks.'.$s : 'webhooks',
            'show' => $s ? 'show:'.$s : 'show:mugs',
        ];
    }

    /** The shop a room belongs to, from its name. Null for none. */
    public static function ofRoom(?string $room): ?int
    {
        $parts = preg_split('/[.:]/', (string) $room);
        [$kind, $first] = [$parts[0], $parts[1] ?? null];
        if ('orders' === $kind) {
            return intdiv((int) $first, 10000);
        }
        if ('chat' === $kind) {
            return self::of($first);
        }
        if (!in_array($kind, ['shop', 'meeting', 'product', 'webhooks', 'show'], true)) {
            return null;
        }

        return null === $first || 'daily' === $first || 'mugs' === $first ? 0 : (ctype_digit($first) ? (int) $first : null);
    }
}
