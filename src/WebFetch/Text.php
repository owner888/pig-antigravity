<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

/**
 * Text that came from somewhere else — a page, the fetch service — made safe to show:
 * pi-antigravity's `utils/text.ts`.
 *
 * "Two operations with two scopes": `repair()` keeps structure and length and replaces only what
 * is not text, and applies to every third-party string, page bodies included; `clean()` flattens a
 * value to one bounded line and applies only to labels and metadata, "never to a page body, whose
 * line structure is the content".
 *
 * Upstream's strings are UTF-16 and what it repairs is a lone surrogate; pig's are UTF-8 bytes, so
 * what is repaired here is an invalid sequence (an encoded surrogate is one), and lengths are
 * counted in characters, which cannot be cut through.
 */
final class Text
{
    /** What JavaScript's `\s` and `trim()` take for whitespace, for a `/u` character class. */
    public const string SPACE = '\t\n\x0B\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Replace what is not UTF-8 with U+FFFD. */
    public static function repair(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /** JavaScript's `String.prototype.trim()`. */
    public static function trim(string $text): string
    {
        return (string) preg_replace('/^[' . self::SPACE . ']+|[' . self::SPACE . ']+$/u', '', $text);
    }

    /** Whether there is nothing in it but whitespace, as `!text.trim()` asks. */
    public static function blank(string $text): bool
    {
        return preg_match('/^[' . self::SPACE . ']*$/u', $text) === 1;
    }

    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /** Cut text to at most $limit characters. */
    public static function cap(string $text, int $limit): string
    {
        return self::length($text) <= $limit ? $text : mb_substr($text, 0, max(0, $limit), 'UTF-8');
    }

    /** "One bounded, single-line value: control characters and line breaks become spaces." */
    public static function clean(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cleaned = (string) preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', self::repair($value));
        $cleaned = self::trim((string) preg_replace('/[' . self::SPACE . ']+/u', ' ', $cleaned));

        if ($cleaned === '') {
            return null;
        }

        return self::length($cleaned) <= $limit ? $cleaned : self::cap($cleaned, max(0, $limit - 1)) . '…';
    }

    /** "Keep the head and the tail of a long value, eliding the middle." */
    public static function elideMiddle(string $value, int $limit): string
    {
        $length = self::length($value);

        if ($length <= $limit) {
            return $value;
        }

        if ($limit < 3) {
            return self::cap($value, $limit);
        }

        $keep = $limit - 1;
        $head = (int) ceil($keep / 2);

        return mb_substr($value, 0, $head, 'UTF-8') . '…' . mb_substr($value, $length - ($keep - $head), null, 'UTF-8');
    }
}
