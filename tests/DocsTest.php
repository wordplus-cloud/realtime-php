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
