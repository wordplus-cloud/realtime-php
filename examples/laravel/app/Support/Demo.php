<?php

namespace App\Support;

use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The demo frame around the API demo (WORDPLUS_DEMO=1), as the demo sites run it (laravel.docs.wordplus.cloud and
 * the others): a shop for each visitor, for 24 hours, signed in as Ann. Windows that are Ben or Cara (/as/<token>, the
 * token then sent as a bearer, App\Http\Middleware\DemoWindow, so each window of one browser is its own person), and
 * what the page draws around the shop: the stacks, the guide, the source, "Try this". The example itself is the same
 * with or without it.
 */
final class Demo
{
    private const DAY = 24 * 3600;

    /** New shops an address opens in an hour. Past them it gets the newest of them again. */
    private const SHOPS_AN_HOUR = 6;

    /** The demos, in the order the top bar lists them, and where the source of each is. */
    private const STACKS = [
        ['wordpress', 'WordPress', 'realtime-wordpress'],
        ['nextjs', 'Next.js', 'realtime-js'],
        ['laravel', 'Laravel', 'realtime-php'],
        ['django', 'Django', 'realtime-python'],
        ['fastapi', 'FastAPI', 'realtime-python'],
        ['express', 'Express', 'realtime-js'],
        ['php', 'PHP', 'realtime-php'],
        ['flask', 'Flask', 'realtime-python'],
        ['mobile', 'Mobile apps', 'realtime-js'],
    ];

    public static function on(): bool
    {
        return '1' === env('WORDPLUS_DEMO');
    }

    /** The token of a user: their id and its signature. */
    public static function sign(int $id): string
    {
        return $id.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', (string) $id, (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    /** The user a token names, if its signature is right. */
    public static function userOf(?string $token): ?User
    {
        $id = (int) explode('.', (string) $token)[0];

        return hash_equals(self::sign($id), (string) $token) ? User::find($id) : null;
    }

    /**
     * A new visitor: a shop of their own, and they are its Ann. Once their address has opened its few this hour, the
     * newest of them again, so nobody is ever turned away and nobody can open thousands.
     */
    public static function start(Request $request): void
    {
        // The visitor address as the proxy in front saw it: the last of X-Forwarded-For, the one it added. The ones
        // before it are whatever the visitor sent, and $request->ip() is that of the proxy itself.
        $forwarded = explode(',', (string) $request->header('X-Forwarded-For'));
        $address = trim(end($forwarded)) ?: $request->server('REMOTE_ADDR');

        // Shops end after a day.
        $ended = User::where('id', '>=', 10000000)->where('created_at', '<', now()->subSeconds(self::DAY))->pluck('id')->map(fn ($id) => Shop::of($id))->unique();
        foreach ($ended as $s) {
            DB::transaction(function () use ($s) {
                Order::whereBetween('id', [$s * 10000, $s * 10000 + 9999])->delete();
                Message::whereBetween('from_id', [$s * 100, $s * 100 + 99])->delete();
                User::whereBetween('id', [$s * 100, $s * 100 + 99])->delete();
            });
        }

        $key = 'demo-shops:'.$address;
        $recent = array_values(array_filter((array) Cache::get($key, []), fn ($o) => is_array($o) && time() - $o['at'] < 3600 && Shop::exists($o['s'])));
        if (count($recent) >= self::SHOPS_AN_HOUR) {
            $s = $recent[count($recent) - 1]['s'];
        } else {
            do {
                $s = random_int(100000, 999999);
            } while (Shop::exists($s));
            Shop::open($s);
            $recent[] = ['s' => $s, 'at' => time()];
        }
        Cache::put($key, $recent, 3600);

        Auth::login(User::findOrFail($s * 100 + 34));
        $request->session()->regenerate();
    }

    /** What a person page draws around the shop. */
    public static function frame(User $me): array
    {
        $sites = env('WORDPLUS_DEMO_SITES') ?: 'https://{case}.docs.wordplus.cloud';
        [, $name, $repository] = collect(self::STACKS)->firstWhere(0, 'laravel');

        return [
            'stack' => 'laravel',
            'name' => $name,
            'guide' => 'https://docs.wordplus.cloud/laravel/getting-started/',
            'source' => 'https://github.com/wordplus-cloud/'.$repository.'/tree/main/examples/laravel',
            'sites' => array_map(fn (array $s) => ['stack' => $s[0], 'name' => $s[1], 'url' => str_replace('{case}', $s[0], $sites)], self::STACKS),
            // This window person, which the page sends with every request. And the others, each a window of their own.
            'token' => self::sign($me->id),
            'open' => Shop::people($me->shop)->reject(fn (User $p) => $p->id === $me->id)
                ->map(fn (User $p) => ['id' => $p->id, 'name' => $p->name, 'url' => '/as/'.self::sign($p->id)])->values(),
            'ends' => ($me->created_at->getTimestamp() + self::DAY) * 1000,
            'limits' => self::limits(),
            'code' => self::code(),
        ];
    }

    /**
     * How long a call and a meeting may last, in seconds, which the page keeps to: 10 and 15 minutes, as the demo runs
     * on WordPlus Cloud for anyone. WORDPLUS_DEMO_LIMITS ("call,meeting") shortens them, for the demo check.
     */
    public static function limits(): array
    {
        [$call, $meeting] = array_map('intval', explode(',', env('WORDPLUS_DEMO_LIMITS') ?: '600,900'));

        return ['call' => $call, 'meeting' => $meeting];
    }

    /**
     * The code under each card, "See the code": the parts of this example marked `#region demo: <card>`, which the build
     * of the demo sites cuts from its source into WORDPLUS_DEMO_CODE, by stack.
     */
    public static function code(): ?array
    {
        $path = env('WORDPLUS_DEMO_CODE');

        return $path ? (json_decode((string) file_get_contents($path), true)['laravel'] ?? null) : null;
    }
}
