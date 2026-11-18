<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The README and the docs (docs/: the reference at the top, a guide for Laravel and one for PHP in their folders, as
 * docs.wordplus.cloud shows them) link only to files that are there, and to other pages of the docs, and their
 * illustrations, by their address on docs.wordplus.cloud.
 */
final class DocsTest extends TestCase
{
    /** A guide's pages, as docs.wordplus.cloud lists them (its scripts/sync.mjs). */
    private const GUIDE = ['getting-started', 'rooms', 'presence', 'sending', 'events', 'calls', 'streams', 'encryption', 'customizing', 'deploying', 'example'];

    private const SECTIONS = 'apps|wordpress|nextjs|laravel|django|fastapi|express|php|flask|mobile|ios|android|use-cases';

    /** @return list<string> */
    private static function docs(): array
    {
        $root = dirname(__DIR__);

        return array_merge(glob($root.'/docs/*.md') ?: [], glob($root.'/docs/*/*.md') ?: []);
    }

    /** The ready-made parts' names since @wordplus/realtime-ui 1.0.6 (its f919f81): the old ones do nothing on a page. */
    public function testTheDocsNameTheReadyMadePartsAsTheyAreNow(): void
    {
        foreach (array_merge([dirname(__DIR__).'/README.md'], self::docs()) as $file) {
            $this->assertDoesNotMatchRegularExpression('/--bm-|\bbm-messages-|\.bm-[a-z]/', (string) file_get_contents($file), $file);
        }
    }

    public function testEachGuideHasItsPages(): void
    {
        $root = dirname(__DIR__);
        $this->assertFileExists($root.'/docs/reference.md');

        foreach (['laravel', 'php'] as $guide) {
            $pages = array_map(static fn ($file) => basename($file, '.md'), glob($root.'/docs/'.$guide.'/*.md') ?: []);
            sort($pages);
            $wanted = self::GUIDE;
            sort($wanted);
            $this->assertSame($wanted, $pages, $guide);
        }
    }

    /**
     * Every guide teaches one first call and one first stream: a route of the app that answers callToken() or
     * streamToken() for the signed-in user, then the element that asks it (asked on 2026-11-09: "the users application
     * provides the authorization token to client to start call so we need to make a clear that in api and examples").
     * A rule is the other way, in a section of its own, "By a rule", after the token way, as in the Node guides.
     */
    public function testTheGuidesStartACallAndAStreamByAToken(): void
    {
        $root = dirname(__DIR__);
        $section = function (string $page, string $heading) use ($root): string {
            $text = (string) file_get_contents($root.'/docs/'.$page);
            $start = strpos($text, "\n## {$heading}\n");
            $this->assertNotFalse($start, "{$page}: ## {$heading}");
            $end = strpos($text, "\n## ", $start + 1);

            return false === $end ? substr($text, $start) : substr($text, $start, $end - $start);
        };
        $rules = '/(\$realtime->|Realtime::)(calls|groupCalls|streams)\(/';

        foreach (['laravel', 'php'] as $guide) {
            $call = $section("{$guide}/calls.md", 'Your first call');
            $this->assertMatchesRegularExpression('/(\$realtime->|Realtime::)callToken\(/', $call, $guide);
            $this->assertStringContainsString('token-url=', $call, $guide);
            $this->assertDoesNotMatchRegularExpression($rules, $call, $guide);

            $stream = $section("{$guide}/streams.md", 'Your first stream');
            $this->assertMatchesRegularExpression('/(\$realtime->|Realtime::)streamToken\(/', $stream, $guide);
            $this->assertStringContainsString('token-url=', $stream, $guide);
            $this->assertDoesNotMatchRegularExpression($rules, $stream, $guide);

            $who = $section("{$guide}/calls.md", 'Who may call');
            $this->assertMatchesRegularExpression('/(\$realtime->|Realtime::)groupCallToken\(/', $who, $guide);
            $this->assertStringContainsString('token-url=', $section("{$guide}/calls.md", 'Group calls'), $guide);
            $this->assertGreaterThan((int) strpos($who, 'callToken('), (int) strpos($who, "\n### By a rule\n"), "{$guide}: calls, By a rule");
            $this->assertMatchesRegularExpression('/\$realtime->calls\(/', $who, $guide);

            $whoIs = $section("{$guide}/streams.md", 'Who is who');
            $this->assertGreaterThan((int) strpos($whoIs, 'streamToken('), (int) strpos($whoIs, "\n### By a rule\n"), "{$guide}: streams, By a rule");
            $this->assertMatchesRegularExpression('/\$realtime->streams\(/', $whoIs, $guide);
        }
    }

    public function testLinksReachFiles(): void
    {
        foreach (array_merge([dirname(__DIR__).'/README.md'], self::docs()) as $file) {
            preg_match_all('/\]\((?!https?:|#)([^)#]+)/', (string) file_get_contents($file), $links);

            foreach ($links[1] as $link) {
                $this->assertFileExists(dirname($file).'/'.$link, basename($file).' → '.$link);
            }
        }
    }

    public function testDocsLinkTheSiteByItsPages(): void
    {
        foreach (self::docs() as $file) {
            preg_match_all('#\]\(https://docs\.wordplus\.cloud/([^)\#]*)#', (string) file_get_contents($file), $links);

            foreach ($links[1] as $path) {
                $this->assertMatchesRegularExpression('#^(('.self::SECTIONS.')/[a-z-]+/|img/[a-z]+/[a-z0-9-]+\.webp)$#', $path, basename($file).' → '.$path);
            }
        }
    }
}
