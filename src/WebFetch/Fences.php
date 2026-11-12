<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

/** "CommonMark fenced blocks, including blockquote containers" — pi-antigravity's `web-fetch/fences.ts`. */
final class Fences
{
    public const string LINE = '/^ {0,3}((?:>\s*)*)(`{3,}|~{3,})(.*)$/Ds';

    /**
     * The fence open after $line, given the one open before it.
     *
     * @param array{marker: string, length: int, prefix: string}|null $open
     * @return array{marker: string, length: int, prefix: string}|null
     */
    public static function advance(?array $open, string $line): ?array
    {
        if (preg_match(self::LINE, $line, $match) !== 1) {
            return $open;
        }

        [, $prefix, $delimiter, $rest] = $match;

        if ($open === null) {
            if ($delimiter[0] === '`' && str_contains($rest, '`')) {
                return null;
            }

            return ['marker' => $delimiter[0], 'length' => strlen($delimiter), 'prefix' => $prefix];
        }

        return $delimiter[0] === $open['marker'] && strlen($delimiter) >= $open['length'] && $prefix === $open['prefix'] && Text::blank($rest)
            ? null
            : $open;
    }

    /** @return array{marker: string, length: int, prefix: string}|null */
    public static function unclosed(string $text): ?array
    {
        $open = null;

        foreach (explode("\n", $text) as $line) {
            $open = self::advance($open, $line);
        }

        return $open;
    }

    /** Whether $line is a fence delimiter line. */
    public static function isLine(string $line): bool
    {
        return preg_match(self::LINE, $line) === 1;
    }
}
