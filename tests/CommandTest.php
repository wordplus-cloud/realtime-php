<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The package's command, as an app runs it: in the app's folder, with no terminal to ask in. The realtime server and the
 * app are one small PHP server here, answering as `router.php` it is given says.
 */
final class CommandTest extends TestCase
{
    private const KEYS = ['WORDPLUS_SITE' => 's1500000007', 'WORDPLUS_SECRET' => 'the-secret', 'WORDPLUS_LOCAL_KEY' => 'abababababababababababababababababababababababababababababababab'];

    private string $dir;

    /** @var list<resource> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/wordplus-command-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
        }
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /**
     * Runs the command in the app's folder, with only the environment given of WordPlus's.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function run_(array $args, array $env = []): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__).'/bin/wordplus-realtime'], $args);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->dir, array_merge(['PATH' => (string) getenv('PATH')], $env));
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $out];
    }

    /** A server in a folder of its own, on a free port, running this router: its address. */
    private function serve(string $router): string
    {
        $folder = $this->dir.'/server-'.count($this->servers);
        mkdir($folder);
        file_put_contents($folder.'/router.php', "<?php\n".$router);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $folder.'/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $folder);
        $this->servers[] = $server;
        $address = 'http://127.0.0.1:'.$port;
        for ($i = 0; $i < 100 && false === @fsockopen('127.0.0.1', $port); ++$i) {
            usleep(50000);
        }

        return $address;
    }

    private const CLOUD_OK = <<<'PHP'
        if ('/wordplus/realtime/usage' === $_SERVER['REQUEST_URI']) {
            echo json_encode(['ok' => true, 'connections' => ['now' => 3, 'limit' => 1000]]);
            return;
        }

        PHP;

    public function testInitWritesTheKeysAndALocalKeyMadeForTheApp(): void
    {
        file_put_contents($this->dir.'/.gitignore', ".env\n");

        [$code, $out] = $this->run_(['init', '--app', 's1500000007', '--secret', 'the-secret']);

        $this->assertSame(0, $code);
        $env = (string) file_get_contents($this->dir.'/.env');
        $this->assertStringContainsString("WORDPLUS_SITE=s1500000007\n", $env);
        $this->assertStringContainsString("WORDPLUS_SECRET=the-secret\n", $env);
        $this->assertSame(1, preg_match('/WORDPLUS_LOCAL_KEY=([0-9a-f]{64})\n/', $env, $local));
        $this->assertStringContainsString('wordplus-realtime check', $out);
        $this->assertStringNotContainsString('the-secret', $out);
        $this->assertStringNotContainsString($local[1], $out);
        $this->assertStringNotContainsString("isn't in .gitignore", $out);
    }

    public function testInitChangesNothingAlreadyThereTheLocalKeyAboveAll(): void
    {
        $before = "WORDPLUS_SITE=s1\nWORDPLUS_SECRET=old\nWORDPLUS_LOCAL_KEY=".self::KEYS['WORDPLUS_LOCAL_KEY']."\n";
        file_put_contents($this->dir.'/.env', $before);

        $this->run_(['init', '--app', 's2', '--secret', 'new']);

        $this->assertSame($before, file_get_contents($this->dir.'/.env'));
    }

    public function testInitAddsWhatAFileLacksOnALineOfItsOwn(): void
    {
        file_put_contents($this->dir.'/.env', 'OTHER=1');

        $this->run_(['init', '--app=s1', '--secret=x']);

        $this->assertStringStartsWith("OTHER=1\nWORDPLUS_SITE=s1\nWORDPLUS_SECRET=x\nWORDPLUS_LOCAL_KEY=", (string) file_get_contents($this->dir.'/.env'));
    }

    public function testInitKeepsTheSecretOutOfGitOnALineOfItsOwnAndOnce(): void
    {
        file_put_contents($this->dir.'/.gitignore', 'vendor');

        $this->assertStringContainsString('added    .env to .gitignore', $this->run_(['init', '--app', 's1', '--secret', 'x'])[1]);
        $this->assertSame("vendor\n.env\n", file_get_contents($this->dir.'/.gitignore'));

        $this->run_(['init', '--app', 's1', '--secret', 'x']);
        $this->assertSame("vendor\n.env\n", file_get_contents($this->dir.'/.gitignore'));
    }

    public function testInitStartsAGitignoreWhereThereIsNone(): void
    {
        $this->run_(['init', '--app', 's1', '--secret', 'x']);

        $this->assertSame(".env\n", file_get_contents($this->dir.'/.gitignore'));
    }

    public function testCheckSaysEachKeyThatIsMissingOrWrongAndWhatToDo(): void
    {
        [$code, $out] = $this->run_(['check'], ['WORDPLUS_LOCAL_KEY' => 'short']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('WORDPLUS_SITE is missing', $out);
        $this->assertStringContainsString('WORDPLUS_SECRET is missing', $out);
        $this->assertStringContainsString("WORDPLUS_LOCAL_KEY isn't 64 hex characters", $out);
        $this->assertStringContainsString('openssl rand -hex 32', $out);
    }

    public function testCheckReadsTheEnvFileAQuotedValueAndACommentLeftOut(): void
    {
        file_put_contents($this->dir.'/.env', "WORDPLUS_SITE=\"s1\"  # the key\n");

        $this->assertStringContainsString("WORDPLUS_SITE is s1\n", $this->run_(['check'])[1]);
    }

    public function testCheckAsksTheCloudWithASignatureTheServerPartMakesAndNeverSaysTheSecret(): void
    {
        $api = $this->serve(<<<'PHP'
            $key = hash_hmac('sha256', 'wordplus-realtime publish v1', 'the-secret', true);
            $line = $_SERVER['HTTP_X_WORDPLUS_TIMESTAMP']."\n".$_SERVER['REQUEST_METHOD']."\n".$_SERVER['REQUEST_URI']."\n".hash('sha256', '');
            $right = hash_equals(hash_hmac('sha256', $line, $key), (string) $_SERVER['HTTP_X_WORDPLUS_SIGNATURE']) && 's1500000007' === $_SERVER['HTTP_X_WORDPLUS_SITE'];
            http_response_code($right ? 200 : 401);
            echo json_encode($right ? ['ok' => true, 'connections' => ['now' => 3, 'limit' => 1000]] : ['ok' => false, 'error' => 'bad_signature']);
            PHP);

        [$code, $out] = $this->run_(['check'], self::KEYS + ['WORDPLUS_REALTIME_API' => $api]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('3 connected now of 1000', $out);
        $this->assertStringNotContainsString('the-secret', $out);
    }

    /** @return array<string, array{string, string}> */
    public static function refusals(): array
    {
        return [
            'an app it does not know' => ['unknown_site', 'WORDPLUS_SITE'],
            'a secret that is wrong' => ['bad_signature', 'WORDPLUS_SECRET'],
            'a clock that is off' => ['stale_signature', 'clock'],
            'a plan without it' => ['not_included', 'plan'],
        ];
    }

    /** @dataProvider refusals */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testCheckSaysWhatARefusalMeans(string $error, string $word): void
    {
        $api = $this->serve("http_response_code(401); echo json_encode(['ok' => false, 'error' => '$error']);");

        [$code, $out] = $this->run_(['check'], self::KEYS + ['WORDPLUS_REALTIME_API' => $api]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString($word, $out);
    }

    public function testCheckPassesRoutesThatAnswer(): void
    {
        $address = $this->serve(self::CLOUD_OK.<<<'PHP'
            if ('/api/realtime/keys' === $_SERVER['REQUEST_URI']) { echo json_encode(['keys' => [], 'app' => 's1500000007']); return; }
            if ('/api/realtime/files/realtime-worker.js' === $_SERVER['REQUEST_URI']) { return; }
            http_response_code(404);
            PHP);

        [$code, $out] = $this->run_(['check', $address], self::KEYS + ['WORDPLUS_REALTIME_API' => $address]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('the keys route answers', $out);
        $this->assertStringContainsString('the browser files are served', $out);
    }

    public function testCheckLooksWhereLaravelsRoutesAreInALaravelApp(): void
    {
        touch($this->dir.'/artisan');
        $address = $this->serve(self::CLOUD_OK.<<<'PHP'
            if ('/wordplus/realtime/keys' === $_SERVER['REQUEST_URI']) { echo json_encode(['keys' => [], 'app' => 's1500000007']); return; }
            if ('/wordplus/realtime/files/realtime-worker.js' === $_SERVER['REQUEST_URI']) { return; }
            http_response_code(404);
            PHP);

        [$code, $out] = $this->run_(['check', $address], self::KEYS + ['WORDPLUS_REALTIME_API' => $address]);

        $this->assertSame(0, $code, $out);
    }

    public function testCheckPassesLaravelsCsrfCheckInFrontOfTheKeysRoute(): void
    {
        touch($this->dir.'/artisan');
        $address = $this->serve(self::CLOUD_OK.<<<'PHP'
            if ('/wordplus/realtime/keys' === $_SERVER['REQUEST_URI']) { http_response_code(419); return; }
            if ('/wordplus/realtime/files/realtime-worker.js' === $_SERVER['REQUEST_URI']) { return; }
            http_response_code(404);
            PHP);

        [$code, $out] = $this->run_(['check', $address], self::KEYS + ['WORDPLUS_REALTIME_API' => $address]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("behind Laravel's CSRF check", $out);
        $this->assertStringNotContainsString('$realtime->respond()', $out);
    }

    public function testCheckLooksUnderTheBaseItIsGiven(): void
    {
        $address = $this->serve(self::CLOUD_OK.<<<'PHP'
            if ('/hooks/rt/keys' === $_SERVER['REQUEST_URI']) { echo json_encode(['keys' => [], 'app' => 's1500000007']); return; }
            if ('/hooks/rt/files/realtime-worker.js' === $_SERVER['REQUEST_URI']) { return; }
            http_response_code(404);
            PHP);

        [$code] = $this->run_(['check', $address, '--base', '/hooks/rt'], self::KEYS + ['WORDPLUS_REALTIME_API' => $address]);

        $this->assertSame(0, $code);
    }

    public function testCheckSaysWhereTheRoutesGoASignInInFrontAndOtherKeys(): void
    {
        $missing = $this->serve(self::CLOUD_OK.'http_response_code(404);');
        $this->assertStringContainsString('$realtime->respond()', $this->run_(['check', $missing], self::KEYS + ['WORDPLUS_REALTIME_API' => $missing])[1]);

        $guarded = $this->serve(self::CLOUD_OK.'http_response_code(401);');
        $this->assertStringContainsString('asks for a sign-in', $this->run_(['check', $guarded], self::KEYS + ['WORDPLUS_REALTIME_API' => $guarded])[1]);

        $other = $this->serve(self::CLOUD_OK."echo json_encode(['keys' => [], 'app' => 's-other']);");
        $this->assertStringContainsString('your server reads other values', $this->run_(['check', $other], self::KEYS + ['WORDPLUS_REALTIME_API' => $other])[1]);
    }

    public function testWithNoCommandItSaysWhatItDoesAndFailsOnOneItDoesNotKnow(): void
    {
        [$code, $out] = $this->run_([]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('wordplus-realtime init', $out);
        $this->assertSame(1, $this->run_(['nonsense'])[0]);
    }

    /** @dataProvider helpProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('helpProvider')]
    public function testHelpSaysWhatItDoesAndDoesNothing(array $args): void
    {
        [$code, $out] = $this->run_($args);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('wordplus-realtime init', $out);
        $this->assertSame(['.', '..'], scandir($this->dir));
    }

    /** @return array<string, array{0: list<string>}> */
    public static function helpProvider(): array
    {
        return ['--help' => [['--help']], '-h' => [['-h']], 'help' => [['help']], 'init --help' => [['init', '--help']], 'check -h' => [['check', '-h']]];
    }
}
