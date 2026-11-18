<?php

declare(strict_types=1);

namespace WordPlus\Realtime\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use WordPlus\Realtime\Realtime;

/**
 * Every php block of the README and the docs is PHP that parses (php -l, each block as a file of its own), and what it
 * calls of the package is there: each class it imports from WordPlus\Realtime, and each method of a chain that starts
 * at the server's connection, `$realtime->…` or the facade's `Realtime::…`, with no fewer arguments than the method
 * needs and no more than it takes, followed through what each method answers (`Realtime::call( $name )->permit( … )`).
 * Its options and the app's own names are the app's, as the prose says.
 */
final class SamplesTest extends TestCase
{
    /**
     * @return list<array{page: string, line: int, code: string, fragment: bool}> Each php block, with the line its code
     *     starts on, and whether it says it is a part of something larger (```php fragment).
     */
    private static function blocks(): array
    {
        $root = dirname(__DIR__);
        $pages = array_merge([$root.'/README.md'], glob($root.'/docs/*.md') ?: [], glob($root.'/docs/*/*.md') ?: []);
        $blocks = [];

        foreach ($pages as $page) {
            $lines = explode("\n", (string) file_get_contents($page));
            for ($i = 0; $i < count($lines); $i++) {
                if (! preg_match('/^(\s*)```(\w*)(.*)$/', $lines[$i], $open)) {
                    continue;
                }
                $end = $i + 1;
                while ($end < count($lines) && trim($lines[$end]) !== '```') {
                    $end++;
                }
                if ($open[2] === 'php') {
                    $code = array_map(static fn (string $line) => str_starts_with($line, $open[1]) ? substr($line, strlen($open[1])) : ltrim($line), array_slice($lines, $i + 1, $end - $i - 1));
                    $blocks[] = ['page' => substr($page, strlen($root) + 1), 'line' => $i + 2, 'code' => implode("\n", $code), 'fragment' => (bool) preg_match('/(^|\s)fragment(\s|$)/', $open[3])];
                }
                $i = $end;
            }
        }

        return $blocks;
    }

    /**
     * A block as a file, on the same lines: PHP from its first line, unless it is a template (HTML with `<?php … ?>` in
     * it). A block of methods (`public function boot()`, in the provider the prose names) is a class's body, after its
     * `use` lines.
     */
    private static function file(string $code): string
    {
        if (str_starts_with(ltrim($code), '<') || str_contains($code, '?>')) {
            return $code;
        }
        $lines = explode("\n", $code);
        foreach ($lines as $i => $line) {
            if (trim($line) === '' || preg_match('#^(use\s|//)#', $line)) {
                continue;
            }
            if (preg_match('/^(public|protected|private)\s+(static\s+)?function\b/', $line)) {
                $lines[$i] = 'final class Sample { '.$line;
                $lines[] = '}';
            }
            break;
        }

        return '<?php '.implode("\n", $lines);
    }

    public function testEveryBlockParses(): void
    {
        $blocks = array_values(array_filter(self::blocks(), static fn (array $block) => ! $block['fragment']));
        $this->assertGreaterThan(60, count($blocks));
        $dir = sys_get_temp_dir().'/wordplus-realtime-samples-'.getmypid();
        @mkdir($dir);
        $files = [];
        foreach ($blocks as $n => $block) {
            $files[$n] = $dir.'/'.$n.'.php';
            file_put_contents($files[$n], self::file($block['code']));
        }

        // php -l, all at once from PHP 8.3, which lints several files in one run, one at a time before it.
        $lint = static function (array $paths): string {
            exec(escapeshellarg(PHP_BINARY).' -n -l '.implode(' ', array_map('escapeshellarg', $paths)).' 2>&1', $output);

            return implode("\n", $output);
        };
        $output = PHP_VERSION_ID >= 80300 ? $lint($files) : implode("\n", array_map(static fn ($path) => $lint([$path]), $files));
        array_map('unlink', $files);
        rmdir($dir);

        $failed = [];
        preg_match_all('/^(?:PHP )?(?:Parse|Fatal) error: (.*) in (\S+) on line (\d+)$/m', $output, $errors, PREG_SET_ORDER);
        foreach ($errors as [, $message, $path, $at]) {
            $block = $blocks[(int) basename($path, '.php')];
            $failed[] = $block['page'].':'.($block['line'] + (int) $at - 1).' '.$message;
        }
        $this->assertSame([], $failed);
        $this->assertSame(count($files), substr_count($output, 'No syntax errors detected'), $output);
    }

    /** The parts of something larger, marked ```php fragment and not parsed. Another one is a choice. */
    public function testFewBlocksAreFragments(): void
    {
        $fragments = array_map(static fn (array $block) => $block['page'].':'.$block['line'], array_filter(self::blocks(), static fn (array $block) => $block['fragment']));
        $this->assertSame(['docs/laravel/deploying.md:44', 'docs/laravel/getting-started.md:49'], array_values($fragments));
    }

    public function testWhatTheBlocksCallIsThere(): void
    {
        $failed = [];
        $chains = 0;

        foreach (self::blocks() as $block) {
            if ($block['fragment']) {
                continue;
            }
            $tokens = \PhpToken::tokenize(self::file($block['code']));
            $line = static fn (\PhpToken $token) => $block['page'].':'.($block['line'] + $token->line - 1);

            foreach ($tokens as $i => $token) {
                // `use WordPlus\Realtime\…;`, and a name written in full.
                if ($token->is(T_NAME_QUALIFIED) || $token->is(T_NAME_FULLY_QUALIFIED)) {
                    $name = ltrim($token->text, '\\');
                    if (str_starts_with($name, 'WordPlus\\Realtime\\') && ! class_exists($name) && ! interface_exists($name)) {
                        $failed[] = $line($token).' no '.$name;
                    }
                }

                // A chain from `$realtime` or the facade `Realtime`.
                $from = $token->is(T_VARIABLE) && $token->text === '$realtime' ? '->' : ($token->is(T_STRING) && $token->text === 'Realtime' ? '::' : null);
                if ($from === null || ($tokens[$i - 1] ?? null)?->is([T_NEW, T_FUNCTION, T_FN]) || ($tokens[$i - 1] ?? null)?->text === '\\') {
                    continue;
                }
                $classes = [Realtime::class];
                $at = self::next($tokens, $i);
                $operator = $from;
                while ($classes !== [] && isset($tokens[$at]) && $tokens[$at]->text === $operator) {
                    $name = $tokens[self::next($tokens, $at)] ?? null;
                    if (! $name?->is(T_STRING) || $name->text === 'class') {
                        break;
                    }
                    $open = self::next($tokens, self::next($tokens, $at));
                    if (($tokens[$open] ?? null)?->text !== '(') {
                        break;
                    }
                    [$arguments, $close, $spread] = self::arguments($tokens, $open);
                    $chains++;

                    $methods = array_values(array_filter(array_map(static fn (string $class) => method_exists($class, $name->text) ? new ReflectionMethod($class, $name->text) : null, $classes)));
                    $methods = array_values(array_filter($methods, static fn (ReflectionMethod $method) => $method->isPublic()));
                    if ($methods === []) {
                        $failed[] = $line($name).' no '.implode('|', array_map(static fn ($class) => (new ReflectionClass($class))->getShortName(), $classes)).'::'.$name->text.'()';
                        break;
                    }
                    $fits = array_filter($methods, static fn (ReflectionMethod $method) => $spread || ($arguments >= $method->getNumberOfRequiredParameters() && ($method->isVariadic() || $arguments <= $method->getNumberOfParameters())));
                    if ($fits === []) {
                        $method = $methods[0];
                        $failed[] = $line($name).' '.$name->text.'() with '.$arguments.' arguments, which takes '.$method->getNumberOfRequiredParameters().' to '.$method->getNumberOfParameters();
                        break;
                    }

                    // What it answers, for the next in the chain: the package's classes alone.
                    $classes = [];
                    foreach ($fits as $method) {
                        $type = $method->getReturnType();
                        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $one) {
                            if ($one instanceof ReflectionNamedType && ! $one->isBuiltin()) {
                                $classes[] = $one->getName() === 'self' || $one->getName() === 'static' ? $method->getDeclaringClass()->getName() : $one->getName();
                            }
                        }
                    }
                    $classes = array_values(array_unique(array_filter($classes, static fn (string $class) => str_starts_with($class, 'WordPlus\\Realtime\\'))));
                    $at = self::next($tokens, $close);
                    $operator = '->';
                }
            }
        }

        $this->assertGreaterThan(100, $chains);
        $this->assertSame([], $failed);
    }

    /** @param list<\PhpToken> $tokens The index of the next token that is not space or a comment. */
    private static function next(array $tokens, int $i): int
    {
        do {
            $i++;
        } while (isset($tokens[$i]) && $tokens[$i]->isIgnorable());

        return $i;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return array{int, int, bool} How many arguments the call at `(` has, where its `)` is, and whether one is spread.
     */
    private static function arguments(array $tokens, int $open): array
    {
        $depth = 0;
        $count = 0;
        $any = false;
        $spread = false;
        for ($i = $open; isset($tokens[$i]); $i++) {
            $text = $tokens[$i]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return [$any ? $count + 1 : $count, $i, $spread];
                }
            } elseif ($depth === 1 && $text === ',') {
                // A comma before the `)` that closes the list ends no argument.
                $count++;
                $any = false;
                continue;
            } elseif ($depth === 1 && $tokens[$i]->is(T_ELLIPSIS)) {
                $spread = true;
            }
            if ($depth >= 1 && ! $tokens[$i]->isIgnorable()) {
                $any = true;
            }
        }

        return [$count, count($tokens) - 1, true];
    }
}
