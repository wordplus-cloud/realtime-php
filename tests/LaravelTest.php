<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use WordPlus\Realtime\Keys;
use WordPlus\Realtime\Laravel\Facades\Realtime as RealtimeFacade;
use WordPlus\Realtime\Laravel\RealtimeServiceProvider;
use WordPlus\Realtime\Realtime;

/**
 * The package in a Laravel app: its routes for the session's user, routes/channels.php deciding who joins which room
 * and what each room is through the `wordplus` broadcaster, broadcast() emitting sealed, the webhook, the page's
 * settings, and the facade.
 */
final class LaravelTest extends TestCase
{
    /** @var list<array{path: string, body: array<string, mixed>}> */
    public static array $sent = [];

    private const LOCAL = 'a3f1c2d4e5b697887766554433221100ffeeddccbbaa99887766554433221100';

    protected function getPackageProviders($app): array
    {
        return [RealtimeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('wordplus-realtime.site', 's1500000007');
        $app['config']->set('wordplus-realtime.secret', 'test-secret');
        $app['config']->set('wordplus-realtime.local_key', self::LOCAL);
        $app['config']->set('broadcasting.default', 'wordplus');
        $app['config']->set('broadcasting.connections.wordplus', ['driver' => 'wordplus']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$sent = [];

        // Nothing leaves the test: emits go to a recorder.
        $this->app->extend(Realtime::class, function (Realtime $realtime) {
            $property = new \ReflectionProperty(Realtime::class, 'options');
            $options = $property->getValue($realtime);
            $options['http'] = static function (string $method, string $url, array $headers, ?string $body) {
                self::$sent[] = ['path' => (string) parse_url($url, PHP_URL_PATH), 'body' => json_decode((string) $body, true)];

                return ['status' => 200, 'body' => '{"ok":true}'];
            };
            $property->setValue($realtime, $options);

            return $realtime;
        });

        Broadcast::channel('orders.{id}', static fn ($user, $id) => (int) $user->id === (int) $id, ['history' => 20]);
        Broadcast::channel('room.{id}', static fn ($user) => ['name' => $user->name], ['emit' => ['typing'], 'guards' => ['web']]);
        Broadcast::channel('shop', static fn () => false, ['public' => true, 'history' => 10]);
        RealtimeServiceProvider::profileUsing(null);
    }

    private function user(int $id, string $name): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => $name]);
    }

    /** @return array<string, mixed> */
    private static function claims(string $token): array
    {
        return json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    public function test_the_routes_answer_for_the_sessions_user_and_routes_channels_php_decides_who_joins_and_what_each_room_is()
    {
        $this->actingAs($this->user(42, 'Ann'));

        $grant = $this->postJson('/wordplus/realtime/auth', ['rooms' => ['orders.42', 'orders.43', 'room.7', 'shop', 'news']])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json();

        $this->assertSame(['orders.43' => 'forbidden', 'news' => 'no_rule'], $grant['denied']);
        $claims = self::claims($grant['token']);
        $this->assertSame('42', $claims['sub']);
        // A channel's options are its third argument; one whose callback answers an array is a presence room.
        $this->assertSame(['orders.42' => ['h' => [20, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]], 'room.7' => ['p' => 1, 'e' => ['typing']], 'shop' => ['h' => [10, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]]], $claims['r']);
        // The presence member's info, as the channel's callback gave it, sealed for the room.
        $this->assertSame(['name' => 'Ann'], json_decode((string) (new Keys(self::LOCAL))->open($claims['infos']['room.7']), true));
        $this->assertCount(2, $grant['keys']);

        // Their keys and identity, their name from the session's user.
        $keys = $this->postJson('/wordplus/realtime/keys', ['labels' => []])->assertOk()->json();
        $this->assertCount(4, $keys['keys']);
        $this->assertSame('Ann', (new Keys(self::LOCAL))->open(self::claims($keys['identity']['token'])['pd']['name']));

        // A room's key, to the user routes/channels.php lets in.
        $asked = $this->postJson('/wordplus/realtime/keys', ['labels' => ['ch:orders.42:1', 'ch:orders.43:1']])->assertOk()->json();
        $this->assertSame([(new Keys(self::LOCAL))->entry('ch:orders.42:1')], $asked['keys']);

        $this->postJson('/wordplus/realtime/calls', ['line' => true])->assertOk()->assertJsonStructure(['token', 'exp']);
        $this->postJson('/wordplus/realtime/other', [])->assertNotFound();
    }

    public function test_nobody_gets_a_public_room_but_no_other_and_no_keys()
    {
        $this->postJson('/wordplus/realtime/auth', ['rooms' => ['orders.1', 'room.1', 'shop']])
            ->assertOk()
            ->assertJsonPath('denied', ['orders.1' => 'forbidden', 'room.1' => 'forbidden']);
        $this->postJson('/wordplus/realtime/keys', ['labels' => []])->assertExactJson(['keys' => [], 'app' => 's1500000007']);
    }

    #[DefineEnvironment('ownRoutes')]
    public function test_a_route_at_an_address_of_its_own_answers_there_alone_and_is_named_to_the_page()
    {
        $this->actingAs($this->user(42, 'Ann'));

        $config = app(Realtime::class)->config(42);
        $this->assertSame(['/permit/rooms', '/check-call', '/wordplus/realtime/keys'], [$config['auth']['endpoint'], $config['calls']['endpoint'], $config['keys']['endpoint']]);

        $this->postJson('/permit/rooms', ['rooms' => ['shop']])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/check-call', ['line' => true])->assertOk()->assertJsonStructure(['token', 'exp']);
        $this->postJson('/wordplus/realtime/keys', ['labels' => []])->assertOk();
        $this->postJson('/wordplus/realtime/calls', ['line' => true])->assertNotFound();

        $body = (string) json_encode(['events' => []]);
        $timestamp = (string) time();
        $key = hash_hmac('sha256', 'wordplus-realtime webhook v1', 'test-secret', true);
        $headers = [
            'Content-Type' => 'application/json',
            'X-WordPlus-Site' => 's1500000007',
            'X-WordPlus-Timestamp' => $timestamp,
            'X-WordPlus-Signature' => hash_hmac('sha256', $timestamp."\nPOST\n/hooks/wordplus\n".hash('sha256', $body), $key),
        ];
        $this->call('POST', '/hooks/wordplus', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertNoContent();
    }

    public function test_artisan_runs_before_the_credentials_are_set()
    {
        config(['wordplus-realtime.site' => '', 'wordplus-realtime.secret' => '']);
        $this->app->forgetInstance(Realtime::class);
        Broadcast::purge('wordplus');

        // routes/channels.php, as every artisan command loads it: the broadcaster asks for the credentials only when used.
        Broadcast::channel('orders.{id}', static fn () => true);
        $this->assertInstanceOf(\WordPlus\Realtime\Laravel\WordPlusBroadcaster::class, Broadcast::connection('wordplus'));
        $this->artisan('route:list')->assertSuccessful();

        $this->expectExceptionMessage('WORDPLUS_SITE and WORDPLUS_SECRET');
        app(Realtime::class)->config(null);
    }

    public function test_the_broadcasting_connection_needs_no_entry_in_config_broadcasting_php()
    {
        // config/broadcasting.php as install:broadcasting --without-reverb --without-node writes it, with no wordplus.
        $connections = config('broadcasting.connections');
        unset($connections['wordplus']);
        config(['broadcasting.connections' => $connections]);

        (new RealtimeServiceProvider($this->app))->register();

        $this->assertSame(['driver' => 'wordplus'], config('broadcasting.connections.wordplus'));
    }

    protected function ownRoutes($app): void
    {
        $app['config']->set('wordplus-realtime.routes.auth', '/permit/rooms');
        $app['config']->set('wordplus-realtime.routes.calls', 'check-call');
        $app['config']->set('wordplus-realtime.routes.webhook', '/hooks/wordplus/');
    }

    public function test_a_realtime_room_wins_over_routes_channels_php()
    {
        RealtimeFacade::room('orders.{id}', static fn () => true, ['count' => true]);
        $this->actingAs($this->user(1, 'Bob'));

        $grant = $this->postJson('/wordplus/realtime/auth', ['rooms' => ['orders.99']])->assertJsonPath('denied', [])->json();
        $this->assertSame(['orders.99' => ['c' => 1]], self::claims($grant['token'])['r']);
    }

    public function test_broadcast_emits_to_the_events_rooms_sealed_keeps_their_history_and_skips_the_tab_that_made_it()
    {
        broadcast(new OrderPaid(42));

        $this->assertCount(1, self::$sent);
        $this->assertSame('/wordplus/realtime/emit/batch', self::$sent[0]['path']);
        [$sealed, $public] = self::$sent[0]['body']['events'];
        $this->assertSame(['orders.42'], $sealed['rooms']);
        $this->assertSame('order.paid', $sealed['event']);
        $this->assertSame(['orders.42' => [20, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]], $sealed['history']);
        $this->assertSame(['id' => 42, 'status' => 'paid'], json_decode((string) (new Keys(self::LOCAL))->open($sealed['data']), true));
        $this->assertSame(['rooms' => ['shop'], 'event' => 'order.paid', 'data' => ['id' => 42, 'status' => 'paid'], 'history' => ['shop' => [10, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]]], $public);

        $this->app['request']->headers->set('X-Socket-ID', 'tab-1');
        broadcast(new OrderPaid(43))->toOthers();
        $this->assertSame('tab-1', self::$sent[1]['body']['events'][0]['except']);
    }

    public function test_to_others_skips_the_tab_a_page_names_in_x_realtime_tab()
    {
        Route::post('/orders/{id}/paid', static function (string $id) {
            broadcast(new OrderPaid((int) $id))->toOthers();

            return ['ok' => true];
        });

        $this->postJson('/orders/42/paid', [], ['X-Realtime-Tab' => 'tab-7'])->assertOk();
        $this->assertSame('tab-7', self::$sent[0]['body']['events'][0]['except']);

        $this->postJson('/orders/42/paid')->assertOk();
        $this->assertArrayNotHasKey('except', self::$sent[1]['body']['events'][0]);
    }

    public function test_the_webhook_route_checks_the_signature_and_hands_the_events_to_their_handlers()
    {
        $seen = [];
        RealtimeFacade::on('member.joined', static function (array $e) use (&$seen) {
            $seen[] = [$e['room'], $e['user']];
        });
        $body = (string) json_encode(['events' => [['id' => 'a', 'at' => 1, 'type' => 'member.joined', 'room' => 'room.7', 'user' => '42']]]);
        $timestamp = (string) time();
        $key = hash_hmac('sha256', 'wordplus-realtime webhook v1', 'test-secret', true);
        $headers = [
            'Content-Type' => 'application/json',
            'X-WordPlus-Site' => 's1500000007',
            'X-WordPlus-Timestamp' => $timestamp,
            'X-WordPlus-Signature' => hash_hmac('sha256', $timestamp."\nPOST\n/wordplus/realtime/webhook\n".hash('sha256', $body), $key),
        ];

        $this->call('POST', '/wordplus/realtime/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertNoContent();
        $this->assertSame([['room.7', '42']], $seen);

        $this->call('POST', '/wordplus/realtime/webhook', [], [], [], $this->transformHeadersToServerVars(['X-WordPlus-Signature' => 'x'] + $headers), $body)->assertUnauthorized();
        $this->assertCount(1, $seen);
    }

    public function test_a_page_gets_its_settings_and_the_facade_emits()
    {
        $this->actingAs($this->user(42, 'Ann'));
        RealtimeServiceProvider::profileUsing(static fn ($id) => ['name' => 'Agent '.$id]);

        $html = Blade::render('@wordplusRealtime');
        $this->assertMatchesRegularExpression('/^<script>window\.wordplusRealtimeConfig = \{.*\};<\/script>$/', $html);
        $config = json_decode(substr($html, strlen('<script>window.wordplusRealtimeConfig = '), -strlen(';</script>')), true);
        $this->assertSame('s1500000007', $config['site']);
        $this->assertSame('/wordplus/realtime/auth', $config['auth']['endpoint']);
        $this->assertArrayHasKey('X-CSRF-TOKEN', $config['auth']['headers']);
        $this->assertMatchesRegularExpression('#^/wordplus/realtime/files/[0-9a-f]{12}/realtime-worker\.js$#', $config['worker']);
        $this->assertCount(4, $config['keys']['entries']);
        $this->assertSame('Agent 42', (new Keys(self::LOCAL))->open(self::claims($config['keys']['identity']['token'])['pd']['name']));

        $this->assertArrayNotHasKey('hid', self::claims($config['keys']['identity']['token']));

        RealtimeFacade::to('news')->emit('hello', ['a' => 1]);
        $this->assertSame(['rooms' => ['news'], 'event' => 'hello', 'data' => ['a' => 1]], end(self::$sent)['body']);
    }

    public function test_the_facade_names_every_method_an_app_calls_so_an_editor_and_a_static_check_know_them()
    {
        // What the package's own routes, the broadcaster and the queries call, never an app.
        $internal = ['authorize', 'publicGrants', 'authorizeCall', 'callKey', 'keysAnswer', 'file', 'respondFile', 'address',
            'routeOf', 'handle', 'webhook', 'respond', 'answerRequest', 'emitEvent', 'request', 'sealedFor', 'opened'];
        $doc = (string) (new \ReflectionClass(RealtimeFacade::class))->getDocComment();
        preg_match_all('/@method static \S+ (\w+)\(/', $doc, $named);

        // An instance's methods: the facade hands its calls to the app's Realtime, never to the class.
        $public = (new \ReflectionClass(\WordPlus\Realtime\Realtime::class))->getMethods(\ReflectionMethod::IS_PUBLIC);
        $methods = array_map(static fn (\ReflectionMethod $m) => $m->getName(), array_filter($public, static fn (\ReflectionMethod $m) => ! $m->isStatic()));
        $forApps = array_values(array_diff(array_filter($methods, static fn ($m) => ! str_starts_with($m, '__')), $internal));

        $this->assertSame([], array_values(array_diff($forApps, $named[1])), 'methods an app calls that the facade does not name');
        $this->assertSame([], array_values(array_diff($named[1], $methods)), 'names in the facade that Realtime does not have');
    }

    public function test_the_browser_files_come_from_the_package_routes_without_a_session()
    {
        $at = app(Realtime::class)->fileAt('realtime-worker.js');

        $res = $this->get($at);
        $res->assertOk();
        $this->assertSame(file_get_contents(Realtime::FILES_DIR.'/realtime-worker.js'), $res->getContent());
        $this->assertSame('text/javascript; charset=utf-8', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
        $this->assertSame([], $res->headers->getCookies());

        $this->get('/wordplus/realtime/files/realtime.js')->assertOk()->assertHeader('ETag');
        $this->get('/wordplus/realtime/files/'.explode('/', $at)[4].'/composer.json')->assertNotFound();
        $this->get('/wordplus/realtime/files/../composer.json')->assertNotFound();
    }

    public function test_the_config_file_sets_what_new_realtime_takes_the_tokens_lifetimes_the_headers_and_the_rooms_worker()
    {
        // A Laravel app could set these only by binding the singleton again.
        $this->app['config']->set('wordplus-realtime.token_ttl', 120);
        $this->app['config']->set('wordplus-realtime.call_token_ttl', 90);
        $this->app['config']->set('wordplus-realtime.headers', ['X-Team' => '4']);
        $this->app['config']->set('wordplus-realtime.rooms_worker', '/e2ee.worker.js');
        $this->app->forgetInstance(Realtime::class);
        RealtimeServiceProvider::profileUsing(static fn ($id) => ['name' => 'Agent '.$id]);

        $identity = self::claims(RealtimeFacade::identity(42)['token']);
        $this->assertSame(120, $identity['exp'] - $identity['iat']);

        $config = RealtimeFacade::config(null);
        $this->assertSame('/e2ee.worker.js', $config['roomsWorker']);
        $this->assertSame('4', ((array) $config['auth']['headers'])['X-Team']);
    }

    public function test_has_keys_using_cuts_a_user_off_from_the_keys()
    {
        RealtimeServiceProvider::profileUsing(static fn ($id) => ['name' => 'Agent '.$id]);
        RealtimeServiceProvider::hasKeysUsing(static fn ($id) => 42 !== (int) $id);

        try {
            $this->assertSame(['keys' => [], 'app' => 's1500000007'], RealtimeFacade::keysAnswer([], 42));
            $this->assertNotSame([], RealtimeFacade::keysAnswer([], 43)['keys']);
        } finally {
            RealtimeServiceProvider::hasKeysUsing(null);
        }
    }

    public function test_a_user_who_hides_their_status_is_never_shown_online()
    {
        RealtimeServiceProvider::profileUsing(static fn ($id) => ['name' => 'Agent '.$id]);
        RealtimeServiceProvider::hiddenUsing(static fn ($id) => 42 === (int) $id);

        try {
            $this->assertTrue(self::claims(RealtimeFacade::identity(42)['token'])['hid'] ?? false);
            $this->assertArrayNotHasKey('hid', self::claims(RealtimeFacade::identity(43)['token']));
        } finally {
            RealtimeServiceProvider::hiddenUsing(null);
        }
    }
}

final class OrderPaid implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public int $id) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->id), new Channel('shop')];
    }

    public function broadcastAs(): string
    {
        return 'order.paid';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->id, 'status' => 'paid'];
    }
}
