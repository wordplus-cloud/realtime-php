<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Laravel;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use WordPlus\Realtime\Realtime;

/**
 * WordPlus Cloud's realtime SDK in Laravel, found by itself: the `Realtime` singleton from config/wordplus-realtime.php,
 * the browser's routes (POST /wordplus/realtime/auth, /calls, /keys) for the session's user and the realtime server's
 * webhook (/wordplus/realtime/webhook), the `wordplus` broadcasting driver, toOthers() for a page's X-Realtime-Tab, and
 * `@wordplusRealtime`, which puts the browser's settings in a page for connect( window.wordplusRealtimeConfig ).
 *
 * A user's profile, for presence, calls and the directory, is their `name` (and `avatar` or Jetstream's
 * `profile_photo_url`) by default; profileUsing() says otherwise.
 */
final class RealtimeServiceProvider extends ServiceProvider
{
    /** @var (callable(int|string): (array<string, mixed>|null))|null */
    private static $profile = null;

    /** @var (callable(int|string): bool)|null */
    private static $hidden = null;

    /** How the app's users look to each other: `fn ( $id ) => [ 'name' => …, 'avatar' => … ]`, or null for nobody. */
    public static function profileUsing(?callable $profile): void
    {
        self::$profile = $profile;
    }

    /** Who is never shown online, such as a user who chose to hide it: `fn ( $id ) => (bool) …`. Nobody by default. */
    public static function hiddenUsing(?callable $hidden): void
    {
        self::$hidden = $hidden;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/wordplus-realtime.php', 'wordplus-realtime');

        // The broadcasting connection, where config/broadcasting.php has none: php artisan install:broadcasting
        // --without-reverb --without-node writes that file without it, and BROADCAST_CONNECTION=wordplus is then enough.
        if (! $this->app['config']->has('broadcasting.connections.wordplus')) {
            $this->app['config']->set('broadcasting.connections.wordplus', ['driver' => 'wordplus']);
        }

        $this->app->singleton(Realtime::class, function (Application $app) {
            $config = $app['config']['wordplus-realtime'];
            $store = $config['generations'] ?? null;

            $realtime = new Realtime(array_filter([
                'site' => (string) ($config['site'] ?? ''),
                'secret' => (string) ($config['secret'] ?? ''),
                'local_key' => (string) ($config['local_key'] ?? ''),
                'base' => '/'.trim((string) ($config['routes']['prefix'] ?? 'wordplus/realtime'), '/'),
                'routes' => self::ownRoutes((array) ($config['routes'] ?? [])),
                'files' => false !== ($config['files'] ?? true),
                'worker' => $config['worker'] ?? null,
                'rooms' => $config['rooms'] ?? null,
                'seal' => (bool) ($config['seal'] ?? true),
                'server' => $config['server'] ?? null,
                'api' => $config['api'] ?? null,
                'generations' => null === $store || '' === $store ? null : new CacheGenerations($app['cache']->store((string) $store)),
                'profile' => static fn ($id) => (self::$profile ?? self::defaultProfile(...))($id),
                'hidden' => static fn ($id) => null !== self::$hidden && (bool) (self::$hidden)($id),
            ], static fn ($value) => null !== $value));

            // Rooms no Realtime::room() owns are routes/channels.php's, as the broadcaster has them: who may join, and
            // what each room is (a channel's third argument).
            $broadcaster = static function () use ($app, $config): ?WordPlusBroadcaster {
                try {
                    $broadcaster = $app->make(BroadcastManager::class)->connection($config['broadcasting'] ?? 'wordplus');
                } catch (\InvalidArgumentException) {
                    return null;
                }

                return $broadcaster instanceof WordPlusBroadcaster ? $broadcaster : null;
            };
            $realtime->fallback(
                static fn ($user, string $room) => $broadcaster()?->allows($app->make('request'), $room),
                static fn (string $room) => $broadcaster()?->optionsFor($room),
            );

            return $realtime;
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../../config/wordplus-realtime.php' => $this->app->configPath('wordplus-realtime.php')], 'wordplus-realtime');

        $this->callAfterResolving(BroadcastManager::class, static function (BroadcastManager $manager): void {
            $manager->extend('wordplus', static fn (Application $app) => new WordPlusBroadcaster(static fn () => $app->make(Realtime::class)));
        });

        // toOthers() skips the tab a page names in X-Realtime-Tab, as it does Echo's X-Socket-ID.
        if ($this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);

            if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
                $kernel->pushMiddleware(RealtimeTab::class);
            }
        }

        $routes = $this->app['config']['wordplus-realtime.routes'] ?? [];

        if ($routes['enabled'] ?? true) {
            $prefix = trim((string) ($routes['prefix'] ?? 'wordplus/realtime'), '/');
            $own = self::ownRoutes((array) $routes);
            $middleware = $routes['middleware'] ?? ['web'];

            if ([] === array_intersect_key($own, array_flip(['auth', 'calls', 'keys']))) {
                Route::middleware($middleware)
                    ->prefix($prefix)
                    ->post('{action}', RealtimeController::class)
                    ->where('action', 'auth|calls|keys')
                    ->name('wordplus-realtime');
            } else {
                // A route at an address of its own (routes.auth, .calls or .keys), the others under the prefix.
                foreach (['auth', 'calls', 'keys'] as $action) {
                    Route::middleware($middleware)
                        ->post($own[$action] ?? $prefix.'/'.$action, RealtimeController::class)
                        ->defaults('action', $action)
                        ->name('wordplus-realtime.'.$action);
                }
            }

            // The realtime server's webhook signs its requests itself: no session, no CSRF token.
            Route::post($own['webhook'] ?? $prefix.'/webhook', [RealtimeController::class, 'webhook'])
                ->name('wordplus-realtime.webhook');

            // The browser files the package carries, the same for everyone: no session either.
            if (false !== ($this->app['config']['wordplus-realtime.files'] ?? true)) {
                Route::get($prefix.'/files/{name}', [RealtimeController::class, 'file'])
                    ->where('name', '[A-Za-z0-9._-]+')
                    ->name('wordplus-realtime.file');
                Route::get($prefix.'/files/{version}/{name}', [RealtimeController::class, 'file'])
                    ->where(['version' => '[A-Za-z0-9]+', 'name' => '[A-Za-z0-9._-]+'])
                    ->name('wordplus-realtime.files');
            }
        }

        Blade::directive('wordplusRealtime', static fn () => '<?php echo \\'.self::class.'::script(); ?>');
    }

    /**
     * The routes config/wordplus-realtime.php puts at addresses of their own (`routes.calls` => '/check-call'), each as a
     * path from the site's root.
     *
     * @param array<string, mixed> $routes
     *
     * @return array<string, string>
     */
    private static function ownRoutes(array $routes): array
    {
        $own = [];

        foreach (Realtime::ROUTES as $name) {
            if (is_string($routes[$name] ?? null) && '' !== trim($routes[$name], '/')) {
                $own[$name] = '/'.trim($routes[$name], '/');
            }
        }

        return $own;
    }

    /** The browser's settings for the page's user, as a script: window.wordplusRealtimeConfig. */
    public static function script(): string
    {
        $user = Auth::user()?->getAuthIdentifier();
        $config = app(Realtime::class)->config(is_int($user) || is_string($user) ? $user : null, ['X-CSRF-TOKEN' => csrf_token()]);

        return '<script>window.wordplusRealtimeConfig = '.json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR).';</script>';
    }

    /** @return array<string, mixed>|null */
    private static function defaultProfile(int|string $id): ?array
    {
        $current = Auth::user();
        $user = null !== $current && (string) $current->getAuthIdentifier() === (string) $id ? $current : Auth::getProvider()?->retrieveById($id);

        if (null === $user) {
            return null;
        }

        $name = $user->name ?? null;

        if (!is_string($name) || '' === $name) {
            return null;
        }

        $avatar = $user->avatar ?? $user->profile_photo_url ?? null;

        return is_string($avatar) && '' !== $avatar ? ['name' => $name, 'avatar' => $avatar] : ['name' => $name];
    }
}
