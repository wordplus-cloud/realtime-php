<?php

namespace App\Models;

use App\Support\Shop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The shop people: Ann (support), Ben (a customer) and Cara (the warehouse), and on a demo the shop of each visitor:
 * (App\Support\Shop). Their `name` and `avatar` are what others see of them, in presence, calls and the directory:
 * the profile of wordplus/realtime-php reads both.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'staff' => 'boolean',
        ];
    }

    /** The shop they are one of the people of, by their id. */
    protected function shop(): Attribute
    {
        return Attribute::get(fn () => Shop::of($this->id));
    }

    /** As the page shows a person. */
    public function toShop(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'role' => $this->role, 'staff' => $this->staff, 'avatar' => $this->avatar, 'shop' => $this->shop];
    }
}
