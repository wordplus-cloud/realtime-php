<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The Example Shop of the plain PHP and Laravel examples starts its call, its daily meeting and its live show from tokens
 * of the app's own routes, as the guides teach and as realtime-js's examples do: the page (the same shop.js in every
 * example) names each route by token-url, the app answers them with callToken(), groupCallToken() and streamToken(), and
 * declares no calls(), groupCalls() or streams() rule for them.
 */
final class ExamplesTest extends TestCase
{
    private const PAGES = ['examples/php/public/shop.js', 'examples/laravel/resources/js/shop.js'];

    public function testThePageAsksTheAppsTokenRoutes(): void
    {
        $root = dirname(__DIR__);
        $page = (string) file_get_contents($root.'/'.self::PAGES[0]);
        $this->assertSame($page, file_get_contents($root.'/'.self::PAGES[1]), 'the shop page is the same in both examples');

        foreach (['callToken', 'meetingToken', 'showToken'] as $route) {
            $this->assertStringContainsString('token-url="${shop.routes.'.$route.'}"', $page);
        }
        $this->assertStringNotContainsString('{pair}', $page);
        $this->assertStringNotContainsString('<room-button', $page);
    }

    public function testTheAppsMakeTheTokensWithNoRule(): void
    {
        $root = dirname(__DIR__);
        $apps = [
            'php' => ['examples/php/src/app.php', 'examples/php/public/index.php'],
            'laravel' => ['examples/laravel/app/Providers/AppServiceProvider.php', 'examples/laravel/app/Http/Controllers/TokenController.php', 'examples/laravel/app/Http/Controllers/ShopController.php', 'examples/laravel/routes/web.php'],
        ];

        foreach ($apps as $app => $files) {
            $code = implode("\n", array_map(fn (string $file) => (string) file_get_contents($root.'/'.$file), $files));
            $this->assertDoesNotMatchRegularExpression('/(\$realtime->|Realtime::)(calls|groupCalls|streams)\(/', $code, $app);
            foreach (['callToken', 'groupCallToken', 'streamToken'] as $made) {
                $this->assertMatchesRegularExpression('/(\$realtime->|Realtime::)'.$made.'\(/', $code, "{$app}: {$made}");
            }
            foreach (['callToken', 'meetingToken', 'showToken'] as $route) {
                $this->assertStringContainsString("'{$route}' =>", $code, "{$app}: shop.routes.{$route}");
            }
            foreach (['/api/call-token', '/api/meeting-token', '/api/show-token'] as $path) {
                $this->assertStringContainsString("'{$path}'", $code, "{$app}: {$path}");
            }
        }
    }
}
