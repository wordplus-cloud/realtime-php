<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Tests;

use PHPUnit\Framework\TestCase;
use WordPlus\Realtime\GenerationStore;
use WordPlus\Realtime\Keys;
use WordPlus\Realtime\Patterns;
use WordPlus\Realtime\Realtime;
use WordPlus\Realtime\RealtimeError;

/**
 * The package against the conformance vectors (spec/vectors.json, @wordplus/realtime's scripts/vectors.php), and as the
 * browser uses its routes: what @wordplus/realtime's server part answers, case for case with its test/server.test.ts.
 */
final class RealtimeTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $v;

    /** @var list<array{method: string, url: string, path: string, headers: array<string, string>, body: mixed, raw: ?string}> */
    private array $sent = [];

    public static function setUpBeforeClass(): void
    {
        self::$v = json_decode((string) file_get_contents(__DIR__.'/../spec/vectors.json'), true);
    }

    /**
     * An app whose requests to the realtime server are recorded and answered by `$answer( $path )`.
     *
     * @param array<string, mixed> $options
     */
    private function app(array $options = [], ?callable $answer = null): Realtime
    {
        return new Realtime(array_filter($options + [
            'site' => self::$v['site'],
            'secret' => self::$v['secret'],
            'local_key' => self::$v['local_key'],
            'api' => 'https://rest.example.test',
            'profile' => static fn ($user) => ['name' => 'User '.$user, 'avatar' => 'https://example.com/'.$user.'.png'],
            'http' => function (string $method, string $url, array $headers, ?string $body) use ($answer) {
                $path = substr($url, strlen('https://rest.example.test'));
                $this->sent[] = ['method' => $method, 'url' => $url, 'path' => $path, 'headers' => $headers, 'body' => null === $body ? null : json_decode($body, true), 'raw' => $body];

                return ['status' => 200, 'body' => (string) json_encode(null === $answer ? ['ok' => true] : $answer($path))];
            },
        ], static fn ($value) => null !== $value));
    }

    /** @return array<string, mixed> a token's claims, after checking its signature with the key it should be signed with */
    private function claims(string $token, string $label): array
    {
        [$header, $payload, $signature] = explode('.', $token);
        $this->assertSame(Keys::base64url(hash_hmac('sha256', $header.'.'.$payload, hash_hmac('sha256', $label, self::$v['secret'], true), true)), $signature);

        return json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
    }

    private function keys(): Keys
    {
        return new Keys(self::$v['local_key']);
    }

    private function store(): GenerationStore
    {
        return new class() implements GenerationStore {
            /** @var array<string, int> */
            public array $values = [];

            public function get(string $name): ?int
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, int $generation): void
            {
                $this->values[$name] = $generation;
            }
        };
    }

    public function test_the_vectors_keys_call_key_sealed_values_media_keys_and_proofs()
    {
        $keys = $this->keys();

        foreach (self::$v['keys'] as $case) {
            $this->assertSame($case['entry'], $keys->entry($case['label']));
        }

        $this->assertSame(self::$v['profile_hash_key'], bin2hex($keys->key('profile-hash')));
        $this->assertSame(self::$v['call_key']['key'], $this->app()->callKey(self::$v['call_key']['id'], self::$v['call_key']['caller'], self::$v['call_key']['callee']));

        foreach (self::$v['sealed'] as $case) {
            $this->assertSame($case['sealed'], $keys->seal($case['plain'], $case['label'], hex2bin($case['nonce_hex'])));
            $this->assertSame($case['plain'], $keys->open($case['sealed']));
        }

        $first = self::$v['sealed'][0];
        $this->assertNull($keys->open(str_replace($first['label'], 'ch:order:43:1', $first['sealed'])));
        $this->assertNull((new Keys(str_repeat('0', 64)))->open($first['sealed']));

        foreach (self::$v['media'] as $media) {
            $this->assertSame($media['key'], $keys->media($media['what']));
        }

        foreach (self::$v['proofs'] as $proof) {
            $this->assertSame($proof['proof'], $keys->proof($proof['what']));
        }
    }

    public function test_an_emit_is_signed_as_the_vectors_say()
    {
        $this->app()->to('orders')->emit('updated', ['status' => 'paid']);

        $sent = $this->sent[0];
        $this->assertSame(self::$v['emit']['path'], $sent['path']);
        $this->assertSame(self::$v['emit']['body'], $sent['raw']);
        $this->assertSame(self::$v['site'], $sent['headers']['X-WordPlus-Site']);

        $key = hash_hmac('sha256', 'wordplus-realtime publish v1', self::$v['secret'], true);
        $expected = hash_hmac('sha256', $sent['headers']['X-WordPlus-Timestamp']."\nPOST\n".self::$v['emit']['path']."\n".hash('sha256', (string) $sent['raw']), $key);
        $this->assertSame($expected, $sent['headers']['X-WordPlus-Signature']);
        $this->assertSame(self::$v['emit']['signature'], hash_hmac('sha256', self::$v['emit']['timestamp']."\nPOST\n".self::$v['emit']['path']."\n".hash('sha256', self::$v['emit']['body']), $key));
        $this->assertSame(self::$v['derived']['publish'], bin2hex($key));
    }

    public function test_a_local_key_must_be_64_hex_and_the_credentials_given()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app(['local_key' => 'short']);
    }

    public function test_patterns_match_a_rooms_parts_the_rule_with_most_of_its_own_characters_deciding()
    {
        $p = new Patterns();
        $p->add('chat:{a}:{b}', 'chat');
        $p->add('chat:{a}:support', 'support');
        $p->add('order:{id}', 'order');
        $p->add('order.{id}', 'laravel');
        $p->add('{any}', 'fallback');

        $this->assertSame(['pattern' => 'chat:{a}:{b}', 'value' => 'chat', 'params' => ['a' => '34', 'b' => '42']], $p->match('chat:34:42'));
        $this->assertSame('support', $p->match('chat:34:support')['value']);
        $this->assertSame(['id' => '42'], $p->match('order:42')['params']);
        $this->assertSame('laravel', $p->match('order.42')['value']);
        $this->assertNull($p->match('order:42:x'));
        $this->assertSame('fallback', $p->match('news')['value']);

        $this->expectExceptionMessageMatches('/not a pattern/');
        $p->add('bad pattern', 'x');
    }

    public function test_the_auth_route_signs_one_token_for_the_rooms_allowed_with_their_options_and_keys()
    {
        $realtime = $this->app();
        $realtime->room('order:{id}', static fn ($user, array $p) => 42 === $user && '42' === $p['id'], ['history' => 20]);
        $realtime->room('chat:{a}:{b}', static fn ($user, array $p) => in_array((string) $user, [$p['a'], $p['b']], true) ? ['info' => ['name' => 'Ben', 'role' => 'Customer']] : false,
            ['presence' => true, 'history' => ['last' => 500, 'days' => 7], 'emit' => ['message', 'typing'], 'webhook' => ['message']]);
        $realtime->room('news', null, ['public' => true, 'count' => true]);
        $realtime->room('board:{id}', static fn ($user) => 42 === $user ? ['readOnly' => true] : true, ['emit' => '*']);
        $keys = $this->keys();

        $grant = $realtime->authorize(['order:42', 'chat:34:42', 'news', 'board:1', 'order:43', 'nobody:1', 'bad room', 'order:42'], 42);
        $this->assertEquals((object) ['order:43' => 'forbidden', 'nobody:1' => 'no_rule', 'bad room' => 'bad_room'], $grant['denied']);

        $claims = $this->claims($grant['token'], 'wordplus-realtime channels v1');
        $this->assertSame(['iss' => self::$v['site'], 'aud' => 'wordplus-realtime', 'sub' => '42'], array_intersect_key($claims, ['iss' => 1, 'aud' => 1, 'sub' => 1]));
        $this->assertSame([
            'order:42' => ['h' => [20, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]],
            'chat:34:42' => ['p' => 1, 'h' => [500, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS], 'e' => ['message', 'typing'], 'w' => ['message']],
            'news' => ['c' => 1],
            'board:1' => ['e' => '*', 'ro' => 1],
        ], $claims['r']);
        $this->assertSame(21600, $claims['exp'] - $claims['iat']);
        $this->assertSame(['name' => 'Ben', 'role' => 'Customer'], json_decode((string) $keys->open($claims['infos']['chat:34:42']), true));

        // The sealed rooms' keys come with the token; a public room is not sealed.
        $this->assertSame([$keys->entry('ch:order:42:1'), $keys->entry('ch:chat:34:42:1'), $keys->entry('ch:board:1:1')], $grant['keys']);

        // The denied list is an object even when empty, as the browser reads it, and so is a room's options.
        $this->assertSame('{"denied":{}}', json_encode($realtime->authorize([], 7)));
        $this->assertStringContainsString('"r":{"news":{"c":1}}', (string) base64_decode(strtr(explode('.', $realtime->authorize(['news'], 7)['token'])[1], '-_', '+/')));
        $this->assertStringContainsString('"r":{"order:42":{"h":[20,' . json_encode(\WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS) . ']}}', (string) base64_decode(strtr(explode('.', $realtime->authorize(['order:42'], 42)['token'])[1], '-_', '+/')));
    }

    public function test_a_rooms_history_keeps_a_part_of_a_day_and_the_cloud_limit_when_days_is_missing_or_not_above_0()
    {
        $realtime = $this->app();
        $realtime->room('a:{id}', static fn () => true, ['history' => ['last' => 30, 'days' => 0.002]]);
        $realtime->room('b:{id}', static fn () => true, ['history' => ['last' => 30, 'days' => '0.002']]);
        $realtime->room('c:{id}', static fn () => true, ['history' => ['last' => 30]]);
        $realtime->room('d:{id}', static fn () => true, ['history' => ['last' => 30, 'days' => 0]]);
        $realtime->room('e:{id}', static fn () => true, ['history' => ['last' => 30, 'days' => 1]]);

        // The realtime server refuses a room whose history keeps 0 days, so `days` 0.002 must stay 0.002.
        $max = \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS;
        $this->assertSame([
            'a:1' => ['h' => [30, 0.002]],
            'b:1' => ['h' => [30, 0.002]],
            'c:1' => ['h' => [30, $max]],
            'd:1' => ['h' => [30, $max]],
            'e:1' => ['h' => [30, $max]],
        ], $this->claims($realtime->authorize(['a:1', 'b:1', 'c:1', 'd:1', 'e:1'], 42)['token'], 'wordplus-realtime channels v1')['r']);
    }

    public function test_nobody_gets_presence_a_public_room_needs_no_rule_and_info_stays_plain_when_sealing_is_off()
    {
        $realtime = $this->app(['seal' => false]);
        $realtime->room('lobby', static fn () => true, ['presence' => true]);
        $realtime->room('product:{id}', null, ['public' => true, 'count' => true]);

        $this->assertEquals((object) ['lobby' => 'forbidden'], $realtime->authorize(['lobby'], null)['denied']);
        $this->assertArrayNotHasKey('sub', $this->claims($realtime->authorize(['product:7'], null)['token'], 'wordplus-realtime channels v1'));

        $many = $realtime->authorize(array_map(static fn ($i) => 'product:'.$i, range(0, 100)), 7);
        $this->assertEquals((object) ['product:100' => 'too_many'], $many['denied']);

        $plain = $this->claims($realtime->authorize(['lobby'], 7)['token'], 'wordplus-realtime channels v1');
        $this->assertSame(['name' => 'User 7', 'avatar' => 'https://example.com/7.png'], $plain['info']);
        $this->assertArrayNotHasKey('infos', $plain);
        $this->assertArrayNotHasKey('keys', $realtime->authorize(['lobby'], 7));
    }

    public function test_a_room_needs_a_rule_and_a_public_room_has_no_members()
    {
        $realtime = $this->app();

        try {
            $realtime->room('order:{id}');
            $this->fail('A room without a rule was taken');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a rule', $e->getMessage());
        }

        $this->expectExceptionMessageMatches('/public room/');
        $realtime->room('lobby', null, ['public' => true, 'presence' => true]);
    }

    public function test_every_page_gets_a_grant_for_the_public_rooms_made_once_and_again_before_it_runs_out()
    {
        $now = 1791676800;
        $realtime = $this->app(['now' => static function () use (&$now) {
            return $now;
        }]);
        $realtime->room('product:{id}', null, ['public' => true, 'count' => true]);
        $realtime->room('scores', null, ['public' => true, 'history' => 10]);
        $realtime->room('order:{id}', static fn () => true);

        [$grant] = $realtime->publicGrants();
        $this->assertSame(['product:{id}', 'scores'], $grant['rooms']);
        $claims = $this->claims($grant['token'], 'wordplus-realtime channels v1');
        $this->assertSame(['product:{id}' => ['c' => 1], 'scores' => ['h' => [10, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]]], $claims['r']);
        $this->assertArrayNotHasKey('sub', $claims);
        $this->assertSame($grant['token'], $realtime->publicGrants()[0]['token']);
        $this->assertSame([$grant], $realtime->config(null)['grants']);

        $now += 19800;
        $this->assertNotSame($grant['token'], $realtime->publicGrants()[0]['token']);
        $this->assertSame([], $this->app()->publicGrants());
    }

    public function test_the_calls_route_signs_a_line_each_side_of_a_call_and_a_place_in_a_group_call()
    {
        $realtime = $this->app();
        $realtime->calls('support:{a}:{b}', static fn ($user, $with, array $p) => $p['a'] === (string) $user || $p['a'] === (string) $with);
        $realtime->groupCalls('meeting:{id}', static fn ($user) => ['admin' => 1 === $user, 'info' => ['name' => 'Agent']]);

        $this->assertSame(['aud' => 'wordplus-realtime line', 'sub' => 42], array_intersect_key($this->claims($realtime->authorizeCall(['line' => true], 42)['token'], 'wordplus-realtime calls v1'), ['aud' => 1, 'sub' => 1]));

        $caller = $realtime->authorizeCall(['call' => 'support:42:34', 'id' => 'abcDEF123_-xyz', 'role' => 'caller', 'to' => 34, 'type' => 'audio'], 42);
        $claims = $this->claims($caller['token'], 'wordplus-realtime calls v1');
        $this->assertSame(['call' => 'support:42:34', 'id' => 'abcDEF123_-xyz', 'sub' => 42, 'role' => 'caller', 'type' => 'audio', 'to' => 34], array_intersect_key($claims, array_flip(['call', 'id', 'sub', 'role', 'type', 'to'])));
        $this->assertSame(['name' => 'User 42', 'avatar' => 'https://example.com/42.png'], json_decode((string) $this->keys()->open($claims['info']), true));
        $this->assertSame(self::$v['call_key']['key'], $caller['key']);

        $callee = $realtime->authorizeCall(['call' => 'support:42:34', 'id' => 'abcDEF123_-xyz', 'role' => 'callee', 'from' => '42'], '34');
        $this->assertSame(self::$v['call_key']['key'], $callee['key']);

        $room = $this->claims($realtime->authorizeCall(['room' => 'meeting:daily'], 1)['token'], 'wordplus-realtime calls v1');
        $this->assertSame(['room' => 'meeting:daily', 'publish' => true, 'subscribe' => true, 'admin' => true, 'hidden' => false], array_intersect_key($room, array_flip(['room', 'publish', 'subscribe', 'admin', 'hidden'])));
        $this->assertStringStartsWith('d:'.Keys::day().':1.', $room['info']);
    }

    public function test_the_calls_rule_hears_which_side_asks_so_a_user_may_answer_calls_they_may_not_place()
    {
        // Pro members place calls, anyone answers them (an SDK review, 2026-10-14).
        $asked = [];
        $realtime = $this->app();
        $realtime->calls('dm:{a}:{b}', function ($user, $with, array $p, string $call, array $side) use (&$asked) {
            $asked[] = [$user, $side['role']];

            return 'callee' === $side['role'] || 'user_ann' === $user;
        });
        // A rule of four arguments, as before, still works.
        $realtime->calls('help:{id}', fn ($user, $with, array $p, string $call) => true);

        $this->assertArrayHasKey('token', $realtime->authorizeCall(['call' => 'dm:user_ann:user_cara', 'id' => 'abcdefgh', 'role' => 'caller', 'to' => 'user_cara'], 'user_ann'));
        $this->assertArrayHasKey('token', $realtime->authorizeCall(['call' => 'dm:user_ann:user_cara', 'id' => 'abcdefgh', 'role' => 'callee', 'from' => 'user_ann'], 'user_cara'));
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['call' => 'dm:user_ann:user_cara', 'id' => 'ijklmnop', 'role' => 'caller', 'to' => 'user_ann'], 'user_cara'));
        $this->assertSame([['user_ann', 'caller'], ['user_cara', 'callee'], ['user_cara', 'caller']], $asked);
        $this->assertArrayHasKey('token', $realtime->authorizeCall(['call' => 'help:1', 'id' => 'abcdefgh', 'role' => 'caller', 'to' => 2], 1));
    }

    public function test_the_calls_route_refuses_nobody_an_id_with_a_space_what_nobody_owns_and_what_isnt_a_request()
    {
        $realtime = $this->app();
        $realtime->calls('support:{id}', static fn () => true);

        $this->assertSame(['denied' => 'no_user'], $realtime->authorizeCall(['line' => true], null));
        $this->assertSame(['denied' => 'no_user'], @$realtime->authorizeCall(['line' => true], 'ann smith'));
        $this->assertSame(['denied' => 'no_user'], @$realtime->authorizeCall(['line' => true], str_repeat('a', 65)));
        // An id of text is a user like any other, and one of digits a number.
        $this->assertSame('user_2abc', $this->claims($realtime->authorizeCall(['line' => true], 'user_2abc')['token'], 'wordplus-realtime calls v1')['sub']);
        $this->assertSame(42, $this->claims($realtime->authorizeCall(['line' => true], '42')['token'], 'wordplus-realtime calls v1')['sub']);
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['call' => 'sales:1', 'id' => 'abcdefgh', 'role' => 'caller', 'to' => 2], 1));
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['room' => 'meeting:1'], 1));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['call' => 'support:1', 'id' => 'short', 'role' => 'caller', 'to' => 2], 1));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['call' => 'support:1', 'id' => 'abcdefgh', 'role' => 'caller', 'to' => 1], 1));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['room' => 'bad room'], 1));
    }

    public function test_a_call_gives_both_sides_its_rooms_key_its_pair_and_each_ones_proof_of_being_in_it()
    {
        $realtime = $this->app();
        $realtime->calls('support-42', static fn () => true);

        $caller = $realtime->authorizeCall(['call' => 'support-42', 'id' => 'abcDEF123_-xyz', 'role' => 'caller', 'to' => 34], 42);
        $callee = $realtime->authorizeCall(['call' => 'support-42', 'id' => 'abcDEF123_-xyz', 'role' => 'callee', 'from' => 42], 34);

        $this->assertSame([self::$v['media'][0]['key'], self::$v['media'][0]['key']], [$caller['media_key'], $callee['media_key']]);
        $this->assertSame([[42, 34], [42, 34]], [$caller['pair'], $callee['pair']]);
        $this->assertSame(self::$v['proofs'][0]['proof'], $caller['member']);
        $this->assertNotSame($caller['member'], $callee['member']);
        $this->assertArrayNotHasKey('ring', $caller);
    }

    public function test_someone_in_a_call_rings_a_person_in_with_their_proof_and_the_person_answers_with_the_ring()
    {
        $realtime = $this->app();
        $realtime->calls('support-42', static fn () => true);
        $caller = $realtime->authorizeCall(['call' => 'support-42', 'id' => 'abcDEF123_-xyz', 'role' => 'caller', 'to' => 34], 42);
        $group = ['call' => 'support-42', 'group' => 'abcDEF123_-xyz', 'pair' => $caller['pair']];

        $ring = $realtime->authorizeCall($group + ['id' => 'ringCara0001', 'role' => 'caller', 'to' => 56, 'member' => $caller['member']], 42);
        $this->assertSame(['sub' => 42, 'to' => 56, 'group' => 'abcDEF123_-xyz'], array_intersect_key($this->claims($ring['token'], 'wordplus-realtime calls v1'), array_flip(['sub', 'to', 'group'])));
        $this->assertSame(self::$v['proofs'][1]['proof'], $ring['ring']);

        $answer = $realtime->authorizeCall($group + ['id' => 'ringCara0001', 'role' => 'callee', 'from' => 42, 'ring' => $ring['ring']], 56);
        $this->assertSame(['sub' => 56, 'group' => 'abcDEF123_-xyz'], array_intersect_key($this->claims($answer['token'], 'wordplus-realtime calls v1'), array_flip(['sub', 'group'])));
        $this->assertSame($caller['media_key'], $answer['media_key']);

        // Someone else's proof, none, another pair; another's ring; a pair that isn't two people; the ring's own id as the call.
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall($group + ['id' => 'ringCara0002', 'role' => 'caller', 'to' => 78, 'member' => $caller['member']], 34));
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall($group + ['id' => 'ringCara0002', 'role' => 'caller', 'to' => 78], 42));
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['pair' => [42, 35]] + $group + ['id' => 'ringCara0002', 'role' => 'caller', 'to' => 78, 'member' => $caller['member']], 42));
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall($group + ['id' => 'ringCara0001', 'role' => 'callee', 'from' => 42, 'ring' => $ring['ring']], 57));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['pair' => [42, 42]] + $group + ['id' => 'ringCara0001', 'role' => 'callee', 'from' => 42, 'ring' => $ring['ring']], 56));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['group' => 'ringCara0001'] + $group + ['id' => 'ringCara0001', 'role' => 'callee', 'from' => 42, 'ring' => $ring['ring']], 56));
    }

    public function test_calls_between_users_whose_ids_are_text_their_keys_and_proofs_never_the_same_for_two_pairs()
    {
        $realtime = $this->app();
        $realtime->calls('support-{id}', static fn () => true);

        // Ann, an id of Clerk, calls Ben, of Auth0, whose id holds the `|` keys are joined with: escaped there, as Node does.
        $caller = $realtime->authorizeCall(['call' => 'support-1', 'id' => 'abcDEF123_-xyz', 'role' => 'caller', 'to' => 'auth0|5f7c'], 'user_2abc');
        $this->assertSame(['sub' => 'user_2abc', 'to' => 'auth0|5f7c'], array_intersect_key($this->claims($caller['token'], 'wordplus-realtime calls v1'), array_flip(['sub', 'to'])));
        $this->assertSame(['user_2abc', 'auth0|5f7c'], $caller['pair']);
        $this->assertSame($this->keys()->media('call|support-1|abcDEF123_-xyz|user_2abc|auth0%7C5f7c'), $caller['media_key']);

        // Another pair that would join to the same string unescaped: another key, and the proof of one is not that of the other.
        $other = $realtime->authorizeCall(['call' => 'support-1', 'id' => 'abcDEF123_-xyz', 'role' => 'caller', 'to' => '5f7c'], 'user_2abc|auth0');
        $this->assertNotSame($caller['media_key'], $other['media_key']);
        $ring = ['call' => 'support-1', 'group' => 'abcDEF123_-xyz', 'id' => 'ringCara0001', 'role' => 'caller', 'to' => 'cara@shop.example'];
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall($ring + ['pair' => $other['pair'], 'member' => $caller['member']], 'user_2abc'));
        $rang = $realtime->authorizeCall($ring + ['pair' => $caller['pair'], 'member' => $caller['member']], 'user_2abc');
        $this->assertSame('cara@shop.example', $this->claims($rang['token'], 'wordplus-realtime calls v1')['to']);
    }

    public function test_the_calls_route_takes_what_the_host_of_a_group_call_does_and_nothing_from_anyone_else()
    {
        $realtime = $this->app([], static fn (string $path) => str_ends_with($path, '/permit') ? ['ok' => true, 'changed' => false] : ['ok' => true, 'muted' => true, 'removed' => true]);
        $realtime->groupCalls('meeting:{id}', static fn ($user) => 1 === $user ? ['admin' => true] : true);

        $this->assertSame(['done' => true], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'mute', 'user' => 42], 1));
        $this->assertSame(['done' => false], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'speak', 'user' => 'auth0|5f7c8e'], 1));
        $this->assertSame(['done' => false], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'listen', 'user' => 42], 1));
        $this->assertSame(['done' => true], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'remove', 'user' => '42'], 1));
        $this->assertSame([
            ['/wordplus/realtime/calls/meeting%3A7/mute', ['user' => '42', 'track' => 'microphone']],
            ['/wordplus/realtime/calls/meeting%3A7/permit', ['user' => 'auth0|5f7c8e', 'publish' => true]],
            ['/wordplus/realtime/calls/meeting%3A7/permit', ['user' => '42', 'publish' => false]],
            ['/wordplus/realtime/calls/meeting%3A7/remove', ['user' => '42']],
        ], array_map(static fn ($s) => [$s['path'], $s['body']], $this->sent));

        // Someone in it who isn't its host, a call no rule owns, an act or a person that isn't one, and the host themselves.
        $this->sent = [];
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'remove', 'user' => 1], 42));
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['moderate' => 'board:7', 'act' => 'remove', 'user' => 42], 1));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'end', 'user' => 42], 1));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'mute', 'user' => 'two words'], 1));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['moderate' => 'meeting:7', 'act' => 'mute', 'user' => 1], 1));
        $this->assertSame([], $this->sent);

        $failing = $this->app(['http' => static fn () => ['status' => 500, 'body' => '{"ok":false,"error":"server"}']]);
        $failing->groupCalls('meeting:{id}', static fn () => ['admin' => true]);
        $this->assertSame(['denied' => 'server'], $failing->authorizeCall(['moderate' => 'meeting:7', 'act' => 'mute', 'user' => 42], 1));
    }

    public function test_the_calls_route_answers_whether_a_user_may_call_each_person_for_the_call_buttons_of_a_list()
    {
        $profiles = 0;
        $realtime = $this->app(['profile' => static function ($user) use (&$profiles) {
            ++$profiles;

            return ['name' => 'Someone'];
        }]);
        $sides = [];
        $realtime->calls('support:{a}:{b}', static function ($user, $with, $params, $call, $side) use (&$sides) {
            $sides[] = $side['role'];

            return 56 !== $with;
        });

        $asks = [['call' => 'support:34:42', 'to' => 34], ['call' => 'support:42:56', 'to' => 56], ['call' => 'meeting:1', 'to' => 34], ['call' => 'support:42:42', 'to' => 42], ['call' => 'support:42:x', 'to' => 'two words'], 'junk'];
        $this->assertSame(['may' => [true, false, false, false, false, false]], $realtime->authorizeCall(['may' => $asks], 42));
        $this->assertSame(['caller', 'caller'], $sides);
        // No profile is looked up for an answer no call is made with.
        $this->assertSame(0, $profiles);
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['may' => array_fill(0, 101, ['call' => 'support:34:42', 'to' => 34])], 42));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['may' => 'support:34:42'], 42));
        $this->assertSame(['denied' => 'no_user'], $realtime->authorizeCall(['may' => []], null));
    }

    public function test_the_calls_route_lists_whom_a_user_may_add_and_encrypts_a_group_call_whose_rule_says_so()
    {
        $realtime = $this->app();
        $realtime->calls('support:{id}', static fn ($user, $with) => 34 === $with || 34 === $user, ['people' => static fn () => [34, 56, 42, 'two words', 0, 56]]);
        $realtime->groupCalls('board-{id}', static fn () => true, ['encrypt' => true]);
        $realtime->groupCalls('meeting:{id}', static fn () => true);

        $this->assertSame([['id' => 34, 'name' => 'User 34', 'avatar' => 'https://example.com/34.png']], $realtime->authorizeCall(['people' => 'support:42'], 42)['people']);
        $this->assertSame([56, 42], array_column($realtime->authorizeCall(['people' => 'support:42'], 34)['people'], 'id'));
        $this->assertSame(['people' => []], $realtime->authorizeCall(['people' => 'board-1'], 34));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['people' => 'bad name'], 34));

        $this->assertSame(self::$v['media'][1]['key'], $realtime->authorizeCall(['room' => 'board-7'], 1)['media_key']);
        $this->assertArrayNotHasKey('media_key', $realtime->authorizeCall(['room' => 'meeting:daily'], 34));
        $this->assertSame(['add' => true], $realtime->config()['screen']);
    }

    public function test_a_user_is_labelled_by_their_id_any_id_of_text_included_never_two_ids_the_same()
    {
        // As labels take them: the ids of digits, Clerk, UUIDs, emails without a plus. Each other character escaped, as Node does.
        $this->assertSame(['42', '-7', 'user_2abc', '0b6f1c9e-8f5d-4c38', 'ann@shop.example'], array_map(Keys::labelUser(...), [42, -7, 'user_2abc', '0b6f1c9e-8f5d-4c38', 'ann@shop.example']));
        $this->assertSame('auth0=7C5f7c', Keys::labelUser('auth0|5f7c'));
        $this->assertSame('a=3Db', Keys::labelUser('a=b'));
        $this->assertSame('a=3D7Cb', Keys::labelUser('a=7Cb'));
        $this->assertSame('ann=2Bnews@shop.example', Keys::labelUser('ann+news@shop.example'));
        $this->assertSame('Zo=C3=AB', Keys::labelUser('Zoë'));
        $this->assertSame([null, null, null], [Keys::labelUser('ann smith'), Keys::labelUser(str_repeat('a', 65)), Keys::labelUser('')]);

        // Such a user has their own key and an identity.
        $start = $this->app()->keysAnswer([], 'auth0|5f7c');
        $this->assertSame($this->keys()->entry('u:auth0=7C5f7c:1'), $start['keys'][0]);
        $this->assertSame('auth0|5f7c', $this->claims($start['identity']['token'], 'wordplus-realtime channels v1')['sub']);
    }

    public function test_the_keys_route_gives_a_users_keys_and_identity_and_the_keys_they_may_have()
    {
        $realtime = $this->app();
        $realtime->room('order:{id}', static fn ($user, array $p) => 42 === $user && '42' === $p['id']);
        $realtime->room('news', null, ['public' => true]);
        $realtime->kind('note', static fn ($rest, $user) => '7' === $rest && 42 === $user);
        $keys = $this->keys();
        $today = Keys::day();

        $start = $realtime->keysAnswer([], 42);
        $this->assertSame([$keys->entry('u:42:1'), $keys->entry('d:'.($today - 1)), $keys->entry('d:'.$today), $keys->entry('d:'.($today + 1))], $start['keys']);

        $identity = $this->claims($start['identity']['token'], 'wordplus-realtime channels v1');
        $this->assertSame(['aud' => 'wordplus-realtime identity', 'sub' => '42'], array_intersect_key($identity, ['aud' => 1, 'sub' => 1]));
        $this->assertSame(42, $identity['pd']['user_id']);
        $this->assertSame('User 42', $keys->open($identity['pd']['name']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $identity['pdh']);
        $this->assertArrayNotHasKey('hid', $identity);

        $asked = $realtime->keysAnswer(['d:'.($today + 1), 'd:'.($today + 2), 'u:42:1', 'u:43:1', 'ch:order:42:1', 'ch:order:42:2', 'ch:order:43:1', 'ch:news:1', 'note:7', 'note:8', 'nonsense'], 42);
        $this->assertSame([$keys->entry('d:'.($today + 1)), $keys->entry('u:42:1'), $keys->entry('ch:order:42:1'), $keys->entry('note:7')], $asked['keys']);

        $this->assertSame(['keys' => []], $realtime->keysAnswer([], null));
    }

    public function test_a_string_id_gets_its_keys_and_identity_and_a_hidden_user_is_marked()
    {
        $realtime = $this->app(['hidden' => static fn ($user) => 'k7f3q:ben' === $user]);
        $keys = $this->keys();

        $start = $realtime->keysAnswer([], 'k7f3q:ben');
        $this->assertSame($keys->entry('u:k7f3q:ben:1'), $start['keys'][0]);
        $identity = $this->claims($start['identity']['token'], 'wordplus-realtime channels v1');
        $this->assertSame('k7f3q:ben', $identity['pd']['user_id']);
        $this->assertSame('k7f3q:ben', $identity['sub']);
        $this->assertTrue($identity['hid']);
    }

    public function test_a_rooms_key_moves_on_and_the_newest_comes_beside_an_older_one()
    {
        $realtime = $this->app(['generations' => $this->store()]);
        $realtime->room('team:{id}', static fn () => true);
        $keys = $this->keys();

        $realtime->rotateRoom('team:1');
        $this->assertSame(['keys' => [$keys->entry('ch:team:1:1'), $keys->entry('ch:team:1:2')]], $realtime->keysAnswer(['ch:team:1:1'], 7));
        $this->assertSame([$keys->entry('ch:team:1:2')], $realtime->authorize(['team:1'], 7)['keys']);

        $realtime->rotateUser('user_2abc');
        $this->assertSame($keys->entry('u:user_2abc:2'), $realtime->keysAnswer([], 'user_2abc')['keys'][0]);

        $this->expectException(\LogicException::class);
        $this->app()->rotateRoom('team:1');
    }

    public function test_no_key_for_a_user_cut_off_and_no_identity_without_a_profile()
    {
        $this->assertSame(['keys' => []], $this->app(['has_keys' => static fn ($user) => 42 !== $user])->keysAnswer([], 42));
        $this->assertArrayNotHasKey('identity', $this->app(['profile' => static fn () => null])->keysAnswer([], 42));
    }

    public function test_an_emit_seals_a_sealed_rooms_data_a_copy_each_sends_the_others_together_and_says_which_keep_it()
    {
        $realtime = $this->app([], static fn (string $path) => str_ends_with($path, '/batch') ? ['ok' => true, 'ids' => [['order:42' => '1-0'], []]] : ['ok' => true, 'ids' => []]);
        $realtime->room('order:{id}', static fn () => true, ['history' => 20]);
        $realtime->room('news', null, ['public' => true, 'history' => 5]);
        $realtime->room('ticker', null, ['public' => true]);

        $ids = $realtime->to(['order:42', 'news', 'ticker'])->except('tab-1')->emit('updated', ['status' => 'paid']);
        $this->assertSame(['order:42' => '1-0'], $ids);
        $this->assertCount(1, $this->sent);
        $this->assertSame('/wordplus/realtime/emit/batch', $this->sent[0]['path']);
        [$sealed, $plain] = $this->sent[0]['body']['events'];
        $this->assertSame(['rooms' => ['order:42'], 'event' => 'updated', 'history' => ['order:42' => [20, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]], 'except' => 'tab-1'], array_diff_key($sealed, ['data' => 1]));
        $this->assertSame(['status' => 'paid'], json_decode((string) $this->keys()->open($sealed['data']), true));
        $this->assertSame(['rooms' => ['news', 'ticker'], 'event' => 'updated', 'data' => ['status' => 'paid'], 'history' => ['news' => [5, \WordPlus\Realtime\Realtime::MAX_HISTORY_DAYS]], 'except' => 'tab-1'], $plain);
    }

    public function test_an_emit_to_users_seals_each_with_their_own_key()
    {
        $realtime = $this->app();

        $realtime->toUser([42, 'k7f3q:ben'])->emit('notice', ['text' => 'Shipped']);
        $this->assertSame('/wordplus/realtime/emit/batch', $this->sent[0]['path']);
        [$first, $second] = $this->sent[0]['body']['events'];
        $this->assertSame(['42'], $first['users']);
        $this->assertSame(['text' => 'Shipped'], json_decode((string) $this->keys()->open($first['data']), true));
        $this->assertSame(['k7f3q:ben'], $second['users']);
        $this->assertStringStartsWith('u:k7f3q:ben:1:1.', $second['data']);

        $realtime->toUser(7)->emit('plain', 1, ['seal' => false]);
        $this->assertSame(['path' => '/wordplus/realtime/emit', 'body' => ['users' => ['7'], 'event' => 'plain', 'data' => 1]], array_intersect_key($this->sent[1], ['path' => 1, 'body' => 1]));
    }

    public function test_a_batch_sends_each_event_and_a_refusal_is_thrown_with_the_servers_reason()
    {
        $realtime = $this->app();
        $realtime->room('r{n}', null, ['public' => true]);
        $realtime->batch(array_map(static fn ($i) => ['rooms' => 'r'.$i, 'event' => 'e'], range(0, 2)));
        $this->assertSame(['/wordplus/realtime/emit', '/wordplus/realtime/emit', '/wordplus/realtime/emit'], array_column($this->sent, 'path'));

        $refusing = $this->app(['http' => static fn () => ['status' => 413, 'body' => '{"ok":false,"error":"too_large"}']]);

        try {
            $refusing->to('r1')->emit('e', 'x');
            $this->fail('No refusal');
        } catch (RealtimeError $e) {
            $this->assertSame('too_large', $e->errorCode);
            $this->assertSame(413, $e->status);
        }

        $this->expectException(RealtimeError::class);
        $refusing->to('bad room')->emit('e');
    }

    public function test_reading_a_rooms_count_members_and_history_opened_erasing_it_and_kicking_a_user_out()
    {
        $keys = $this->keys();
        $sealedInfo = $keys->sealJson(['name' => 'Ann'], 'ch:chat:1:1');
        $sealedData = $keys->sealJson(['text' => 'Hi'], 'ch:chat:1:1');
        $store = $this->store();
        $realtime = $this->app(['generations' => $store], static function (string $path) use ($sealedInfo, $sealedData) {
            if (str_contains($path, '/history') && !str_contains($path, 'id=')) {
                return ['ok' => true, 'events' => [['id' => '1-0', 'at' => 1, 'event' => 'message', 'data' => $sealedData, 'from' => '34']], 'more' => false];
            }

            if (str_ends_with($path, '?members=1')) {
                return ['ok' => true, 'count' => 1, 'members' => ['count' => 1, 'list' => [['id' => '34', 'info' => $sealedInfo]]]];
            }

            return ['ok' => true, 'count' => 3, 'deleted' => 1];
        });

        $this->assertSame(3, $realtime->in('chat:1')->count());
        $this->assertSame(['count' => 1, 'list' => [['id' => '34', 'info' => ['name' => 'Ann']]]], $realtime->in('chat:1')->members());
        $history = $realtime->in('chat:1')->history(['limit' => 10]);
        $this->assertSame(['id' => '1-0', 'at' => 1, 'event' => 'message', 'data' => ['text' => 'Hi'], 'from' => '34'], $history['events'][0]);
        $this->assertFalse($history['more']);
        $this->assertSame(1, $realtime->in('chat:1')->forget('1-0'));

        $realtime->in('chat:1')->kick(43);
        $this->assertSame([
            ['GET', '/wordplus/realtime/rooms/chat%3A1'],
            ['GET', '/wordplus/realtime/rooms/chat%3A1?members=1'],
            ['GET', '/wordplus/realtime/rooms/chat%3A1/history?limit=10'],
            ['DELETE', '/wordplus/realtime/rooms/chat%3A1/history?id=1-0'],
            ['POST', '/wordplus/realtime/rooms/chat%3A1/kick'],
        ], array_map(static fn ($s) => [$s['method'], $s['path']], $this->sent));
        $this->assertSame(['user' => '43'], $this->sent[4]['body']);
        $this->assertSame(2, $store->get('ch:chat:1'));
    }

    public function test_reading_who_is_online()
    {
        $realtime = $this->app([], static fn (string $path) => str_contains($path, 'ids=')
            ? ['ok' => true, 'count' => 2, 'online' => [['id' => '34', 'online' => true], ['id' => '42', 'online' => false, 'at' => 5]]]
            : ['ok' => true, 'count' => 2, 'ids' => ['34', '43'], 'next' => '0']);

        $this->assertSame([['id' => '34', 'online' => true], ['id' => '42', 'online' => false, 'at' => 5]], $realtime->online()->states([34, 42]));
        $this->assertTrue($realtime->online()->has(34));
        $this->assertSame(2, $realtime->online()->count());
        $this->assertSame(['ids' => ['34', '43'], 'next' => '0'], $realtime->online()->list());
        $this->assertSame('/wordplus/realtime/online?ids=34,42', $this->sent[0]['path']);
    }

    public function test_reaching_rooms_users_usage_the_webhook_group_calls_and_live_streams()
    {
        $reply = $this->keys()->sealJson(['ok' => 1], 'u:42:1');
        $realtime = $this->app([], static function (string $path) use ($reply) {
            return match (true) {
                str_starts_with($path, '/wordplus/realtime/rooms') => ['ok' => true, 'count' => 1, 'rooms' => [['name' => 'shop:1', 'count' => 2, 'connections' => 2]], 'next' => '1'],
                str_ends_with($path, '/ask') => ['ok' => true, 'replies' => [['data' => $reply, 'at' => 7]]],
                str_starts_with($path, '/wordplus/realtime/users/') => ['ok' => true, 'user' => ['id' => '42', 'online' => true, 'connections' => 2]],
                '/wordplus/realtime/usage' === $path => ['ok' => true, 'connections' => ['now' => 3, 'limit' => 1000], 'credits' => null],
                '/wordplus/realtime/webhooks' === $path => ['ok' => true, 'deliveries' => [['id' => 'b1', 'status' => 500, 'resendable' => true]]],
                str_starts_with($path, '/wordplus/realtime/webhooks/') => ['ok' => true, 'delivered' => true, 'status' => 204],
                '/wordplus/realtime/calls' === $path => ['ok' => true, 'calls' => [['name' => 'meeting:7', 'people' => 2, 'started_at' => 1]]],
                str_starts_with($path, '/wordplus/realtime/calls/') => ['ok' => true, 'call' => ['name' => 'meeting:7', 'people' => 1, 'started_at' => 1, 'list' => []], 'muted' => true, 'removed' => true, 'changed' => true],
                str_ends_with($path, '/chat?limit=5') => ['ok' => true, 'messages' => [['id' => '1-0', 'text' => 'hi']]],
                str_ends_with($path, '/chat') => ['ok' => true, 'message' => ['id' => '2-0', 'text' => 'Welcome']],
                default => ['ok' => true, 'stream' => ['name' => 'show:1', 'state' => 'live'], 'until' => 99, 'lifted' => true, 'deleted' => true],
            };
        });

        $this->assertSame(['count' => 1, 'rooms' => [['name' => 'shop:1', 'count' => 2, 'connections' => 2]], 'next' => '1'], $realtime->listRooms(['prefix' => 'shop:', 'limit' => 1]));
        $this->assertSame(['id' => '42', 'online' => true, 'connections' => 2], $realtime->user(42)->get());
        $realtime->user(42)->disconnect();
        $this->assertSame([['data' => ['ok' => 1], 'at' => 7]], $realtime->user(42)->ask('cart', ['why' => 'checkout'], ['timeout' => 5000]));
        $this->assertSame(['connections' => ['now' => 3, 'limit' => 1000], 'credits' => null], $realtime->usage());
        $this->assertTrue($realtime->webhooks()->deliveries()[0]['resendable']);
        $this->assertSame(['delivered' => true, 'status' => 204], $realtime->webhooks()->resend('b1'));
        $this->assertSame(['delivered' => true, 'status' => 204], $realtime->webhooks()->test());
        $this->assertSame([['name' => 'meeting:7', 'people' => 2, 'started_at' => 1]], $realtime->listCalls());
        $this->assertSame(1, $realtime->call('meeting:7')->get()['people']);
        $this->assertTrue($realtime->call('meeting:7')->mute(42, 'microphone'));
        $this->assertTrue($realtime->call('meeting:7')->permit(43, ['publish' => true]));
        $this->assertTrue($realtime->call('meeting:7')->remove(43));
        $realtime->call('meeting:7')->end();
        $this->assertSame('live', $realtime->stream('show:1')->get()['state']);
        $this->assertSame([['id' => '1-0', 'text' => 'hi']], $realtime->stream('show:1')->chat(5));
        $this->assertSame(['id' => '2-0', 'text' => 'Welcome'], $realtime->stream('show:1')->say('Welcome', 'Shop'));
        $this->assertTrue($realtime->stream('show:1')->deleteMessage('1-0'));
        $realtime->stream('show:1')->pin(null);
        $realtime->stream('show:1')->slow(10);
        $this->assertSame(99, $realtime->stream('show:1')->timeout(7, 600));
        $this->assertTrue($realtime->stream('show:1')->unban(7));

        $this->assertSame([
            ['GET', '/wordplus/realtime/rooms?prefix=shop%3A&limit=1'],
            ['GET', '/wordplus/realtime/users/42'],
            ['POST', '/wordplus/realtime/users/42/disconnect'],
            ['POST', '/wordplus/realtime/users/42/ask'],
            ['GET', '/wordplus/realtime/usage'],
            ['GET', '/wordplus/realtime/webhooks'],
            ['POST', '/wordplus/realtime/webhooks/b1/resend'],
            ['POST', '/wordplus/realtime/webhooks/test'],
            ['GET', '/wordplus/realtime/calls'],
            ['GET', '/wordplus/realtime/calls/meeting%3A7'],
            ['POST', '/wordplus/realtime/calls/meeting%3A7/mute'],
            ['POST', '/wordplus/realtime/calls/meeting%3A7/permit'],
            ['POST', '/wordplus/realtime/calls/meeting%3A7/remove'],
            ['POST', '/wordplus/realtime/calls/meeting%3A7/end'],
            ['GET', '/wordplus/realtime/streams/show%3A1'],
            ['GET', '/wordplus/realtime/streams/show%3A1/chat?limit=5'],
            ['POST', '/wordplus/realtime/streams/show%3A1/chat'],
            ['DELETE', '/wordplus/realtime/streams/show%3A1/chat/1-0'],
            ['POST', '/wordplus/realtime/streams/show%3A1/pin'],
            ['POST', '/wordplus/realtime/streams/show%3A1/slow'],
            ['POST', '/wordplus/realtime/streams/show%3A1/timeout'],
            ['POST', '/wordplus/realtime/streams/show%3A1/unban'],
        ], array_map(static fn (array $s) => [$s['method'], $s['path']], $this->sent));
        // The ask's data sealed with the user's key, which WordPlus Cloud doesn't hold.
        $this->assertSame('cart', $this->sent[3]['body']['event']);
        $this->assertSame(5000, $this->sent[3]['body']['timeout']);
        $this->assertStringStartsWith('u:42:1:1.', $this->sent[3]['body']['data']);
        $this->assertSame(['user' => '42', 'track' => 'microphone'], $this->sent[10]['body']);
        $this->assertSame(['user' => '43', 'publish' => true], $this->sent[11]['body']);
        $this->assertSame(['text' => 'Welcome', 'name' => 'Shop'], $this->sent[16]['body']);
        $this->assertSame(['id' => null], $this->sent[18]['body']);
        $this->assertSame(['user' => '7', 'seconds' => 600], $this->sent[20]['body']);
    }

    /** @return array<string, string> a webhook's headers, signed as the realtime server signs them */
    private function hookHeaders(string $body, ?string $timestamp = null): array
    {
        $timestamp ??= self::$v['webhook']['timestamp'];
        $key = hash_hmac('sha256', 'wordplus-realtime webhook v1', self::$v['secret'], true);

        return ['x-wordplus-site' => self::$v['site'], 'x-wordplus-timestamp' => $timestamp, 'x-wordplus-signature' => hash_hmac('sha256', $timestamp."\nPOST\n".self::$v['webhook']['path']."\n".hash('sha256', $body), $key)];
    }

    public function test_a_webhooks_signature_is_the_vectors()
    {
        $this->assertSame(self::$v['webhook']['signature'], $this->hookHeaders(self::$v['webhook']['body'])['x-wordplus-signature']);
    }

    public function test_a_webhook_hands_each_event_opened_to_its_handlers_and_answers_204()
    {
        $realtime = $this->app(['now' => static fn () => (int) self::$v['webhook']['timestamp']]);
        $seen = [];
        $realtime->on('room.occupied', static function (array $e) use (&$seen) {
            $seen[] = ['occupied', $e['room']];
        });
        $realtime->on('message', static function (array $e) use (&$seen) {
            $seen[] = ['message', $e['data'], $e['from']];
        });
        $realtime->on('stream.live', static function (array $e) use (&$seen) {
            $seen[] = ['live', $e['stream'], $e['started_at']];
        });
        $realtime->on('*', static function (array $e) use (&$seen) {
            $seen[] = ['any', $e['type']];
        });

        $body = (string) json_encode(['events' => [
            ['id' => 'a', 'at' => 1, 'type' => 'room.occupied', 'room' => 'order:42'],
            ['id' => 'b', 'at' => 2, 'type' => 'emit', 'room' => 'chat:1', 'event' => 'message', 'data' => $this->keys()->sealJson(['text' => 'Hi'], 'ch:chat:1:1'), 'from' => '34', 'event_id' => '1-0'],
            ['id' => 'c', 'at' => 3, 'type' => 'stream.live', 'stream' => 'show:mugs', 'started_at' => 3, 'ended_at' => null],
        ]]);
        $answer = $realtime->webhook($body, $this->hookHeaders($body), self::$v['webhook']['path']);
        $this->assertSame(204, $answer['status']);
        $this->assertSame([['occupied', 'order:42'], ['any', 'room.occupied'], ['message', ['text' => 'Hi'], '34'], ['any', 'emit'], ['live', 'show:mugs', 3], ['any', 'stream.live']], $seen);
    }

    public function test_a_webhook_hands_what_people_used_to_its_handlers_for_an_app_that_charges_by_it()
    {
        $realtime = $this->app(['now' => static fn () => (int) self::$v['webhook']['timestamp']]);
        $charged = [];
        $realtime->on('call.ended', static function (array $e) use (&$charged) {
            $charged[] = ['call', $e['call'], $e['from'], $e['seconds']];
        });
        $realtime->on('group.left', static function (array $e) use (&$charged) {
            $charged[] = ['group', $e['call'], $e['user'], $e['seconds']];
        });
        $realtime->on('stream.left', static function (array $e) use (&$charged) {
            $charged[] = ['stream', $e['stream'], $e['user'], $e['seconds']];
        });

        $body = (string) json_encode(['events' => [
            ['id' => 'call.ended:c1', 'at' => 1, 'type' => 'call.ended', 'call' => 'support:34:42', 'call_id' => 'c1', 'from' => 42, 'to' => 34, 'media' => 'video', 'started_at' => 1, 'answered_at' => 2, 'ended_at' => 125002, 'seconds' => 125, 'reason' => 'hangup'],
            ['id' => 'group:EV_1', 'at' => 2, 'type' => 'group.left', 'call' => 'team:7', 'user' => 'auth0|5f7c', 'media' => 'audio', 'joined_at' => 1, 'left_at' => 61001, 'seconds' => 61],
            ['id' => 's1', 'at' => 3, 'type' => 'stream.left', 'stream' => 'live:1', 'user' => 42, 'role' => 'viewer', 'watch' => 'w1', 'joined_at' => 1, 'left_at' => 30001, 'seconds' => 30],
        ]]);
        $this->assertSame(204, $realtime->webhook($body, $this->hookHeaders($body), self::$v['webhook']['path'])['status']);
        $this->assertSame([['call', 'support:34:42', 42, 125], ['group', 'team:7', 'auth0|5f7c', 61], ['stream', 'live:1', 42, 30]], $charged);
    }

    public function test_a_webhook_hands_the_binary_parts_of_an_event_as_their_bytes()
    {
        $realtime = $this->app(['now' => static fn () => (int) self::$v['webhook']['timestamp']]);
        $seen = [];
        $realtime->on('update', static function (array $e) use (&$seen) {
            $seen[] = $e['data'];
        });

        // As a page sends a Uint8Array: base64 in JSON, sealed with the room, and once plain in a room that isn't.
        $body = (string) json_encode(['events' => [
            ['id' => 'a', 'at' => 1, 'type' => 'emit', 'room' => 'doc:7', 'event' => 'update', 'data' => $this->keys()->sealJson(['bytes' => ['$b' => 'AQL6'], 'at' => [1, ['$b' => '_w']]], 'ch:doc:7:1'), 'from' => '34', 'event_id' => '1-0'],
            ['id' => 'b', 'at' => 2, 'type' => 'emit', 'room' => 'board', 'event' => 'update', 'data' => ['bytes' => ['$b' => 'AQL6'], 'note' => ['$b' => 1]], 'from' => '34', 'event_id' => '1-1'],
        ]]);
        $this->assertSame(204, $realtime->webhook($body, $this->hookHeaders($body), self::$v['webhook']['path'])['status']);
        $this->assertSame([['bytes' => "\x01\x02\xfa", 'at' => [1, "\xff"]], ['bytes' => "\x01\x02\xfa", 'note' => ['$b' => 1]]], $seen);
    }

    public function test_a_webhook_the_server_didnt_sign_a_stale_one_and_a_failing_handler_are_refused()
    {
        $now = (int) self::$v['webhook']['timestamp'];
        $realtime = $this->app(['now' => static function () use (&$now) {
            return $now;
        }]);
        $good = $this->hookHeaders(self::$v['webhook']['body']);
        $send = static fn (array $headers, ?string $body = null) => $realtime->webhook($body ?? self::$v['webhook']['body'], $headers, self::$v['webhook']['path']);

        $this->assertSame(401, $send(['x-wordplus-signature' => 'x'] + $good)['status']);
        $this->assertSame(401, $send(['x-wordplus-site' => 'other'] + $good)['status']);
        $this->assertSame(401, $send($good, self::$v['webhook']['body'].' ')['status']);
        $now += 400;
        $this->assertSame(['code' => 'stale_signature'], $send($good)['body']);
        $now -= 400;
        $this->assertSame(204, $send($good)['status']);

        $realtime->on('room.occupied', static function () {
            throw new \RuntimeException('down');
        });
        $this->assertSame(500, @$send($good)['status']);
    }

    public function test_the_routes_answer_json_posts_only()
    {
        $realtime = $this->app();
        $realtime->room('order:{id}', static fn ($user) => '42' === (string) $user);

        $answer = $realtime->answerRequest('POST', 'application/json; charset=utf-8', 'auth', '{"rooms":["order:1"]}', 42);
        $this->assertSame(200, $answer['status']);
        $this->assertArrayHasKey('token', $answer['body']);

        $this->assertSame(405, $realtime->answerRequest('GET', 'application/json', 'auth', '', 42)['status']);
        $this->assertSame(415, $realtime->answerRequest('POST', 'text/plain', 'auth', '{}', 42)['status']);
        $this->assertSame(400, $realtime->answerRequest('POST', 'application/json', 'auth', '{', 42)['status']);
        $this->assertSame(400, $realtime->answerRequest('POST', 'application/json', 'auth', '{"rooms":"x"}', 42)['status']);
        $this->assertSame(404, $realtime->answerRequest('POST', 'application/json', 'other', '{}', 42)['status']);
        $this->assertSame(['status' => 200, 'body' => ['denied' => 'no_user']], $realtime->answerRequest('POST', 'application/json', 'calls', '{"line":true}', 0));
    }

    public function test_the_browsers_settings_carry_a_users_keys_and_identity()
    {
        $config = $this->app(['base' => '/realtime/', 'worker' => '/realtime-worker.js', 'api' => null])->config(42, ['X-CSRF-TOKEN' => 't']);

        $this->assertSame('https://ws.wordplus.cloud', $config['server']);
        $this->assertSame('https://rest.wordplus.cloud', $config['api']);
        $this->assertSame(self::$v['site'], $config['site']);
        $this->assertSame('/realtime/calls', $config['calls']['endpoint']);
        $this->assertSame('/realtime/keys', $config['keys']['endpoint']);
        $this->assertSame('/websocket', $config['path']);
        $this->assertSame('/realtime-worker.js', $config['worker']);
        $this->assertSame('/realtime/auth', $config['auth']['endpoint']);
        $this->assertEquals((object) ['X-CSRF-TOKEN' => 't'], $config['auth']['headers']);
        $this->assertCount(4, $config['keys']['entries']);
        $this->assertArrayHasKey('identity', $config['keys']);
        $this->assertMatchesRegularExpression('#^/realtime/files/[0-9a-f]{12}/realtime-rooms\.min\.js$#', $config['rooms']);
        $this->assertArrayNotHasKey('grants', $config);

        $this->assertSame([], $this->app()->config(null)['keys']['entries']);
    }

    public function test_a_page_head_names_the_client_and_carries_the_settings_safe_in_a_script()
    {
        $realtime = $this->app(['profile' => static fn ($id) => ['name' => '</script><b>x']]);
        $head = $realtime->head(42, ['X-CSRF-TOKEN' => 't']);

        $this->assertStringStartsWith('<script type="importmap">{"imports":{"@wordplus/realtime":"'.$realtime->fileAt('realtime.js').'"}}</script>', $head);
        $settings = json_decode(substr($head, strpos($head, 'window.wordplusRealtimeConfig = ') + strlen('window.wordplusRealtimeConfig = '), -strlen(';</script>')), true);
        $this->assertSame(self::$v['site'], $settings['site']);
        $this->assertSame('t', $settings['auth']['headers']['X-CSRF-TOKEN']);
        $this->assertSame(2, substr_count($head, '</script>'));   // nothing in the settings closes a script

        $this->assertStringNotContainsString('importmap', $realtime->head(42, [], false));
        $this->assertStringNotContainsString('importmap', $this->app(['files' => false])->head(42));
    }

    public function test_the_browser_files_are_served_as_the_package_carries_them()
    {
        // From the routes, so a PHP app needs no Node and copies nothing (an SDK review, 2026-10-15).
        $realtime = $this->app();
        $at = $realtime->fileAt('realtime-worker.js');
        $this->assertMatchesRegularExpression('#^/api/realtime/files/[0-9a-f]{12}/realtime-worker\.js$#', $at);
        $version = explode('/', $at)[4];
        $config = $realtime->config(null);
        $this->assertSame([$at, str_replace('realtime-worker.js', 'realtime-rooms.min.js', $at)], [$config['worker'], $config['rooms']]);

        // The copies are the files their manifest names, so a copy left behind fails here.
        $manifest = json_decode((string) file_get_contents(Realtime::FILES_DIR.'/files.json'), true);
        $this->assertSame(['realtime.js', 'realtime-worker.js', 'realtime-rooms.min.js', 'realtime-rooms-e2ee.worker.js', 'realtime-ui.min.js', 'realtime-ui-streams.min.js'], array_keys($manifest['files']));
        foreach ($manifest['files'] as $name => $sha) {
            $this->assertSame($sha, hash_file('sha256', Realtime::FILES_DIR.'/'.$name), $name);
            $answer = $realtime->file('GET', $realtime->fileAt($name).'?x=1');
            $this->assertSame([200, 'text/javascript; charset=utf-8', 'public, max-age=31536000, immutable'], [$answer['status'], $answer['headers']['Content-Type'], $answer['headers']['Cache-Control']]);
            $this->assertSame(file_get_contents(Realtime::FILES_DIR.'/'.$name), $answer['body']);
        }

        // By hand with no version: checked again, and not sent again while the same. Another version: kept by nobody.
        $plain = $realtime->file('GET', '/api/realtime/files/realtime.js');
        $this->assertSame(['no-cache', '"'.$version.'"'], [$plain['headers']['Cache-Control'], $plain['headers']['ETag']]);
        $this->assertSame(304, $realtime->file('GET', '/api/realtime/files/realtime.js', '"'.$version.'"')['status']);
        $this->assertSame('no-store', $realtime->file('GET', '/api/realtime/files/0123456789ab/realtime.js')['headers']['Cache-Control']);
        $this->assertNull($realtime->file('HEAD', $at)['body']);

        $this->assertSame(404, $realtime->file('GET', '/api/realtime/files/'.$version.'/files.json')['status']);
        $this->assertSame(404, $realtime->file('GET', '/api/realtime/files/a/b/realtime.js')['status']);
        $this->assertNull($realtime->file('POST', $at));
        $this->assertNull($realtime->file('GET', '/api/realtime/auth'));

        // An app that serves them itself.
        $own = $this->app(['files' => false]);
        $this->assertNull($own->file('GET', $at));
        $this->assertArrayNotHasKey('worker', $own->config(null));
        $this->assertSame('/realtime-worker.js', $this->app(['worker' => '/realtime-worker.js'])->config(null)['worker']);
    }

    public function test_a_route_at_an_address_of_its_own_is_named_to_the_page_and_found_by_its_path_alone()
    {
        $realtime = $this->app(['routes' => ['auth' => '/permit/rooms', 'calls' => '/check-call/', 'keys' => 'https://app.example.com/my/keys']]);
        $config = $realtime->config(null);

        $this->assertSame(['/permit/rooms', '/check-call/', 'https://app.example.com/my/keys'], [$config['auth']['endpoint'], $config['calls']['endpoint'], $config['keys']['endpoint']]);
        $this->assertSame('/api/realtime/webhook', $realtime->address('webhook'));
        $this->assertSame('auth', $realtime->routeOf('/permit/rooms'));
        $this->assertSame('calls', $realtime->routeOf('/check-call?x=1'));
        $this->assertSame('keys', $realtime->routeOf('/my/keys/'));
        $this->assertSame('webhook', $realtime->routeOf('/api/realtime/webhook'));
        // Not at their usual addresses any more, and nothing else ending as a route does.
        $this->assertNull($realtime->routeOf('/api/realtime/calls'));
        $this->assertNull($realtime->routeOf('/blog/auth'));

        // Without addresses of their own, the last part, as before.
        $this->assertSame('calls', $this->app()->routeOf('/anywhere/calls'));
    }

    public function test_from_the_environment()
    {
        $_ENV['WORDPLUS_SITE'] = 's1';
        $_ENV['WORDPLUS_SECRET'] = 'x';
        $_ENV['WORDPLUS_LOCAL_KEY'] = self::$v['local_key'];

        try {
            $this->assertSame('s1', Realtime::fromEnvironment()->site());
        } finally {
            unset($_ENV['WORDPLUS_SITE'], $_ENV['WORDPLUS_SECRET'], $_ENV['WORDPLUS_LOCAL_KEY']);
        }
    }

    public function test_live_streams_sign_easy_streams_own_vectors()
    {
        $g = json_decode((string) file_get_contents(__DIR__.'/../spec/stream-grants.json'), true);
        $key = hash_hmac('sha256', 'easy-stream grant v1', $g['secret'], true);

        foreach ($g['cases'] as $case) {
            $this->assertSame($case['token'], Realtime::streamGrant($case['claims'] + ['aud' => 'easy-stream'], $key), $case['name']);
        }
    }

    public function test_the_calls_route_answers_a_streams_grant_as_its_rule_and_settings_say_a_visitor_as_a_viewer_only()
    {
        $realtime = $this->app();
        $realtime->streams('show:{id}', static function ($user, array $params) {
            if (1 === $user) {
                return ['role' => 'host', 'quality' => '720p'];
            }
            if (9 === $user) {
                return false;
            }

            return null === $user ? 'viewer' : ['role' => 'viewer', 'chat' => 'read', 'info' => ['name' => 'Ben', 'avatar' => 'http://not-https.example/b.png']];
        }, ['stage' => false, 'reactions' => true, 'chat' => static fn (array $p) => ['offline' => '42' === $p['id'] ? 'open' : 'closed', 'links' => false]]);

        $host = $realtime->authorizeCall(['stream' => 'show:42'], 1);
        $claims = $this->claims($host['token'], 'easy-stream grant v1');
        $this->assertSame(['iss', 'aud', 'str', 'role', 'sub', 'name', 'avatar', 'q', 'stage', 'offline', 'links', 'reactions', 'iat', 'exp'], array_keys($claims));
        $this->assertSame(['iss' => self::$v['site'], 'aud' => 'easy-stream', 'str' => 'show:42', 'role' => 'host', 'sub' => '1', 'name' => 'User 1', 'avatar' => 'https://example.com/1.png', 'q' => '720p', 'stage' => false, 'offline' => 'open', 'links' => false, 'reactions' => true], array_slice($claims, 0, 12));
        $this->assertSame(600, $claims['exp'] - $claims['iat']);
        $this->assertSame($claims['exp'], $host['exp']);

        $ben = $this->claims($realtime->authorizeCall(['stream' => 'show:7'], 34)['token'], 'easy-stream grant v1');
        $this->assertSame(['viewer', '34', 'Ben', 'read', 'closed'], [$ben['role'], $ben['sub'], $ben['name'], $ben['chat'], $ben['offline']]);
        $this->assertArrayNotHasKey('avatar', $ben);
        $visitor = $this->claims($realtime->authorizeCall(['stream' => 'show:7'], null)['token'], 'easy-stream grant v1');
        $this->assertSame('viewer', $visitor['role']);
        $this->assertArrayNotHasKey('sub', $visitor);

        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['stream' => 'show:7'], 9));
        $this->assertSame(['denied' => 'forbidden'], $realtime->authorizeCall(['stream' => 'talk:7'], 1));
        $this->assertSame(['denied' => 'bad_request'], $realtime->authorizeCall(['stream' => 'show_7'], 1));

        $realtime->streams('open:{id}', static fn () => 'host');
        $this->assertSame(['denied' => 'no_user'], $realtime->authorizeCall(['stream' => 'open:1'], null));
    }
}
