<?php

declare(strict_types=1);

namespace PigAntigravity;

use Pig\CodingAgent\Config;
use Pig\CodingAgent\Settings;
use RuntimeException;

/**
 * Google One AI credit overages — pi-antigravity 0.13's `utils/credits.ts`.
 *
 * A subscription's quota pools run out; an account that has bought AI credits can have a request
 * spend those instead, when the request says so (`enabledCreditTypes: ["GOOGLE_ONE_AI"]`). That is
 * the person's money, so it is **off unless they turned it on**: an environment variable, their
 * own pig settings file, or the official CLI's settings, asked in that order.
 *
 * Read from the person's settings *file* and not from the session's merged settings, as upstream
 * reads `~/.pi/agent/settings.json`: a project's `.pig/settings.json` must not be able to switch
 * on spending. Written through pig's `Settings`, the one writer of that file.
 */
final class Credits
{
    /** What a request carries when overages are on. */
    public const array CREDIT_TYPES = ['GOOGLE_ONE_AI'];

    private const array TRUE_WORDS = ['1', 'true', 'yes', 'on', 'enable', 'enabled'];

    private const array FALSE_WORDS = ['0', 'false', 'no', 'off', 'disable', 'disabled'];

    /**
     * pi-antigravity's `getCreditsStatus()`.
     *
     * @param string|null $pigSettings the person's settings file; `Config::home()`'s when null
     * @param string|null $cliSettings the official CLI's; `~/.gemini/antigravity-cli/settings.json` when null
     * @return array{enabled: bool, source: 'env'|'pig_settings'|'antigravity_settings'|'default', detail: string}
     */
    public static function status(?string $pigSettings = null, ?string $cliSettings = null): array
    {
        // 1. The environment: `ANTIGRAVITY_USE_CREDITS`, `ANTIGRAVITY_USE_G1_CREDITS`, `USE_G1_CREDITS`.
        $bare = getenv('USE_G1_CREDITS');
        $env = Discovery::env('USE_CREDITS') ?? Discovery::env('USE_G1_CREDITS') ?? (is_string($bare) ? $bare : null);

        if ($env !== null) {
            $word = strtolower(trim($env));
            $isTrue = in_array($word, self::TRUE_WORDS, true);

            if ($isTrue || in_array($word, self::FALSE_WORDS, true)) {
                return ['enabled' => $isTrue, 'source' => 'env', 'detail' => "Environment variable (ANTIGRAVITY_USE_CREDITS={$env})"];
            }
        }

        // 2. pig's settings: `useG1Credits`, `useCredits`, `antigravity.useCredits`, `antigravity.useG1Credits`.
        $pig = self::readObject($pigSettings ?? Config::home() . '/settings.json');
        $nested = is_array($pig['antigravity'] ?? null) ? $pig['antigravity'] : [];

        foreach ([['useG1Credits', $pig], ['useCredits', $pig], ['useCredits', $nested, 'antigravity.'], ['useG1Credits', $nested, 'antigravity.']] as $ask) {
            $value = $ask[1][$ask[0]] ?? null;

            if (is_bool($value)) {
                return ['enabled' => $value, 'source' => 'pig_settings', 'detail' => '~/.pig/agent/settings.json (' . ($ask[2] ?? '') . $ask[0] . ': ' . ($value ? 'true' : 'false') . ')'];
            }
        }

        // 3. The Antigravity CLI's own settings.
        $cli = self::readObject($cliSettings ?? self::cliSettingsPath());

        foreach ([true, false] as $state) {
            if (($cli['useG1Credits'] ?? null) === $state || ($cli['useAiCredits'] ?? null) === $state) {
                return ['enabled' => $state, 'source' => 'antigravity_settings', 'detail' => '~/.gemini/antigravity-cli/settings.json (useG1Credits: ' . ($state ? 'true' : 'false') . ')'];
            }
        }

        return ['enabled' => false, 'source' => 'default', 'detail' => 'Default (disabled; enable with /antigravity.credits on)'];
    }

    /** pi-antigravity's `isCreditsEnabled()`. */
    public static function enabled(): bool
    {
        return self::status()['enabled'];
    }

    /**
     * pi-antigravity's `setCreditsEnabled()`: `useG1Credits` in the person's settings, and in the
     * official CLI's file "only when that file already exists and parses. Never create it, and
     * never overwrite it after a parse error."
     */
    public static function set(Settings $settings, bool $enabled, ?string $cliSettings = null): void
    {
        $settings->set('useG1Credits', $enabled);

        $path = $cliSettings ?? self::cliSettingsPath();
        $cli = self::readObject($path);

        if ($cli === null) {
            return;
        }

        $cli['useG1Credits'] = $enabled;

        if (file_put_contents($path, json_encode($cli, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) {
            throw new RuntimeException("Failed to update {$path}");
        }
    }

    private static function cliSettingsPath(): string
    {
        return Config::userHome() . '/.gemini/antigravity-cli/settings.json';
    }

    /**
     * A settings file's object, or null when there is no such file or it is not a JSON object —
     * "Unreadable settings leave credit overages disabled."
     *
     * @return array<string, mixed>|null
     */
    private static function readObject(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $parsed = json_decode((string) file_get_contents($path), true);

        return is_array($parsed) && ($parsed === [] || !array_is_list($parsed)) ? $parsed : null;
    }
}
