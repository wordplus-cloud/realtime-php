<?php

// The demo frame around the API demo (WORDPLUS_DEMO=1), as the demo sites run it (php.docs.wordplus.cloud and the
// others): a shop for each visitor, for 24 hours, signed in as Ann. Windows that are Ben or Cara (/as/<token>, the
// token then sent as a bearer, so each window of one browser is its own person), and what the page draws around the
// shop: the stacks, the guide, the source, "Try this". The example itself is the same with or without it.

declare(strict_types=1);

const DEMO_DAY = 24 * 3600;
/** New shops an address opens in an hour. Past them it gets the newest of them again. */
const DEMO_SHOPS_AN_HOUR = 6;

/** The demos, in the order the top bar lists them, and where the source of each is. */
const DEMO_STACKS = [
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

/** The token of a user: their id and its signature. */
function demo_sign(int $id): string
{
    $mac = hash_hmac('sha256', (string) $id, getenv('SESSION_SECRET') ?: 'example-shop', true);

    return $id.'.'.rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
}

/** The user a token names, if its signature is right and they are one of the people of a shop. */
function demo_user_of(?string $token): ?int
{
    $id = (int) explode('.', (string) $token)[0];

    return null !== person($id) && hash_equals(demo_sign($id), (string) $token) ? $id : null;
}

/**
 * The shop for a new visitor at `$address`, its number: a new one, or once the address has opened its few this hour,
 * the newest of them again, so nobody is ever turned away and nobody can open thousands.
 */
function demo_open_shop(string $address): int
{
    $number = 0;
    shops(function (array &$shops) use ($address, &$number) {
        // Shops end after a day. The recent shops of each address are kept beside them.
        foreach ($shops as $s => $shop) {
            if ('_opened' !== $s && time() - $shop['opened'] > DEMO_DAY) {
                unset($shops[$s]);
            }
        }
        $opened = [];
        foreach ($shops['_opened'] ?? [] as $key => $list) {
            $list = array_values(array_filter((array) $list, fn ($o) => is_array($o) && time() - $o['at'] < 3600 && isset($shops[(string) $o['s']])));
            if ([] !== $list) {
                $opened[$key] = $list;
            }
        }
        $recent = $opened[$address] ?? [];
        if (count($recent) >= DEMO_SHOPS_AN_HOUR) {
            $number = $recent[count($recent) - 1]['s'];
        } else {
            do {
                $number = random_int(100000, 999999);
            } while (isset($shops[(string) $number]));
            $shops[(string) $number] = open_shop($number);
            $recent[] = ['s' => $number, 'at' => time()];
        }
        $opened[$address] = $recent;
        $shops['_opened'] = $opened;
    });

    return $number;
}

/** What a person page draws around the shop. */
function demo_frame(array $me): array
{
    $sites = getenv('WORDPLUS_DEMO_SITES') ?: 'https://{case}.docs.wordplus.cloud';
    $own = array_values(array_filter(DEMO_STACKS, fn (array $s) => 'php' === $s[0]))[0];

    return [
        'stack' => 'php',
        'name' => $own[1],
        'guide' => 'https://docs.wordplus.cloud/php/getting-started/',
        'source' => 'https://github.com/wordplus-cloud/'.$own[2].'/tree/main/examples/php',
        'sites' => array_map(fn (array $s) => ['stack' => $s[0], 'name' => $s[1], 'url' => str_replace('{case}', $s[0], $sites)], DEMO_STACKS),
        // This window person, which the page sends with every request. And the others, each a window of their own.
        'token' => demo_sign($me['id']),
        'open' => array_values(array_map(
            fn (array $p) => ['id' => $p['id'], 'name' => $p['name'], 'url' => '/as/'.demo_sign($p['id'])],
            array_filter(people_of($me['shop']), fn (array $p) => $p['id'] !== $me['id']),
        )),
        'ends' => (shops()[(string) $me['shop']]['opened'] + DEMO_DAY) * 1000,
        'limits' => demo_limits(),
        'code' => demo_code(),
    ];
}

/**
 * How long a call and a meeting may last, in seconds, which the page keeps to: 10 and 15 minutes, as the demo runs on
 * WordPlus Cloud for anyone. WORDPLUS_DEMO_LIMITS ("call,meeting") shortens them, for the demo check.
 */
function demo_limits(): array
{
    [$call, $meeting] = array_map('intval', explode(',', getenv('WORDPLUS_DEMO_LIMITS') ?: '600,900'));

    return ['call' => $call, 'meeting' => $meeting];
}

/**
 * The code under each card, "See the code": the parts of this example marked `#region demo: <card>`, which the build
 * of the demo sites cuts from its source into WORDPLUS_DEMO_CODE, by stack.
 */
function demo_code(): ?array
{
    $path = getenv('WORDPLUS_DEMO_CODE');

    return $path ? (json_decode((string) file_get_contents($path), true)['php'] ?? null) : null;
}
