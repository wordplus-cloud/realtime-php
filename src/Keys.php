<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/**
 * The keys that seal what an app sends through the realtime server, so WordPlus Cloud carries it without being able to
 * read or change it: the WordPress package's WordPlus_Realtime_Keys, label for label, derived from the app's local key
 * in place of WordPress's master key.
 *
 * - `d:{day}` the day's key (UTC days since 1970), for what everyone the app knows may see: the directory's profiles, a
 *   call's and a room's people.
 * - `u:{id}:{generation}` a user's own, for what goes to them alone.
 * - `ch:{room}:{generation}` a sealed room's, for its events and its members' info (the label's kind kept from protocol
 *   2's channels).
 * - an app's own kinds (Realtime::kind()).
 *
 * A key is `HMAC-SHA256(local key, "wordplus-realtime key v1|" . label)`, and a sealed value
 * `{label}:1.{base64url(nonce . ciphertext)}`, XChaCha20-Poly1305 with the label as associated data.
 */
final class Keys
{
    public const DERIVE = 'wordplus-realtime key v1|';

    /** A room's media key's derivation, and a call's proofs', apart from the labels' so no label names one. */
    public const MEDIA = 'wordplus-realtime media v1|';

    public const PROOF = 'wordplus-realtime proof v1|';

    public const FORMAT = '1';

    public const NONCE_BYTES = 24;

    /** A label: its kind, then up to 240 of the characters room names take. */
    public const LABEL = '[a-z][a-z0-9]{0,15}:[A-Za-z0-9_\-=@,.;:]{1,240}';

    public const RESERVED = ['d', 'u', 'ch'];

    public const MAX_LABELS = 100;

    /** The characters of an id a label carries as they are: those of room names, but `=`, which escapes the others. */
    private const LABEL_SAFE = '/^[A-Za-z0-9_\-@,.;:]$/';

    /** @var array<string, string> */
    private array $derived = [];

    public function __construct(
        private readonly string $master,
        private readonly ?GenerationStore $store = null,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $master)) {
            throw new \InvalidArgumentException('wordplus-realtime: the local key must be 64 hex characters (openssl rand -hex 32)');
        }
    }

    public static function validLabel(mixed $label): bool
    {
        return is_string($label) && strlen($label) <= 256 && 1 === preg_match('/^'.self::LABEL.'$/', $label);
    }

    /** The label of the key that opens a sealed value, or null when it isn't one. */
    public static function labelOf(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('/^('.self::LABEL.'):'.self::FORMAT.'\.[A-Za-z0-9_-]+$/', $value, $m) ? $m[1] : null;
    }

    /** Today, in UTC days since 1970, or the day of a unix time. */
    public static function day(?int $time = null): int
    {
        return intdiv($time ?? time(), 86400);
    }

    /**
     * The key a room's media is encrypted with end to end, base64url: `call|{call}|{call id}|{caller}|{callee}` for a
     * call's rooms, `room|{room}` for a group call's room whose prefix encrypts it. Only the calls route gives it out.
     */
    public function media(string $what): string
    {
        return self::base64url(hash_hmac('sha256', self::MEDIA.$what, $this->master, true));
    }

    /** A proof only this app's server makes, base64url: a user is in a call, or was rung into it (`member|…`, `rung|…`). */
    public function proof(string $what): string
    {
        return self::base64url(hash_hmac('sha256', self::PROOF.$what, $this->master, true));
    }

    /** The key a label names, 32 bytes; also the profile hash's, under a name no label takes. */
    public function key(string $label): string
    {
        if (count($this->derived) > 10000) {
            $this->derived = [];
        }

        return $this->derived[$label] ??= hash_hmac('sha256', self::DERIVE.$label, $this->master, true);
    }

    /** A label and its key as a browser keeps them, `{label}:{64 hex}`, or '' for a label that isn't one. */
    public function entry(string $label): string
    {
        return self::validLabel($label) ? $label.':'.bin2hex($this->key($label)) : '';
    }

    public function seal(string $value, string $label, ?string $nonce = null): ?string
    {
        if (!self::validLabel($label)) {
            return null;
        }

        $nonce ??= random_bytes(self::NONCE_BYTES);
        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, $label, $nonce, $this->key($label));

        return $label.':'.self::FORMAT.'.'.self::base64url($nonce.$sealed);
    }

    /** Data as JSON, sealed: what the browser opens and parses. */
    public function sealJson(mixed $data, string $label): ?string
    {
        return $this->seal(Json::encode($data), $label);
    }

    /** The plain value of one this app sealed, or null: not a sealed value, another app's, or changed on the way. */
    public function open(mixed $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^('.self::LABEL.'):'.self::FORMAT.'\.([A-Za-z0-9_-]+)$/', $value, $m)) {
            return null;
        }

        $bytes = base64_decode(strtr($m[2], '-_', '+/'), true);

        if (false === $bytes || strlen($bytes) < self::NONCE_BYTES + 16) {
            return null;
        }

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes, self::NONCE_BYTES), $m[1], substr($bytes, 0, self::NONCE_BYTES), $this->key($m[1]));
        } catch (\Throwable) {
            return null;
        }

        return false === $plain ? null : $plain;
    }

    public function dayLabel(?int $day = null): string
    {
        return 'd:'.($day ?? self::day());
    }

    public function userGeneration(int|string $user): int
    {
        return max(1, (int) ($this->store?->get('u:'.$user) ?? 1));
    }

    /** A user's own key, its newest; '' for nobody or an id labels can't carry. */
    public function userLabel(int|string|null $user): string
    {
        $part = null === $user || 0 === $user ? null : self::labelUser($user);

        return null === $part ? '' : 'u:'.$part.':'.$this->userGeneration($user);
    }

    /**
     * A user id as a label carries it (ids such as Auth0's `auth0|5f7c…`): as it is when every character is one labels
     * take, else each other character, `=` among them, as `=` and the hex of its UTF-8 bytes (`auth0=7C5f7c`), so no two
     * ids share a label. Null for an id of more than 64 characters, or with a space or a control character. The Node and
     * Python packages write the same.
     */
    public static function labelUser(int|string $user): ?string
    {
        $id = (string) $user;
        $chars = '' === $id ? [] : preg_split('//u', $id, -1, PREG_SPLIT_NO_EMPTY);

        if (false === $chars || [] === $chars || count($chars) > 64 || 1 === preg_match('/[\s\x00-\x1f\x7f]/u', $id)) {
            return null;
        }

        $out = '';

        foreach ($chars as $char) {
            $out .= 1 === preg_match(self::LABEL_SAFE, $char) ? $char : implode('', array_map(static fn (string $byte): string => sprintf('=%02X', ord($byte)), str_split($char)));
        }

        return strlen($out) <= 200 ? $out : null;
    }

    /** The user's next key, which the one they had doesn't open: when their access is revoked. */
    public function rotateUser(int|string $user): void
    {
        $this->needStore()->set('u:'.$user, $this->userGeneration($user) + 1);
    }

    public function roomGeneration(string $room): int
    {
        return max(1, (int) ($this->store?->get('ch:'.$room) ?? 1));
    }

    /** A room's key, its newest; '' for a name that isn't one. Whether a room is sealed is its rule's to say. */
    public function roomLabel(string $room): string
    {
        if (!Patterns::validRoom($room)) {
            return '';
        }

        return 'ch:'.$room.':'.$this->roomGeneration($room);
    }

    /** A room's next key, when someone lost access to it: what it carries from now on is sealed from them. */
    public function rotateRoom(string $room): void
    {
        if (Patterns::validRoom($room)) {
            $this->needStore()->set('ch:'.$room, $this->roomGeneration($room) + 1);
        }
    }

    /**
     * The keys a user starts with: their own, and the day's from yesterday's to tomorrow's. None for nobody.
     *
     * @return list<string>
     */
    public function userEntries(int|string|null $user): array
    {
        $own = $this->userLabel($user);

        if ('' === $own) {
            return [];
        }

        $today = self::day();

        return [$this->entry($own), $this->entry($this->dayLabel($today - 1)), $this->entry($this->dayLabel($today)), $this->entry($this->dayLabel($today + 1))];
    }

    /**
     * A user's entry in the directory: `pd`, the profile with every text sealed with the day's key however deep (numbers,
     * true and false as they are), and `pdh`, an HMAC of the plain profile under a key derived from the local key.
     *
     * @param array<string, mixed> $plain
     *
     * @return array{pd: array<string, mixed>, pdh: string}
     */
    public function profile(int|string $user, array $plain, bool $seal = true): array
    {
        $plain['user_id'] = $user;
        $label = $this->dayLabel();
        $pd = [];

        foreach ($plain as $field => $value) {
            // The id stays as it is, a number or a string: the server keys the entry by it.
            if ('user_id' === $field) {
                $pd['user_id'] = $user;

                continue;
            }

            $kept = $this->sealedValue($value, $label, $seal, 0);

            if (null !== $kept || null === $value) {
                $pd[$field] = $kept;
            }
        }

        return ['pd' => $pd, 'pdh' => hash_hmac('sha256', Json::encode($plain), $this->key('profile-hash'))];
    }

    private function sealedValue(mixed $value, string $label, bool $seal, int $depth): mixed
    {
        if (is_string($value)) {
            return $seal && '' !== $value ? $this->seal($value, $label) : $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
            return $value;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value) || $depth >= 8) {
            return null;
        }

        $sealed = [];

        foreach ($value as $key => $item) {
            $sealed[$key] = $this->sealedValue($item, $label, $seal, $depth + 1);
        }

        return $sealed;
    }

    private function needStore(): GenerationStore
    {
        return $this->store ?? throw new \LogicException('wordplus-realtime: rotating a key needs a generation store (the `generations` option)');
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
