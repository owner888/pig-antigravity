<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use Pig\Ai\Http\Request;
use Pig\CodingAgent\Config;

/**
 * A daily limit on the 429s an account may receive — pig's own, not pi-antigravity's, as the
 * developer asked: Google has suspended accounts after runs of 429s, and upstream's retries
 * (another endpoint, a sibling tier, the backoff ladder) can collect several in one turn.
 *
 * Every request to Google goes out through `DailyLimitedTransport`. Each 429 an account receives counts once;
 * once its count for the day reaches the limit, nothing more is sent on that account until the
 * next day (local time), and the request is refused here with `DailyLimitReached` — before it
 * leaves this machine. Other accounts are counted apart, so a quota wall can still move to one
 * that is under its limit.
 *
 * The limit is `antigravity.daily429Limit` in the person's own settings file (default 5), read
 * like `Credits`: a project's `.pig/settings.json` cannot raise it. The counts are kept in
 * `antigravity-429.json` beside it, for today only.
 */
final class DailyRateLimits
{
    public const int DEFAULT_LIMIT = 5;

    public const string SETTING = 'antigravity.daily429Limit';

    private static ?string $file = null;

    private static ?string $settingsFile = null;

    /** @var (Closure(): string)|null today's date, `Y-m-d` — for tests */
    private static ?Closure $today = null;

    /** @var (Closure(string): ?string)|null the account an access token belongs to */
    private static ?Closure $accountOf = null;

    /** @throws DailyLimitReached when $account has had the day's limit of 429s */
    public static function assertUnder(string $account): void
    {
        $count = self::count($account);
        $limit = self::limit();

        if ($count >= $limit) {
            throw new DailyLimitReached($count, $limit);
        }
    }

    /** Whether $account has had today's limit of 429s; a quota wall's failover passes such an account over. */
    public static function exceeded(string $account): bool
    {
        return self::count($account) >= self::limit();
    }

    public static function count(string $account): int
    {
        return self::read()['counts'][$account] ?? 0;
    }

    public static function record(string $account): void
    {
        $state = self::read();
        $state['counts'][$account] = ($state['counts'][$account] ?? 0) + 1;
        self::write($state);
    }

    /** `antigravity.daily429Limit` from the person's settings file, a whole number of at least 1; else 5. */
    public static function limit(): int
    {
        $path = self::$settingsFile ?? Config::home() . '/settings.json';
        $parsed = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $value = is_array($parsed) && is_array($parsed['antigravity'] ?? null) ? ($parsed['antigravity']['daily429Limit'] ?? null) : null;

        return is_int($value) && $value >= 1 ? $value : self::DEFAULT_LIMIT;
    }

    /** How a token is told to belong to an account: the extension's stored accounts. */
    public static function useAccounts(?Closure $accountOf): void
    {
        self::$accountOf = $accountOf;
    }

    /** For tests: where the counts and the setting are read, and what today is. */
    public static function useFiles(?string $counts, ?string $settings = null, ?Closure $today = null): void
    {
        self::$file = $counts;
        self::$settingsFile = $settings;
        self::$today = $today;
    }

    /** The account a request is sent for, by its bearer token; null for one that carries none (the OAuth token exchange). */
    public static function accountOf(Request $request): ?string
    {
        foreach ($request->headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization' && preg_match('/^Bearer\s+(\S+)$/i', (string) $value, $match) === 1) {
                return self::accountOfToken($match[1]);
            }
        }

        return null;
    }

    /** The stored account the token is filed under, or the token's own fingerprint for one that is not. */
    private static function accountOfToken(string $token): string
    {
        $account = self::$accountOf !== null ? (self::$accountOf)($token) : null;

        return $account ?? 'token-' . substr(hash('sha256', $token), 0, 16);
    }

    /** @return array{date: string, counts: array<string, int>} today's counts; none from another day */
    private static function read(): array
    {
        $today = self::today();
        $path = self::path();
        $parsed = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!is_array($parsed) || ($parsed['date'] ?? null) !== $today || !is_array($parsed['counts'] ?? null)) {
            return ['date' => $today, 'counts' => []];
        }

        return ['date' => $today, 'counts' => array_filter($parsed['counts'], 'is_int')];
    }

    /** @param array{date: string, counts: array<string, int>} $state */
    private static function write(array $state): void
    {
        $path = self::path();
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }

        // Written beside and renamed over, so a reader never sees half a file.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false || !rename($temporary, $path)) {
            throw new \RuntimeException("Cannot write {$path}");
        }
    }

    private static function path(): string
    {
        return self::$file ?? Config::home() . '/antigravity-429.json';
    }

    private static function today(): string
    {
        return self::$today !== null ? (self::$today)() : date('Y-m-d');
    }
}
