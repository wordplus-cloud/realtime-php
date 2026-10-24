<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/**
 * WordPlus Cloud's realtime SDK for an app's own server in PHP, outside WordPress: the rooms the app owns, declared
 * once by pattern with what they are; what the app's server sends them and its users; who is online; and the routes its
 * browsers ask. @wordplus/realtime's server part, answering the browser exactly as it does.
 *
 *     $realtime = Realtime::fromEnvironment( [ 'profile' => fn ( $user ) => [ 'name' => name_of( $user ) ] ] );
 *     $realtime->room( 'order:{id}', fn ( $user, array $p ) => owns_order( $user, $p['id'] ), [ 'history' => 20 ] );
 *     $realtime->room( 'chat:{a}:{b}', fn ( $user, array $p ) => in_array( (string) $user, [ $p['a'], $p['b'] ], true ),
 *         [ 'presence' => true, 'history' => 500, 'emit' => [ 'message', 'typing' ] ] );
 *
 *     // POST /api/realtime/auth, /calls, /keys and /webhook:
 *     $realtime->respond( basename( $_SERVER['REQUEST_URI'] ), current_user_id() );
 *
 *     $realtime->to( 'order:42' )->emit( 'updated', [ 'status' => 'paid' ] );
 *     $realtime->toUser( 42 )->emit( 'notice', [ 'text' => 'Your order shipped' ] );
 *
 * A user is an id the app gives: an integer or a string, or null for nobody. In calls, an id of digits is a number and
 * any other id of up to 64 characters, with no space, is taken as it is.
 */
final class Realtime
{
    public const VERSION = '1.0.2';

    /** The largest id of digits a page reads as the same number (JavaScript's Number.MAX_SAFE_INTEGER). */
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public const SERVER = 'https://ws.wordplus.cloud';

    public const API_SERVER = 'https://rest.wordplus.cloud';

    public const PATH = '/websocket';

    /** The app's routes: the browser's three, then the one WordPlus Cloud posts its webhooks to. */
    public const ROUTES = ['auth', 'calls', 'keys', 'webhook'];

    /** The browser files the package carries, synced from @wordplus/realtime (its scripts/sync-files.mjs), with files.json. */
    public const FILES_DIR = __DIR__.'/../assets';

    /** @var array{version: string, files: array<string, string>}|null */
    private static ?array $manifest = null;

    public const TOKEN_LABEL = 'wordplus-realtime channels v1';

    public const PUBLISH_LABEL = 'wordplus-realtime publish v1';

    public const WEBHOOK_LABEL = 'wordplus-realtime webhook v1';

    public const CALLS_LABEL = 'wordplus-realtime calls v1';

    public const CALL_KEY_LABEL = 'wordplus-realtime call key v1';

    public const AUDIENCE = 'wordplus-realtime';

    public const CALL_AUDIENCE = 'wordplus-realtime call';

    public const LINE_AUDIENCE = 'wordplus-realtime line';

    public const ROOM_AUDIENCE = 'wordplus-realtime room';

    public const IDENTITY_AUDIENCE = 'wordplus-realtime identity';

    public const TOKEN_TTL = 21600;

    public const MAX_TTL = 86400;

    public const CALL_TTL = 21600;

    public const MAX_ROOMS = 100;

    /** The cloud keeps at most this many events a room, for at most this many days: 5 minutes, for a page that reconnects. */
    public const MAX_HISTORY = 1000;

    public const MAX_HISTORY_DAYS = 5 / 1440;

    /** Events in one batch request, and a webhook's signature's window. */
    public const BATCH = 100;

    public const WEBHOOK_WINDOW = 300;

    /** A live stream's grant: Easy Stream's key label and audience, its claims in this order (Easy Stream's protocol). */
    public const GRANT_KEY_LABEL = 'easy-stream grant v1';

    public const GRANT_AUDIENCE = 'easy-stream';

    /** What a host does to someone else in a group call from its call screen: the calls route's `moderate`. */
    public const MODERATION_ACTS = ['mute', 'speak', 'listen', 'remove'];

    public const CLAIM_ORDER = ['iss', 'aud', 'str', 'role', 'sub', 'name', 'avatar', 'chat', 'mod', 'src', 'q', 'stage', 'offline', 'links', 'fresh', 'people', 'reactions', 'org', 'iat', 'exp'];

    /** A stream's grant lives ten minutes, as Easy Stream's do; the page asks for another as one runs out. */
    public const STREAM_TTL = 600;

    /** A live stream's name: a room's without an underscore, as the cloud takes it in a grant's `str`. */
    private const STREAM_NAME = '/^[A-Za-z0-9\-=@,.;:]{1,128}$/';

    private const STREAM_USER = '/^[\w.@-]{1,64}$/';

    private readonly string $site;

    private readonly string $secret;

    private readonly string $localKey;

    private readonly Keys $keys;

    /** The rooms the app owns: each pattern's options and rule. */
    private readonly Patterns $rules;

    private readonly Patterns $callRules;

    private readonly Patterns $groupRules;

    private readonly Patterns $streamRules;

    /** @var array<string, array{0: callable, 1: callable|null}> */
    private array $kinds = [];

    /** @var array<string, list<callable>> */
    private array $handlers = [];

    /** @var array{rule: callable, options: callable}|null who decides a room no room() rule owns, and what it is */
    private ?array $fallback = null;

    /** @var array{token: string, exp: int, rooms: list<string>}|false|null */
    private array|false|null $publicGrant = null;

    private bool $warnedUser = false;

    /**
     * @param array{
     *     site: string, secret: string, local_key: string,
     *     profile?: callable(int|string): (array<string, mixed>|null),
     *     has_keys?: callable(int|string): bool,
     *     hidden?: callable(int|string): bool,
     *     generations?: GenerationStore,
     *     seal?: bool, base?: string, routes?: array<string, string>, headers?: array<string, string>, files?: bool,
     *     worker?: string, rooms?: string, rooms_worker?: string, server?: string, api?: string, token_ttl?: int,
     *     call_token_ttl?: int,
     *     http?: callable(string, string, array<string, string>, ?string): array{status: int, body: string},
     *     now?: callable(): int,
     * } $options
     */
    public function __construct(private array $options)
    {
        $this->site = (string) ($options['site'] ?? '');
        $this->secret = (string) ($options['secret'] ?? '');
        $this->localKey = (string) ($options['local_key'] ?? '');

        if ('' === $this->site || '' === $this->secret) {
            throw new \InvalidArgumentException('wordplus-realtime: site and secret are required (WORDPLUS_SITE, WORDPLUS_SECRET)');
        }

        $this->keys = new Keys($this->localKey, $options['generations'] ?? null);
        $this->rules = new Patterns();
        $this->callRules = new Patterns();
        $this->groupRules = new Patterns();
        $this->streamRules = new Patterns();
    }

    /**
     * From WORDPLUS_SITE, WORDPLUS_SECRET and WORDPLUS_LOCAL_KEY in the environment, with the other options given.
     *
     * @param array<string, mixed> $options
     */
    public static function fromEnvironment(array $options = []): self
    {
        $env = static fn (string $name): string => (string) ($_ENV[$name] ?? $_SERVER[$name] ?? getenv($name) ?: '');

        return new self($options + ['site' => $env('WORDPLUS_SITE'), 'secret' => $env('WORDPLUS_SECRET'), 'local_key' => $env('WORDPLUS_LOCAL_KEY')]);
    }

    public function keys(): Keys
    {
        return $this->keys;
    }

    public function site(): string
    {
        return $this->site;
    }

    /**
     * Rooms the app owns, by pattern (`order:{id}`), who may join, and what they are: `$rule( $user, $params, $room )`
     * answers false, true, or `[ 'info' => …, 'emit' => …, 'readOnly' => … ]` for that user. `$options`: `presence`,
     * `count`, `history` (a number, or `[ 'last' => …, 'days' => … ]`), `emit` and `webhook` (names, or '*'), `public`,
     * `seal`. A public room may have no rule: anyone joins. The rule with the most characters of its own decides.
     *
     * @param array<string, mixed> $options
     */
    public function room(string $pattern, ?callable $rule = null, array $options = []): self
    {
        if (empty($options['public']) && null === $rule) {
            throw new \InvalidArgumentException('wordplus-realtime: room '.json_encode($pattern).' needs a rule, or `public`');
        }

        if (!empty($options['presence']) && !empty($options['public'])) {
            throw new \InvalidArgumentException('wordplus-realtime: a public room has no members to list; take `presence` off '.json_encode($pattern));
        }

        $this->rules->add($pattern, ['options' => self::normalized($options), 'rule' => $rule]);
        $this->publicGrant = null;

        return $this;
    }

    /**
     * Who decides a room no room() rule owns, and what such a room is: Laravel's routes/channels.php, through the
     * broadcaster. `$rule( $user, $room )` answers as a room rule does, `presence` true in an array for a member of a
     * presence room; `$options( $room )` the room's options, or null for a room it doesn't own either.
     */
    public function fallback(?callable $rule, ?callable $options = null): self
    {
        $this->fallback = null === $rule ? null : ['rule' => $rule, 'options' => $options ?? static fn (string $room): array => []];

        return $this;
    }

    /**
     * Calls between two people the app owns, by pattern (`support:{a}:{b}`): `$rule( $user, $withUser, $params, $call,
     * $side )` answers false, true, or `[ 'info', 'publish', 'subscribe', 'admin', 'hidden' ]`. It is asked as the caller
     * places the call (`$side['role']` `caller`) and again as the callee answers (`callee`), so a rule may let someone
     * answer calls they may not place.
     *
     * A call becomes a group call when either of its people adds someone, which the rule decides as for any call: the
     * user calling the person about the call's name, then the person taking a call from them. A call's rooms are
     * encrypted end to end with a key only this server gives out, to the people in the call. `$options['people']`
     * (`fn ( $user, $call )`) lists the user ids the call screen offers to add.
     *
     * @param array{people?: callable(int, string): array<int|string>} $options
     */
    public function calls(string $pattern, callable $rule, array $options = []): self
    {
        $this->callRules->add($pattern, ['rule' => $rule, 'people' => isset($options['people']) && is_callable($options['people']) ? $options['people'] : null]);

        return $this;
    }

    /**
     * Group calls the app owns, by pattern (`meeting:{id}`): `$rule( $user, $params, $name )`; `$options['encrypt']`
     * encrypts their rooms end to end.
     *
     * @param array{encrypt?: bool} $options
     */
    public function groupCalls(string $pattern, callable $rule, array $options = []): self
    {
        $this->groupRules->add($pattern, ['rule' => $rule, 'encrypt' => !empty($options['encrypt'])]);

        return $this;
    }

    /**
     * Live streams the app owns, by pattern (`show:{id}`): `$rule( $user, $params, $name )` says who is what in each,
     * `$settings` are the stream's own. The rule answers false, a role (`host`, `guest`, `viewer`) or the role with more:
     * `['role' => …, 'info' => ['name' => …, 'avatar' => https], 'chat' => 'write'|'read', 'mod' => bool, 'src' => [camera,
     * microphone, screen], 'quality' => '720p'|'1080p']`. `$user` is null for a visitor who isn't signed in, who may only
     * be a viewer. Settings: `stage`, `reactions`, `people` (`mods` or `all`) and `chat` (`offline` open or closed,
     * `links`, `fresh`), each a value or a callable of the name's parts. A page asks with `calls.stream( name )`.
     *
     * @param array{stage?: bool|callable, reactions?: bool|callable, people?: string|callable, chat?: array<string, mixed>|callable} $settings
     */
    public function streams(string $pattern, callable $rule, array $settings = []): self
    {
        $this->streamRules->add($pattern, ['rule' => $rule, 'settings' => $settings]);

        return $this;
    }

    /**
     * Keys of the app's own kind (`kind:{rest}`), and who may have them: `$mayHave( $rest, $user, $label )`. A kind is
     * 1–16 of a-z and 0-9, starting with a letter, and not `d`, `u` or `ch`. `$newest( $rest, $label )` names the newest
     * key of the same thing, for a kind with generations.
     */
    public function kind(string $kind, callable $mayHave, ?callable $newest = null): self
    {
        if (1 === preg_match('/^[a-z][a-z0-9]{0,15}$/', $kind) && !in_array($kind, Keys::RESERVED, true)) {
            $this->kinds[$kind] = [$mayHave, $newest];
        }

        return $this;
    }

    /**
     * A webhook from the realtime server, opened: `room.occupied`, `room.vacated`, `member.joined`, `member.left`,
     * `user.online`, `user.offline`, `stream.live`, `stream.ended` (a live stream going live and ending, its name in
     * `stream`), what people used (`call.ended`, `group.joined`, `group.left`, `stream.joined`, `stream.left`, with
     * `seconds` once over), the name of a browser's event a room's rule sends here (`webhook`), or '*'.
     */
    public function on(string $type, callable $handler): self
    {
        $this->handlers[$type][] = $handler;

        return $this;
    }

    /** An emit to rooms: `$realtime->to( 'order:42' )->except( $tab )->emit( 'updated', $data )`. */
    public function to(string|array $rooms): Emitter
    {
        return (new Emitter($this))->to($rooms);
    }

    /** An emit to users, every connection they have open: `$realtime->toUser( 42 )->emit( 'notice', $data )`. */
    public function toUser(int|string|array $users): Emitter
    {
        return (new Emitter($this))->toUser($users);
    }

    /** A room as the realtime server holds it: its members, count, history, and kicking a user out. */
    public function in(string $room): RoomQueries
    {
        return new RoomQueries($this, $room);
    }

    /** Who is online across the app. */
    public function online(): OnlineQueries
    {
        return new OnlineQueries($this);
    }

    /**
     * The rooms someone is in now, by name: those starting with `prefix`, a page at a time (`cursor` from `next`, `limit`
     * up to 1,000).
     *
     * @param array{prefix?: string, cursor?: string, limit?: int} $page
     *
     * @return array{count: int, rooms: list<array{name: string, count: int, connections: int}>, next?: string}
     */
    public function listRooms(array $page = []): array
    {
        $query = http_build_query(array_filter($page, static fn ($v) => null !== $v));
        $answer = $this->request('GET', '/wordplus/realtime/rooms'.('' === $query ? '' : '?'.$query));
        $out = ['count' => (int) ($answer['count'] ?? 0), 'rooms' => array_values((array) ($answer['rooms'] ?? []))];

        if (isset($answer['next'])) {
            $out['next'] = (string) $answer['next'];
        }

        return $out;
    }

    /** A user as the realtime server knows them: their state, taking them out of everything at once, asking their pages. */
    public function user(int|string $id): UserQueries
    {
        return new UserQueries($this, (string) $id);
    }

    /**
     * What the app uses now: its connections (those of an app against its plan), and the credits of its account as WordPlus Cloud last
     * said, a credit a minute.
     *
     * @return array{connections: array{now: int, limit: ?int}, credits: ?array<string, mixed>}
     */
    public function usage(): array
    {
        $answer = $this->request('GET', '/wordplus/realtime/usage');

        return ['connections' => (array) ($answer['connections'] ?? []), 'credits' => $answer['credits'] ?? null];
    }

    /** The last deliveries of the webhook, a batch not taken sent again, and a test event. */
    public function webhooks(): WebhookQueries
    {
        return new WebhookQueries($this);
    }

    /**
     * The group calls of the app going on now, from the media server.
     *
     * @return list<array{name: string, people: int, started_at: int}>
     */
    public function listCalls(): array
    {
        return array_values((array) ($this->request('GET', '/wordplus/realtime/calls')['calls'] ?? []));
    }

    /** A group call as the media server holds it: its people, muting, removing someone, ending it. */
    public function call(string $name): CallQueries
    {
        return new CallQueries($this, $name);
    }

    /**
     * The live streams of the app that are live now.
     *
     * @return list<array{name: string, started_at: ?int, viewers: int}>
     */
    public function listStreams(): array
    {
        return array_values((array) ($this->request('GET', '/wordplus/realtime/streams')['streams'] ?? []));
    }

    /** A live stream: its state, who is in it, its chat, and what its moderators do. */
    public function stream(string $name): StreamQueries
    {
        return new StreamQueries($this, $name);
    }

    /**
     * Several events at once, each as an emit takes them (`rooms`, `users`, `event`, `data`, `except`, `seal`). The kept
     * events' ids, by room, for each.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, string>>
     */
    public function batch(array $events): array
    {
        return array_map(fn (array $event) => $this->emitEvent($event), $events);
    }

    /**
     * What the auth route answers for the rooms asked: one token for those allowed, their keys, and why each other was
     * refused (`forbidden`, `bad_room`, `no_rule`, `too_many`).
     *
     * @param array<mixed> $rooms
     *
     * @return array<string, mixed>
     */
    public function authorize(array $rooms, int|string|null $user): array
    {
        $denied = [];
        $allowed = [];
        $asked = array_values(array_unique(array_map('strval', array_filter($rooms, 'is_scalar'))));

        foreach ($asked as $i => $room) {
            if ($i >= self::MAX_ROOMS) {
                $denied[$room] = 'too_many';

                continue;
            }

            if (!Patterns::validRoom($room)) {
                $denied[$room] = 'bad_room';

                continue;
            }

            $entry = $this->entryFor($room);

            if (null === $entry) {
                $denied[$room] = 'no_rule';

                continue;
            }

            $answer = $this->answerFor($entry, $room, $user);
            $options = $entry['options'];

            if (null !== $answer && !empty($answer['presence'])) {
                $options['presence'] = true;
                $options['count'] = true;
            }

            if (null === $answer || ($options['presence'] && !self::known($user))) {
                $denied[$room] = 'forbidden';

                continue;
            }

            $allowed[] = [
                'room' => $room,
                'options' => $options,
                'emit' => $answer['emit'] ?? $options['emit'],
                'readOnly' => !empty($answer['readOnly']),
                'info' => $answer['info'] ?? null,
                'given' => array_key_exists('info', $answer),
            ];
        }

        if ([] === $allowed) {
            return ['denied' => (object) $denied];
        }

        $now = $this->now();
        $exp = $now + self::clamp((int) ($this->options['token_ttl'] ?? self::TOKEN_TTL), 60, self::MAX_TTL);
        $claims = ['iss' => $this->site, 'aud' => self::AUDIENCE, 'iat' => $now, 'exp' => $exp];

        if (self::known($user)) {
            $claims['sub'] = (string) $user;
        }

        $r = [];
        $infos = [];
        $plainInfo = null;
        $hasPlainInfo = false;
        $keys = [];

        foreach ($allowed as $a) {
            $r[$a['room']] = (object) self::short(['emit' => $a['emit']] + $a['options'], $a['readOnly']);
            $sealed = $this->sealing() && $a['options']['seal'];

            if ($sealed) {
                $keys[] = $this->keys->entry($this->keys->roomLabel($a['room']));
            }

            if (!$a['options']['presence']) {
                continue;
            }

            $info = $a['given'] ? $a['info'] : $this->defaultInfo($user);

            if ($sealed) {
                $infos[$a['room']] = $this->keys->sealJson($info, $this->keys->roomLabel($a['room'])) ?? $info;
            } else {
                $plainInfo = $info;
                $hasPlainInfo = true;
            }
        }

        $claims['r'] = (object) $r;

        if ([] !== $infos) {
            $claims['infos'] = (object) $infos;
        }

        if ($hasPlainInfo) {
            $claims['info'] = $plainInfo;
        }

        $grant = ['token' => self::jwt($claims, $this->tokenKey()), 'exp' => $exp, 'denied' => (object) $denied];

        if ([] !== $keys) {
            $grant['keys'] = $keys;
        }

        return $grant;
    }

    /**
     * The grant for the app's public rooms, which every page may carry: their patterns, no user, signed for 6 hours and
     * made again an hour before it runs out. Empty when the app has none.
     *
     * @return list<array{token: string, exp: int, rooms: list<string>}>
     */
    public function publicGrants(): array
    {
        if (is_array($this->publicGrant) && $this->publicGrant['exp'] - $this->now() < 3600) {
            $this->publicGrant = null;
        }

        if (null === $this->publicGrant) {
            $open = array_values(array_filter($this->rules->entries(), static fn (array $e) => $e[1]['options']['public'] && null === $e[1]['rule']));

            if ([] === $open) {
                $this->publicGrant = false;
            } else {
                $now = $this->now();
                $exp = $now + self::TOKEN_TTL;
                $r = [];

                foreach ($open as [$pattern, $entry]) {
                    $r[$pattern] = (object) self::short($entry['options'], false);
                }

                $this->publicGrant = [
                    'token' => self::jwt(['iss' => $this->site, 'aud' => self::AUDIENCE, 'iat' => $now, 'exp' => $exp, 'r' => (object) $r], $this->tokenKey()),
                    'exp' => $exp,
                    'rooms' => array_map(static fn (array $e) => $e[0], $open),
                ];
            }
        }

        return false === $this->publicGrant ? [] : [$this->publicGrant];
    }

    /**
     * What the calls route answers: the page's line (`line`), its side of a call (`call`, `id`, `role`, `to` or
     * `from`, `type`, and `group`, `pair` and `member` or `ring` for a ring into the group call it became), a place in a
     * group call (`room`, `type`), the people a user may add to a call (`people`: its name), or whether the user may call
     * each of some people (`may`: up to 100 `['call' => …, 'to' => …]`, each asked of its rule as the caller), or a
     * host's act in a group call (`moderate`: its name, `act` and `user`). A token, when it runs out, the call's key for
     * its signals, the key its rooms encrypt their media with, the call's pair and the user's proof of being in it, the
     * proof a person rung in was rung, the people, `may` (true or false for each, in the order asked), `done` for a
     * host's act, or `denied`: `no_user`, `bad_request`, `forbidden`, `server`.
     *
     * Who is in a call is in proofs made with the local key, which WordPlus Cloud never sees: the realtime server
     * carries them from page to page and can't make one, and this server keeps nothing.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public function authorizeCall(array $request, int|string|null $user): array
    {
        // A live stream's grant (streams()), which a visitor who isn't signed in may get too, as a viewer.
        if (array_key_exists('stream', $request)) {
            return $this->authorizeStream($request['stream'], $user);
        }

        $me = self::callUser($user);

        if (null === $me) {
            if (self::known($user) && !$this->warnedUser) {
                $this->warnedUser = true;
                trigger_error('wordplus-realtime: a user of a call is an id of up to 64 characters with no space; '.var_export($user, true).' is not one', E_USER_NOTICE);
            }

            return ['denied' => 'no_user'];
        }

        if (!empty($request['line'])) {
            return $this->signCalls(['aud' => self::LINE_AUDIENCE, 'sub' => $me]);
        }

        if (array_key_exists('people', $request)) {
            return Patterns::validRoom($request['people']) ? ['people' => $this->callPeople($request['people'], $me)] : ['denied' => 'bad_request'];
        }

        if (array_key_exists('may', $request)) {
            return $this->mayCall($request['may'], $me);
        }

        if (array_key_exists('moderate', $request)) {
            return $this->moderateCall($request['moderate'], $request['act'] ?? null, $request['user'] ?? null, $me);
        }

        $type = isset($request['type']) && 'audio' === $request['type'] ? 'audio' : 'video';

        if (array_key_exists('room', $request)) {
            $room = $request['room'];

            if (!Patterns::validRoom($room)) {
                return ['denied' => 'bad_request'];
            }

            $found = $this->groupRules->match($room);
            $answer = null === $found ? null : $this->callAnswer(($found['value']['rule'])($me, $found['params'], $room), $me);

            if (null === $answer) {
                return ['denied' => 'forbidden'];
            }

            $signed = $this->signCalls([
                'aud' => self::ROOM_AUDIENCE,
                'room' => $room,
                'sub' => $me,
                'type' => $type,
                'info' => $this->sealedCallInfo($answer['info']),
                'publish' => !isset($answer['publish']) || (bool) $answer['publish'],
                'subscribe' => !isset($answer['subscribe']) || (bool) $answer['subscribe'],
                'admin' => !empty($answer['admin']),
                'hidden' => !empty($answer['hidden']),
            ]);

            // Encrypted end to end when its rule says so: everyone the rule lets in gets the room's key.
            if ($found['value']['encrypt']) {
                $signed['media_key'] = $this->keys->media('room|'.$room);
            }

            return $signed;
        }

        $call = $request['call'] ?? null;
        $id = $request['id'] ?? null;
        $role = $request['role'] ?? null;
        $group = $request['group'] ?? null;

        if (!Patterns::validRoom($call) || !self::validAttempt($id) || !in_array($role, ['caller', 'callee'], true)) {
            return ['denied' => 'bad_request'];
        }

        if (null !== $group && (!self::validAttempt($group) || $group === $id)) {
            return ['denied' => 'bad_request'];
        }

        $other = self::callUser('caller' === $role ? ($request['to'] ?? null) : ($request['from'] ?? null));

        if (null === $other || $other === $me) {
            return ['denied' => 'bad_request'];
        }

        $answer = $this->askCaller($me, $call, $other, $role);

        if (null === $answer) {
            return ['denied' => 'forbidden'];
        }

        // The call's two people, whose key its rooms encrypt with: this request's, for the call itself; for a ring into
        // the group call it became, the pair its proofs name, with the ringer's proof of being in it or the proof the
        // person was rung.
        if (null === $group) {
            $first = $id;
            $pair = 'caller' === $role ? [$me, $other] : [$other, $me];
        } else {
            $first = $group;
            $pair = self::pairOf($request['pair'] ?? null);

            if (null === $pair) {
                return ['denied' => 'bad_request'];
            }

            $proof = 'caller' === $role ? ($request['member'] ?? null) : ($request['ring'] ?? null);

            if (!is_string($proof) || !hash_equals($this->callProof('caller' === $role ? 'member' : 'rung', $call, $first, $pair, $me), $proof)) {
                return ['denied' => 'forbidden'];
            }
        }

        $claims = ['aud' => self::CALL_AUDIENCE, 'call' => $call, 'id' => $id, 'sub' => $me, 'role' => $role, 'type' => $type, 'info' => $this->sealedCallInfo($answer['info'])];

        if ('caller' === $role) {
            $claims['to'] = $other;
        }

        if (null !== $group) {
            $claims['group'] = $group;
        }

        $signed = $this->signCalls($claims);
        $signed['key'] = $this->callKey($id, 'caller' === $role ? $me : $other, 'caller' === $role ? $other : $me);
        $signed['media_key'] = $this->keys->media('call|'.$call.'|'.$first.'|'.self::idPart($pair[0]).'|'.self::idPart($pair[1]));
        $signed['pair'] = $pair;
        $signed['member'] = $this->callProof('member', $call, $first, $pair, $me);

        if (null !== $group && 'caller' === $role) {
            $signed['ring'] = $this->callProof('rung', $call, $first, $pair, $other);
        }

        return $signed;
    }

    /** The key a call's two browsers seal their negotiation with: made from the local key, which WordPlus Cloud doesn't know. */
    public function callKey(string $id, int|string $caller, int|string $callee): string
    {
        return hash_hmac('sha256', self::CALL_KEY_LABEL.'|'.$id.'|'.self::idPart($caller).'|'.self::idPart($callee), $this->localKey);
    }

    /**
     * What the keys route answers, `{ labels }`: the keys asked for that this user may have, each with the newest of the
     * same thing beside it. With no labels: the keys the user starts with, and their identity.
     *
     * @return array{keys: list<string>, identity?: array{token: string, exp: int}}
     */
    public function keysAnswer(mixed $labels, int|string|null $user): array
    {
        if (!is_array($labels) || [] === $labels) {
            $keys = $this->userEntries($user);
            $identity = [] === $keys ? null : $this->identity($user);

            return null === $identity ? ['keys' => $keys] : ['keys' => $keys, 'identity' => $identity];
        }

        $keys = [];

        foreach (array_slice(array_values(array_unique(array_filter($labels, 'is_string'))), 0, Keys::MAX_LABELS) as $label) {
            if (!$this->mayHave($label, $user)) {
                continue;
            }

            $keys[] = $this->keys->entry($label);
            $newest = $this->newest($label);

            if ('' !== $newest && $this->mayHave($newest, $user)) {
                $keys[] = $this->keys->entry($newest);
            }
        }

        return ['keys' => array_values(array_unique($keys))];
    }

    /** Whether this user may have the key a label names, as WordPress's may_have() decides. */
    public function mayHave(string $label, int|string|null $user): bool
    {
        if (!Keys::validLabel($label) || !$this->userHasKeys($user)) {
            return false;
        }

        [$kind, $rest] = explode(':', $label, 2);

        if ('d' === $kind) {
            return self::known($user) && 1 === preg_match('/^\d{1,9}$/', $rest) && (int) $rest <= Keys::day() + 1;
        }

        if ('u' === $kind) {
            return self::known($user) && $rest === substr($this->keys->userLabel($user), 2);
        }

        if ('ch' === $kind) {
            if (1 !== preg_match('/^(.+):(\d{1,9})$/', $rest, $m) || !Patterns::validRoom($m[1])) {
                return false;
            }

            $entry = $this->entryFor($m[1]);

            if (null === $entry || !$entry['options']['seal']) {
                return false;
            }

            $generation = (int) $m[2];

            return $generation >= 1 && $generation <= $this->keys->roomGeneration($m[1]) && $this->mayJoin($m[1], $user);
        }

        return isset($this->kinds[$kind]) && true === ($this->kinds[$kind][0])($rest, $user, $label);
    }

    /** Whether a user may join a room, as the auth route decides it. */
    public function mayJoin(string $room, int|string|null $user): bool
    {
        if (!Patterns::validRoom($room)) {
            return false;
        }

        $entry = $this->entryFor($room);

        if (null === $entry) {
            return false;
        }

        $answer = $this->answerFor($entry, $room, $user);

        if (null === $answer) {
            return false;
        }

        return !($entry['options']['presence'] || !empty($answer['presence'])) || self::known($user);
    }

    /**
     * Who a user is for the realtime server, and their entry in the app's directory, signed as a room token is: their
     * online status, what the app's server sends them, and the directory. Null for nobody, a user without a profile, or
     * one cut off from the keys.
     *
     * @return array{token: string, exp: int}|null
     */
    public function identity(int|string|null $user): ?array
    {
        if (!self::known($user) || !$this->userHasKeys($user)) {
            return null;
        }

        $plain = $this->profileOf($user);

        if (null === $plain) {
            return null;
        }

        $profile = $this->keys->profile($user, $plain, $this->sealing());
        $now = $this->now();
        $exp = $now + self::clamp((int) ($this->options['token_ttl'] ?? self::TOKEN_TTL), 60, self::MAX_TTL);
        $claims = ['iss' => $this->site, 'aud' => self::IDENTITY_AUDIENCE, 'iat' => $now, 'exp' => $exp, 'sub' => (string) $user, 'pd' => $profile['pd'], 'pdh' => $profile['pdh']];

        if (isset($this->options['hidden']) && true === ($this->options['hidden'])($user)) {
            $claims['hid'] = true;
        }

        return ['token' => self::jwt($claims, $this->tokenKey()), 'exp' => $exp];
    }

    /** Moves a room's key on, when someone lost access to it; needs `generations`. */
    public function rotateRoom(string $room): void
    {
        $this->keys->rotateRoom($room);
    }

    /** Moves a user's own key on, when their access is revoked; needs `generations`. */
    public function rotateUser(int|string $user): void
    {
        $this->keys->rotateUser($user);
    }

    /**
     * The browser's settings for a user, for connect(): where to connect, the app, its routes, its public rooms' grant,
     * and a signed-in user's keys and identity, so their page needs no request before it connects.
     *
     * @param array<string, string> $headers headers the browser sends with each route's request, such as a CSRF token
     *
     * @return array<string, mixed>
     */
    public function config(int|string|null $user = null, array $headers = []): array
    {
        $headers = $headers + (array) ($this->options['headers'] ?? []);
        $entries = $this->userEntries($user);
        $identity = [] === $entries ? null : $this->identity($user);
        $grants = $this->publicGrants();
        $keys = ['endpoint' => $this->address('keys'), 'headers' => (object) $headers, 'entries' => $entries];

        if (null !== $identity) {
            $keys['identity'] = $identity;
        }

        $people = array_filter($this->callRules->entries(), static fn (array $e) => null !== $e[1]['people']);

        return array_filter([
            'server' => rtrim((string) ($this->options['server'] ?? self::SERVER), '/'),
            'path' => self::PATH,
            'site' => $this->site,
            'api' => rtrim((string) ($this->options['api'] ?? self::API_SERVER), '/'),
            'worker' => $this->options['worker'] ?? ($this->servesFiles() ? $this->fileAt('realtime-worker.js') : null),
            'rooms' => $this->options['rooms'] ?? ($this->servesFiles() ? $this->fileAt('realtime-rooms.min.js') : null),
            'roomsWorker' => $this->options['rooms_worker'] ?? null,
            // The call screen asks whom a user may add once a call is up, when some calls rule lists people.
            'screen' => [] !== $people ? ['add' => true] : null,
            'grants' => [] !== $grants ? $grants : null,
            'auth' => ['endpoint' => $this->address('auth'), 'headers' => (object) $headers],
            'calls' => ['endpoint' => $this->address('calls'), 'headers' => (object) $headers],
            'keys' => $keys,
        ], static fn ($value) => null !== $value);
    }

    /**
     * What a page's `<head>` takes, as HTML: `window.wordplusRealtimeConfig`, the settings for the user, and an import map
     * naming `@wordplus/realtime` the client the routes serve, so a page's own module imports it with no bundler:
     * `import { connect } from '@wordplus/realtime'`. `$importmap` false leaves the map out, for a page whose bundler
     * resolves the package, or that has an import map of its own.
     *
     * @param array<string, string> $headers headers the browser sends with each route's request, such as a CSRF token
     */
    public function head(int|string|null $user = null, array $headers = [], bool $importmap = true): string
    {
        // JSON that a <script> cannot be closed or commented out by.
        $json = static fn ($value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $out = $importmap && $this->servesFiles()
            ? '<script type="importmap">'.$json(['imports' => ['@wordplus/realtime' => $this->fileAt('realtime.js')]])."</script>\n"
            : '';

        return $out.'<script>window.wordplusRealtimeConfig = '.$json($this->config($user, $headers)).';</script>';
    }

    /**
     * Where the routes serve a browser file, in the folder of this version: `realtime.js`, `realtime-worker.js`,
     * `realtime-rooms.min.js`, `realtime-rooms-e2ee.worker.js`, `realtime-ui.min.js` or `realtime-ui-streams.min.js`.
     * For a page with no bundler: `<script type="module">import { connect } from '<?= $realtime->fileAt( 'realtime.js' ) ?>'`.
     */
    public function fileAt(string $name): string
    {
        return $this->filesBase().'/'.self::filesManifest()['version'].'/'.$name;
    }

    /**
     * A browser file, for a GET or HEAD under `{base}/files/`: null for any other request. The package carries them
     * (assets/), so a PHP app needs no Node and copies nothing. In the folder of this version a file is kept for a year;
     * with no version, as a page names it by hand, it is checked again each time; in another version's folder, as a page
     * of the release before or after asks while servers are updated, it is the file of this one, kept by nobody.
     *
     * @return array{status: int, headers: array<string, string>, body: ?string}|null
     */
    public function file(string $method, string $path, ?string $ifNoneMatch = null): ?array
    {
        $method = strtoupper($method);

        if (('GET' !== $method && 'HEAD' !== $method) || !$this->servesFiles()) {
            return null;
        }

        $base = trim($this->filesBase(), '/');
        $path = trim(self::pathOf($path), '/');

        if (!str_starts_with($path, $base.'/')) {
            return null;
        }

        $parts = explode('/', substr($path, strlen($base) + 1));
        $name = (string) array_pop($parts);
        $manifest = self::filesManifest();
        $folder = match (count($parts)) {
            0 => null,
            1 => $parts[0],
            default => false,
        };

        if (false === $folder || !isset($manifest['files'][$name])) {
            return ['status' => 404, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'body' => 'Not found'];
        }

        $etag = '"'.$manifest['version'].'"';
        $headers = [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => $folder === $manifest['version'] ? 'public, max-age=31536000, immutable' : (null === $folder ? 'no-cache' : 'no-store'),
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (null === $folder && $ifNoneMatch === $etag) {
            return ['status' => 304, 'headers' => $headers, 'body' => null];
        }

        return ['status' => 200, 'headers' => $headers, 'body' => 'HEAD' === $method ? null : (string) file_get_contents(self::FILES_DIR.'/'.$name)];
    }

    /**
     * Answers the current request in plain PHP when it asks for a browser file, and says whether it did: in a front
     * controller, `if ( $realtime->respondFile() ) exit;` before the app's own routes.
     */
    public function respondFile(): bool
    {
        $answer = $this->file((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'), isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : null);

        if (null === $answer) {
            return false;
        }

        http_response_code($answer['status']);

        foreach ($answer['headers'] as $name => $value) {
            header($name.': '.$value);
        }

        echo $answer['body'] ?? '';

        return true;
    }

    /** @return array{version: string, files: array<string, string>} */
    private static function filesManifest(): array
    {
        return self::$manifest ??= (array) json_decode((string) file_get_contents(self::FILES_DIR.'/files.json'), true, 4, JSON_THROW_ON_ERROR);
    }

    private function servesFiles(): bool
    {
        return false !== ($this->options['files'] ?? true);
    }

    private function filesBase(): string
    {
        return rtrim((string) ($this->options['base'] ?? '/api/realtime'), '/').'/files';
    }

    /** Where a route is (`auth`, `calls`, `keys` or `webhook`): its own address from `routes`, else `{base}/{name}`. */
    public function address(string $name): string
    {
        $own = $this->options['routes'][$name] ?? null;

        if (is_string($own) && '' !== $own) {
            return $own;
        }

        return rtrim((string) ($this->options['base'] ?? '/api/realtime'), '/').'/'.$name;
    }

    /**
     * The route a request's path asks for: the one at that address, else, while no route has an address of its own, the
     * path's last part, as the routes were always told apart. Null for a path that is none of them: a front controller
     * answers `respond( $realtime->routeOf( $path ), $user )` when it is not null.
     */
    public function routeOf(string $path): ?string
    {
        $path = rtrim(self::pathOf($path), '/');

        foreach (self::ROUTES as $name) {
            if (rtrim(self::pathOf($this->address($name)), '/') === $path) {
                return $name;
            }
        }

        if ([] !== array_filter((array) ($this->options['routes'] ?? []), static fn ($own) => is_string($own) && '' !== $own)) {
            return null;
        }

        $parts = explode('/', $path);

        return (string) end($parts);
    }

    /** An address's path: itself without its query, or a full address's path part. */
    private static function pathOf(string $address): string
    {
        if (1 === preg_match('#^https?://#i', $address)) {
            return (string) (parse_url($address, PHP_URL_PATH) ?? '');
        }

        return (string) preg_replace('/[?#].*$/s', '', $address);
    }

    /**
     * One of the JSON routes, `auth`, `calls` or `keys`, for a request's JSON body and its user.
     *
     * @return array{status: int, body: mixed}
     */
    public function handle(string $action, mixed $body, int|string|null $user): array
    {
        $params = is_array($body) ? $body : [];
        $user = self::known($user) ? $user : null;

        return match ($action) {
            'auth' => is_array($params['rooms'] ?? null)
                ? ['status' => 200, 'body' => $this->authorize($params['rooms'], $user)]
                : ['status' => 400, 'body' => ['code' => 'bad_request', 'message' => 'rooms must be a list of room names.']],
            'calls' => ['status' => 200, 'body' => $this->authorizeCall($params, $user)],
            'keys' => ['status' => 200, 'body' => $this->keysAnswer($params['labels'] ?? null, $user)],
            default => ['status' => 404, 'body' => ['code' => 'not_found']],
        };
    }

    /**
     * A webhook from the realtime server, as its request came (`$raw` the body as sent, `$path` with its query): checked,
     * its events opened and handed to the handlers on() set. Answers 204 once every handler is done, 401 for a request
     * the realtime server didn't sign, 500 when a handler threw (the realtime server sends the batch again later).
     *
     * @param array<string, string|list<string>> $headers the request's headers, any case
     *
     * @return array{status: int, body: mixed}
     */
    public function webhook(string $raw, array $headers, string $path): array
    {
        $header = static function (string $name) use ($headers): string {
            foreach ($headers as $key => $value) {
                if (0 === strcasecmp((string) $key, $name)) {
                    return (string) (is_array($value) ? ($value[0] ?? '') : $value);
                }
            }

            return '';
        };
        $timestamp = $header('X-WordPlus-Timestamp');
        $signature = $header('X-WordPlus-Signature');

        if ($header('X-WordPlus-Site') !== $this->site || '' === $signature || 1 !== preg_match('/^\d{1,12}$/', $timestamp)) {
            return ['status' => 401, 'body' => ['code' => 'missing_signature']];
        }

        if (abs($this->now() - (int) $timestamp) > self::WEBHOOK_WINDOW) {
            return ['status' => 401, 'body' => ['code' => 'stale_signature']];
        }

        $expected = hash_hmac('sha256', $timestamp."\nPOST\n".$path."\n".hash('sha256', $raw), hash_hmac('sha256', self::WEBHOOK_LABEL, $this->secret, true));

        if (!hash_equals($expected, $signature)) {
            return ['status' => 401, 'body' => ['code' => 'bad_signature']];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !is_array($decoded['events'] ?? null)) {
            return ['status' => 400, 'body' => ['code' => 'bad_request']];
        }

        try {
            foreach ($decoded['events'] as $e) {
                if (!is_array($e)) {
                    continue;
                }

                $emit = 'emit' === ($e['type'] ?? null);
                $event = $emit ? ['data' => $this->opened($e['data'] ?? null)] + $e : $e;

                foreach (array_merge($this->handlers[(string) ($emit ? ($e['event'] ?? '') : ($e['type'] ?? ''))] ?? [], $this->handlers['*'] ?? []) as $handler) {
                    $handler($event);
                }
            }
        } catch (\Throwable $error) {
            error_log('wordplus-realtime: a webhook handler failed: '.$error->getMessage());

            return ['status' => 500, 'body' => ['code' => 'handler_failed']];
        }

        return ['status' => 204, 'body' => null];
    }

    /**
     * Answers the current request in plain PHP: POST, the action the route's last part (`auth`, `calls`, `keys` or
     * `webhook`), or named by the file of a route at an address of its own, or by routeOf(). Sends the status, the
     * headers and the JSON.
     */
    public function respond(string $action, int|string|null $user): void
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = (string) file_get_contents('php://input');

        if ('webhook' === $action && 'POST' === strtoupper($method)) {
            $answer = $this->webhook($body, self::requestHeaders(), (string) ($_SERVER['REQUEST_URI'] ?? ''));
        } else {
            $answer = $this->answerRequest($method, (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''), $action, $body, $user);
        }

        http_response_code($answer['status']);
        header('Cache-Control: no-store');

        if (204 === $answer['status']) {
            return;
        }

        header('Content-Type: application/json');
        echo Json::encode($answer['body']);
    }

    /**
     * A request as the routes take it: POST, JSON (a page on another site can't send that without asking first, which
     * it is never allowed), and its body.
     *
     * @return array{status: int, body: mixed}
     */
    public function answerRequest(string $method, string $contentType, string $action, string $body, int|string|null $user): array
    {
        if ('POST' !== strtoupper($method)) {
            return ['status' => 405, 'body' => ['code' => 'method_not_allowed']];
        }

        if (!str_contains(strtolower($contentType), 'application/json')) {
            return ['status' => 415, 'body' => ['code' => 'bad_request', 'message' => 'Send JSON.']];
        }

        try {
            $parsed = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['status' => 400, 'body' => ['code' => 'bad_request', 'message' => 'The body is not JSON.']];
        }

        return $this->handle($action, $parsed, $user);
    }

    /**
     * What an emit sends: each sealed room's own copy, the others together, each user's own copy; the kept events' ids
     * by room. @internal: Emitter and batch() call it.
     *
     * @param array<string, mixed> $event `rooms`, `users`, `event`, `data`, `except`, `seal`
     *
     * @return array<string, string>
     *
     * @throws RealtimeError the server's reason
     */
    public function emitEvent(array $event): array
    {
        $name = $event['event'] ?? null;

        if (!is_string($name) || '' === $name) {
            throw new RealtimeError('bad_request');
        }

        $rooms = array_values(array_unique(array_map('strval', (array) ($event['rooms'] ?? []))));
        $users = array_values(array_unique(array_map('strval', array_filter((array) ($event['users'] ?? []), static fn ($u) => (is_int($u) || is_string($u)) && self::known($u)))));
        $data = $event['data'] ?? null;
        $except = empty($event['except']) ? [] : ['except' => (string) $event['except']];
        $sealing = (!array_key_exists('seal', $event) || false !== $event['seal']) && $this->sealing();
        $out = [];
        $plain = [];
        $plainHistory = [];

        foreach ($rooms as $room) {
            if (!Patterns::validRoom($room)) {
                throw new RealtimeError('bad_room');
            }

            $options = $this->entryFor($room)['options'] ?? null;
            $history = null !== ($options['history'] ?? null) ? [$room => [$options['history']['last'], $options['history']['days']]] : null;

            if ($sealing && !empty($options['seal'])) {
                $out[] = ['rooms' => [$room], 'event' => $name, 'data' => $this->keys->sealJson($data, $this->keys->roomLabel($room))]
                    + (null === $history ? [] : ['history' => (object) $history]) + $except;
            } else {
                $plain[] = $room;
                $plainHistory += $history ?? [];
            }
        }

        if ([] !== $plain) {
            $out[] = ['rooms' => $plain, 'event' => $name, 'data' => $data] + ([] === $plainHistory ? [] : ['history' => (object) $plainHistory]) + $except;
        }

        $plainUsers = [];

        foreach ($users as $user) {
            $label = $sealing ? $this->keys->userLabel($user) : '';

            if ('' !== $label) {
                $out[] = ['users' => [$user], 'event' => $name, 'data' => $this->keys->sealJson($data, $label)] + $except;
            } else {
                $plainUsers[] = $user;
            }
        }

        if ([] !== $plainUsers) {
            $out[] = ['users' => $plainUsers, 'event' => $name, 'data' => $data] + $except;
        }

        $ids = [];

        if (1 === count($out)) {
            return (array) ($this->signed('POST', '/wordplus/realtime/emit', $out[0])['ids'] ?? []);
        }

        foreach (array_chunk($out, self::BATCH) as $batch) {
            foreach ((array) ($this->signed('POST', '/wordplus/realtime/emit/batch', ['events' => $batch])['ids'] ?? []) as $kept) {
                $ids += (array) $kept;
            }
        }

        return $ids;
    }

    /**
     * A request to the realtime server, signed as the app. @internal: RoomQueries and OnlineQueries call it.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        return $this->signed($method, $path, $body);
    }

    /** Data for the pages of a user: sealed with their key when the app seals and has keys. @internal: UserQueries calls it. */
    public function sealedFor(string $user, mixed $data, bool $seal = true): mixed
    {
        $label = $seal && $this->sealing() ? $this->keys->userLabel($user) : '';

        return '' === $label ? $data : $this->keys->sealJson($data, $label);
    }

    /** An event's data as the app's server sees it: opened when it sealed it, its binary parts strings of their bytes. */
    public function opened(mixed $data): mixed
    {
        if (!is_string($data)) {
            return self::unpackBinary($data);
        }

        $plain = $this->keys->open($data);

        if (null === $plain) {
            return $data;
        }

        $decoded = json_decode($plain, true);

        return null === $decoded && 'null' !== $plain ? $data : self::unpackBinary($decoded);
    }

    /** The binary parts of data as the pages carry them in JSON, `{"$b": base64}`, as the Node and Python packages open them. */
    private static function unpackBinary(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 16 || !is_array($value)) {
            return $value;
        }

        if (1 === count($value) && isset($value['$b']) && is_string($value['$b'])) {
            $base64 = strtr($value['$b'], '-_', '+/');
            $bytes = base64_decode(str_pad($base64, (int) (4 * ceil(strlen($base64) / 4)), '='), true);

            return false === $bytes ? $value : $bytes;
        }

        return array_map(static fn (mixed $item): mixed => self::unpackBinary($item, $depth + 1), $value);
    }

    /**
     * The rule and options a room falls under: a room() rule, or the fallback's; null for none.
     *
     * @return array{options: array<string, mixed>, rule: callable|null, params: array<string, string>, fallback: bool}|null
     */
    private function entryFor(string $room): ?array
    {
        $found = $this->rules->match($room);

        if (null !== $found) {
            return ['options' => $found['value']['options'], 'rule' => $found['value']['rule'], 'params' => $found['params'], 'fallback' => false];
        }

        if (null === $this->fallback) {
            return null;
        }

        $options = ($this->fallback['options'])($room);

        return is_array($options) ? ['options' => self::normalized($options), 'rule' => $this->fallback['rule'], 'params' => [], 'fallback' => true] : null;
    }

    /**
     * A rule's answer for a user: null for no, else what they bring and may do.
     *
     * @param array{options: array<string, mixed>, rule: callable|null, params: array<string, string>, fallback: bool} $entry
     *
     * @return array<string, mixed>|null
     */
    private function answerFor(array $entry, string $room, int|string|null $user): ?array
    {
        if (null === $entry['rule']) {
            return $entry['options']['public'] ? [] : null;
        }

        $answer = $entry['fallback'] ? ($entry['rule'])($user, $room) : ($entry['rule'])($user, $entry['params'], $room);

        if (false === $answer || null === $answer) {
            return null;
        }

        if (!is_array($answer)) {
            return [];
        }

        $out = [];

        if (array_key_exists('info', $answer)) {
            $out['info'] = $answer['info'];
        }

        if ('*' === ($answer['emit'] ?? null) || is_array($answer['emit'] ?? null)) {
            $out['emit'] = '*' === $answer['emit'] ? '*' : array_values(array_map('strval', $answer['emit']));
        }

        if (!empty($answer['readOnly'])) {
            $out['readOnly'] = true;
        }

        if (!empty($answer['presence'])) {
            $out['presence'] = true;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function askCaller(int|string $user, string $name, int|string $withUser, string $role): ?array
    {
        $found = $this->callRules->match($name);

        return null === $found ? null : $this->callAnswer(($found['value']['rule'])($user, $withUser, $found['params'], $name, ['role' => $role]), $user);
    }

    /**
     * A calls rule's answer, with the user's info by default; null for no.
     *
     * @return array<string, mixed>|null
     */
    private function callAnswer(mixed $answer, int|string $user): ?array
    {
        if (false === $answer || null === $answer) {
            return null;
        }

        $out = is_array($answer) ? $answer : [];

        if (!array_key_exists('info', $out)) {
            $out['info'] = $this->defaultInfo($user);
        }

        return $out;
    }

    /**
     * A proof only this server makes about a call: `member`, a user is in it; `rung`, someone in it rang a user in.
     *
     * @param array{0: int|string, 1: int|string} $pair
     */
    private function callProof(string $kind, string $call, string $first, array $pair, int|string $user): string
    {
        return $this->keys->proof($kind.'|'.$call.'|'.$first.'|'.self::idPart($pair[0]).'|'.self::idPart($pair[1]).'|'.self::idPart($user));
    }

    /**
     * What a host does to someone else in the group call `$name`, from its call screen: the call's rule asked again for
     * the user, who must be its host (`admin`), then the act through WordPlus Cloud: `mute` their microphone, `speak`
     * or `listen` (permit()'s `publish`), `remove`. `['done' => …]`, whether it changed anything.
     *
     * @return array<string, mixed>
     */
    private function moderateCall(mixed $name, mixed $act, mixed $target, int|string $me): array
    {
        $user = self::callUser($target);

        if (!Patterns::validRoom($name) || !in_array($act, self::MODERATION_ACTS, true) || null === $user || $user === $me) {
            return ['denied' => 'bad_request'];
        }

        $found = $this->groupRules->match($name);
        $answer = null === $found ? null : ($found['value']['rule'])($me, $found['params'], $name);

        if (!is_array($answer) || empty($answer['admin'])) {
            return ['denied' => 'forbidden'];
        }

        $call = $this->call($name);

        try {
            return ['done' => match ($act) {
                'mute' => $call->mute($user, 'microphone'),
                'remove' => $call->remove($user),
                default => $call->permit($user, ['publish' => 'speak' === $act]),
            }];
        } catch (RealtimeError) {
            return ['denied' => 'server'];
        }
    }

    /**
     * Whether a user may call each person asked about (`[['call' => …, 'to' => …]]`), in the order asked: the rule of each
     * call, asked as for its caller, as placing it asks. A list of people asks it before offering its call buttons. At
     * most 100.
     *
     * @return array<string, mixed>
     */
    private function mayCall(mixed $asks, int|string $me): array
    {
        if (!is_array($asks) || !array_is_list($asks) || count($asks) > 100) {
            return ['denied' => 'bad_request'];
        }

        $may = [];

        foreach ($asks as $ask) {
            $call = is_array($ask) ? ($ask['call'] ?? null) : null;
            $other = is_array($ask) ? self::callUser($ask['to'] ?? null) : null;
            // The rule alone, with no info to make for a call no one places yet.
            $found = Patterns::validRoom($call) && null !== $other && $other !== $me ? $this->callRules->match($call) : null;
            $answer = null === $found ? null : ($found['value']['rule'])($me, $other, $found['params'], $call, ['role' => 'caller']);
            $may[] = null !== $answer && false !== $answer;
        }

        return ['may' => $may];
    }

    /**
     * Whom a user may add to a call named `$call`: those its rule's `people` lists whom they may call about it. At most
     * 100.
     *
     * @return list<array{id: int|string, name: string, avatar: string}>
     */
    private function callPeople(string $call, int|string $me): array
    {
        $people = $this->callRules->match($call)['value']['people'] ?? null;

        if (null === $people) {
            return [];
        }

        $listed = [];

        foreach ((array) $people($me, $call) as $id) {
            $user = self::callUser($id);

            if (null === $user || $user === $me || isset($listed[$user]) || null === $this->askCaller($me, $call, $user, 'caller')) {
                continue;
            }

            $profile = $this->profileOf($user);

            if (null !== $profile) {
                $listed[$user] = ['id' => $user, 'name' => $profile['name'], 'avatar' => is_string($profile['avatar'] ?? null) ? $profile['avatar'] : ''];
            }

            if (count($listed) >= 100) {
                break;
            }
        }

        return array_values($listed);
    }

    /** @return array<string, mixed>|null */
    private function profileOf(int|string $user): ?array
    {
        $profile = isset($this->options['profile']) ? ($this->options['profile'])($user) : null;

        return is_array($profile) && isset($profile['name']) && is_string($profile['name']) && '' !== $profile['name'] ? $profile : null;
    }

    /** A member's info by default: their name and picture. */
    private function defaultInfo(int|string|null $user): mixed
    {
        if (!self::known($user)) {
            return null;
        }

        $profile = $this->profileOf($user);

        if (null === $profile) {
            return null;
        }

        return isset($profile['avatar']) && is_string($profile['avatar']) && '' !== $profile['avatar'] ? ['name' => $profile['name'], 'avatar' => $profile['avatar']] : ['name' => $profile['name']];
    }

    private function userHasKeys(int|string|null $user): bool
    {
        if (!self::known($user)) {
            return true;
        }

        return !isset($this->options['has_keys']) || false !== ($this->options['has_keys'])($user);
    }

    /** @return list<string> */
    private function userEntries(int|string|null $user): array
    {
        return self::known($user) && $this->userHasKeys($user) ? $this->keys->userEntries($user) : [];
    }

    private function newest(string $label): string
    {
        [$kind, $rest] = explode(':', $label, 2);
        $newest = '';

        if ('ch' === $kind && 1 === preg_match('/^(.+):\d{1,9}$/', $rest, $m)) {
            $newest = $this->keys->roomLabel($m[1]);
        } elseif (isset($this->kinds[$kind]) && null !== $this->kinds[$kind][1]) {
            $newest = (string) ($this->kinds[$kind][1])($rest, $label);
        }

        return Keys::validLabel($newest) && $newest !== $label && str_starts_with($newest, $kind.':') ? $newest : '';
    }

    private function sealing(): bool
    {
        return false !== ($this->options['seal'] ?? true);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array{token: string, exp: int}
     */
    private function signCalls(array $claims): array
    {
        $now = $this->now();
        $exp = $now + self::clamp((int) ($this->options['call_token_ttl'] ?? self::CALL_TTL), 60, self::CALL_TTL);

        return ['token' => self::jwt(['iss' => $this->site, 'iat' => $now, 'exp' => $exp] + $claims, hash_hmac('sha256', self::CALLS_LABEL, $this->secret, true)), 'exp' => $exp];
    }

    /**
     * Easy Stream's grant for a live stream, as its rule answers for this user: their role, name and picture, chat and
     * what they may send, with the stream's settings. Denied `bad_request` for a name that isn't a stream's, `forbidden`
     * where the rule says no, `no_user` where it gives a visitor more than a viewer's place.
     *
     * @return array<string, mixed>
     */
    private function authorizeStream(mixed $name, int|string|null $user): array
    {
        if (!is_string($name) || 1 !== preg_match(self::STREAM_NAME, $name)) {
            return ['denied' => 'bad_request'];
        }

        $found = $this->streamRules->match($name);

        if (null === $found) {
            return ['denied' => 'forbidden'];
        }

        $signedIn = self::known($user);
        $answer = ($found['value']['rule'])($signedIn ? $user : null, $found['params'], $name);

        if (is_string($answer)) {
            $answer = ['role' => $answer];
        }

        if (!is_array($answer) || !in_array($answer['role'] ?? null, ['host', 'guest', 'viewer'], true)) {
            return ['denied' => 'forbidden'];
        }

        $sub = $signedIn ? (string) $user : null;

        if (null === $sub ? 'viewer' !== $answer['role'] : 1 !== preg_match(self::STREAM_USER, $sub)) {
            return ['denied' => 'no_user'];
        }

        $info = $answer['info'] ?? $this->defaultInfo($user);
        $named = is_array($info) && isset($info['name']) && is_string($info['name']) && '' !== $info['name'] ? mb_substr($info['name'], 0, 64) : null;
        $avatar = is_array($info) && isset($info['avatar']) && is_string($info['avatar']) && strlen($info['avatar']) <= 512 && str_starts_with($info['avatar'], 'https://') ? $info['avatar'] : null;

        $params = $found['params'];
        $settings = $found['value']['settings'];
        $setting = static fn (mixed $value): mixed => is_callable($value) ? $value($params) : $value;
        $chat = $setting($settings['chat'] ?? null) ?? [];

        $now = $this->now();
        $exp = $now + self::STREAM_TTL;

        $claims = [
            'iss' => $this->site, 'aud' => self::GRANT_AUDIENCE, 'str' => $name, 'role' => $answer['role'], 'sub' => $sub,
            'name' => $named, 'avatar' => $avatar, 'chat' => $answer['chat'] ?? null, 'mod' => $answer['mod'] ?? null,
            'src' => $answer['src'] ?? null, 'q' => $answer['quality'] ?? null,
            'stage' => $setting($settings['stage'] ?? null), 'offline' => $chat['offline'] ?? null, 'links' => $chat['links'] ?? null,
            'fresh' => $chat['fresh'] ?? null, 'people' => $setting($settings['people'] ?? null),
            'reactions' => $setting($settings['reactions'] ?? null), 'iat' => $now, 'exp' => $exp,
        ];

        return ['token' => self::streamGrant($claims, hash_hmac('sha256', self::GRANT_KEY_LABEL, $this->secret, true)), 'exp' => $exp];
    }

    /**
     * Easy Stream's grant: an HS256 JWT of the claims in the protocol's order (CLAIM_ORDER), none left null, so every
     * language signs the same claims to the same token (spec/stream-grants.json, Easy Stream's own vectors).
     *
     * @param array<string, mixed> $claims
     */
    public static function streamGrant(array $claims, string $key): string
    {
        $ordered = [];

        foreach (self::CLAIM_ORDER as $name) {
            if (isset($claims[$name])) {
                $ordered[$name] = $claims[$name];
            }
        }

        return self::jwt($ordered, $key);
    }

    /** A call's or a group call's info, sealed with the day's key: everyone the app knows opens it. */
    private function sealedCallInfo(mixed $info): mixed
    {
        if (null === $info || !$this->sealing()) {
            return $info;
        }

        return $this->keys->sealJson($info, $this->keys->dayLabel()) ?? $info;
    }

    /**
     * A request to the realtime server, signed as the app: an HMAC-SHA256 over
     * "{timestamp}\n{METHOD}\n{path with query}\n{sha256 of the body}", with the publish key derived from its secret.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function signed(string $method, string $path, ?array $body = null): array
    {
        $api = rtrim((string) ($this->options['api'] ?? self::API_SERVER), '/');
        $url = $api.$path;
        $json = null === $body ? '' : Json::encode($body);
        $signedPath = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        if (is_string($query) && '' !== $query) {
            $signedPath .= '?'.$query;
        }

        $timestamp = (string) $this->now();
        $key = hash_hmac('sha256', self::PUBLISH_LABEL, $this->secret, true);
        $headers = [
            'Content-Type' => 'application/json',
            'X-WordPlus-Site' => $this->site,
            'X-WordPlus-Timestamp' => $timestamp,
            'X-WordPlus-Signature' => hash_hmac('sha256', $timestamp."\n".$method."\n".$signedPath."\n".hash('sha256', $json), $key),
        ];
        $response = ($this->options['http'] ?? self::http(...))($method, $url, $headers, null === $body ? null : $json);
        $decoded = json_decode((string) $response['body'], true);

        if (200 !== (int) $response['status'] || !is_array($decoded) || empty($decoded['ok'])) {
            throw new RealtimeError(is_array($decoded) && !empty($decoded['error']) ? (string) $decoded['error'] : 'http_'.(int) $response['status'], (int) $response['status']);
        }

        return $decoded;
    }

    /**
     * A request with curl, or PHP's streams where curl is missing.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    private static function http(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = array_map(static fn ($name, $value) => $name.': '.$value, array_keys($headers), $headers);

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);

            if (null !== $body) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }

            $answer = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            if (false === $answer) {
                throw new RealtimeError('unreachable');
            }

            return ['status' => $status, 'body' => (string) $answer];
        }

        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body ?? '', 'timeout' => 5, 'ignore_errors' => true]]);
        $answer = @file_get_contents($url, false, $context);

        if (false === $answer) {
            throw new RealtimeError('unreachable');
        }

        $status = isset($http_response_header[0]) && 1 === preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;

        return ['status' => $status, 'body' => $answer];
    }

    /** @return array<string, string> the current request's headers, as PHP's server gives them */
    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return (array) getallheaders();
        }

        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (str_starts_with((string) $name, 'HTTP_')) {
                $headers[str_replace('_', '-', substr((string) $name, 5))] = (string) $value;
            }
        }

        return $headers;
    }

    private function tokenKey(): string
    {
        return hash_hmac('sha256', self::TOKEN_LABEL, $this->secret, true);
    }

    /** Unix seconds: the clock the `now` option gives, for tests, or the system's. */
    private function now(): int
    {
        return isset($this->options['now']) ? (int) ($this->options['now'])() : time();
    }

    /**
     * A room's options as its rule gave them, with their defaults and the cloud's limits.
     *
     * @param array<string, mixed> $options
     *
     * @return array{presence: bool, count: bool, public: bool, seal: bool, history: array{last: int, days: float}|null, emit: list<string>|string, webhook: list<string>|string}
     */
    private static function normalized(array $options): array
    {
        $history = null;
        $given = $options['history'] ?? null;

        if (is_int($given) && $given > 0) {
            $history = ['last' => min($given, self::MAX_HISTORY), 'days' => self::MAX_HISTORY_DAYS];
        } elseif (is_array($given) && (int) ($given['last'] ?? 0) > 0) {
            $days = isset($given['days']) && is_numeric($given['days']) && (float) $given['days'] > 0 ? min((float) $given['days'], self::MAX_HISTORY_DAYS) : self::MAX_HISTORY_DAYS;
            $history = ['last' => min((int) $given['last'], self::MAX_HISTORY), 'days' => $days];
        }

        $names = static fn (mixed $list): array|string => '*' === $list ? '*' : (is_array($list) ? array_values(array_map('strval', $list)) : []);

        return [
            'presence' => !empty($options['presence']),
            'count' => !empty($options['count']) || !empty($options['presence']),
            'public' => !empty($options['public']),
            'seal' => (bool) ($options['seal'] ?? empty($options['public'])),
            'history' => $history,
            'emit' => $names($options['emit'] ?? []),
            'webhook' => $names($options['webhook'] ?? []),
        ];
    }

    /**
     * A room's options as a token carries them: `p`, `c`, `h`, `e`, `w`, `ro`, only those set.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function short(array $options, bool $readOnly): array
    {
        $out = [];

        if ($options['presence']) {
            $out['p'] = 1;
        }

        if ($options['count'] && !$options['presence']) {
            $out['c'] = 1;
        }

        if (null !== $options['history']) {
            $out['h'] = [$options['history']['last'], $options['history']['days']];
        }

        if ('*' === $options['emit'] || [] !== $options['emit']) {
            $out['e'] = $options['emit'];
        }

        if ('*' === $options['webhook'] || [] !== $options['webhook']) {
            $out['w'] = $options['webhook'];
        }

        if ($readOnly) {
            $out['ro'] = 1;
        }

        return $out;
    }

    /** @param array<string, mixed> $claims */
    private static function jwt(array $claims, string $key): string
    {
        $input = Keys::base64url('{"alg":"HS256","typ":"JWT"}').'.'.Keys::base64url(Json::encode($claims));

        return $input.'.'.Keys::base64url(hash_hmac('sha256', $input, $key, true));
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /** A user the app knows: any id but null, '' and 0. */
    private static function known(int|string|null $user): bool
    {
        return null !== $user && '' !== $user && 0 !== $user && '0' !== $user;
    }

    /** An attempt at a call, as a page names it. */
    private static function validAttempt(mixed $id): bool
    {
        return is_string($id) && 1 === preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id);
    }

    /**
     * A call's two people as a page names them: two different users, or null.
     *
     * @return array{0: int|string, 1: int|string}|null
     */
    private static function pairOf(mixed $pair): ?array
    {
        if (!is_array($pair) || 2 !== count($pair)) {
            return null;
        }

        [$a, $b] = array_map(self::callUser(...), array_values($pair));

        return null !== $a && null !== $b && $a !== $b ? [$a, $b] : null;
    }

    /**
     * A user as the realtime server's calls take them (its inc/calls/p2p/users.ts): a number for an id of digits, else the
     * id of text the app gives, up to 64 characters with no space or control character. 0 is nobody.
     */
    private static function callUser(mixed $user): int|string|null
    {
        if (is_int($user)) {
            return 0 !== $user && abs($user) <= self::MAX_SAFE_INTEGER ? $user : null;
        }

        if (!is_string($user) || 1 !== preg_match('/^[^\s\x00-\x1f\x7f]{1,64}$/u', $user)) {
            return null;
        }

        if (1 === preg_match('/^-?[1-9][0-9]*$/', $user) && strlen(ltrim($user, '-')) <= 16 && abs((int) $user) <= self::MAX_SAFE_INTEGER) {
            return (int) $user;
        }

        return '0' === $user ? null : $user;
    }

    /**
     * A user in what this server joins with `|` to make a key or a proof: a number as its digits, as always, and an id of
     * text with its `%` and `|` escaped, so no two pairs of people make the same string, as the Node and Python packages do.
     */
    private static function idPart(int|string $user): string
    {
        return is_int($user) ? (string) $user : strtr($user, ['%' => '%25', '|' => '%7C']);
    }
}
