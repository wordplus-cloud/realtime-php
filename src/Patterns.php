<?php

declare(strict_types=1);

namespace WordPlus\Realtime;

/**
 * The names an app's rules own, by pattern: `order:{id}`,
 * `chat:{a}:{b}`, `meeting:{id}`, a plain name for itself. A `{part}` takes one or more characters other than `:` and
 * `.`, as few as the rest of the pattern allows, exactly as the realtime server matches a public grant's patterns. The
 * rule with the most characters of its own wins; on a tie, the one declared first. @wordplus/realtime's patterns.ts, line
 * for line.
 *
 * `{pair}`, in a room's or a call's pattern, is the two people the name holds: `support:{pair}` matches `support:34:42`,
 * with `[ 'pair' => '34:42' ]`, and only those two get in, which room() and calls() check before any rule of the app's.
 */
final class Patterns
{
    /** @var list<array{pattern: string, re: string, parts: list<string>, literal: int, order: int, value: mixed}> */
    private array $list = [];

    /** Whether a pattern names the two people in it. */
    public static function hasPair(string $pattern): bool
    {
        return str_contains($pattern, '{pair}');
    }

    /** The other of the two in a `{pair}` for a user who is one of them, or null for anyone else, and for one person twice. */
    public static function otherOf(string $pair, int|string|null $user): ?string
    {
        [$a, $b] = explode(':', $pair, 2);

        if (null === $user || $a === $b) {
            return null;
        }

        $id = (string) $user;

        return $id === $a ? $b : ($id === $b ? $a : null);
    }

    /** A room's name, a call's or a group call's: 1 to 200 of A-Z a-z 0-9 _ - = @ , . ; and `:`. */
    public static function validRoom(mixed $name): bool
    {
        return is_string($name) && 1 === preg_match('/^[A-Za-z0-9_\-=@,.;:]{1,200}$/D', $name);
    }

    /** A pattern: a name with `{part}`s, each named by a letter or `_` then up to 31 letters, digits and `_`. */
    public static function validPattern(mixed $pattern): bool
    {
        return is_string($pattern) && 1 === preg_match('/^(?:[A-Za-z0-9_\-=@,.;:]|\{[A-Za-z_][A-Za-z0-9_]{0,31}\}){1,200}$/D', $pattern);
    }

    /** Adds a pattern and what it owns; one already there is replaced. */
    public function add(string $pattern, mixed $value): void
    {
        if (!self::validPattern($pattern)) {
            throw new \InvalidArgumentException('wordplus-realtime: not a pattern: '.json_encode($pattern).' (letters, digits, _ - = @ , . ; : and {part}s)');
        }

        if (substr_count($pattern, '{pair}') > 1) {
            throw new \InvalidArgumentException('wordplus-realtime: a name holds one pair: '.json_encode($pattern));
        }

        $parts = [];
        $source = '';

        foreach ((array) preg_split('/(\{[^}]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            if (str_starts_with((string) $piece, '{')) {
                $parts[] = substr((string) $piece, 1, -1);
                $source .= '{pair}' === $piece ? '([^:.]+?:[^:.]+?)' : '([^:.]+?)';
            } else {
                $source .= preg_quote((string) $piece, '/');
            }
        }

        $existing = null;

        foreach ($this->list as $i => $compiled) {
            if ($compiled['pattern'] === $pattern) {
                $existing = $i;
            }
        }

        $compiled = [
            'pattern' => $pattern,
            're' => '/^'.$source.'$/D',
            'parts' => $parts,
            // `{pair}` counts its `:`, so it weighs as `{a}:{b}` does.
            'literal' => strlen((string) preg_replace('/\{[^}]+\}/', '', str_replace('{pair}', ':', $pattern))),
            'order' => null === $existing ? count($this->list) : $this->list[$existing]['order'],
            'value' => $value,
        ];

        if (null === $existing) {
            $this->list[] = $compiled;
        } else {
            $this->list[$existing] = $compiled;
        }
    }

    /**
     * What owns a name, with its parts, or null for none.
     *
     * @return array{pattern: string, value: mixed, params: array<string, string>}|null
     */
    public function match(string $name): ?array
    {
        $best = null;
        $params = [];

        foreach ($this->list as $compiled) {
            if (1 !== preg_match($compiled['re'], $name, $m)) {
                continue;
            }

            if (null !== $best && ($compiled['literal'] < $best['literal'] || ($compiled['literal'] === $best['literal'] && $compiled['order'] > $best['order']))) {
                continue;
            }

            $best = $compiled;
            $params = [];

            foreach ($compiled['parts'] as $i => $part) {
                $params[$part] = $m[$i + 1];
            }
        }

        return null === $best ? null : ['pattern' => $best['pattern'], 'value' => $best['value'], 'params' => $params];
    }

    /** @return list<array{0: string, 1: mixed}> */
    public function entries(): array
    {
        return array_map(static fn (array $compiled) => [$compiled['pattern'], $compiled['value']], $this->list);
    }
}
