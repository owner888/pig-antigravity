<?php

declare(strict_types=1);

namespace PigAntigravity;

use Pig\Ai\ProviderError;
use Pig\CodingAgent\Config;

/**
 * Gemini Enterprise routing — pi-antigravity 0.13's `client/enterprise.ts`.
 *
 * "Consumer prompts stay on cloudcode-pa. A regional enterprise license (error #3501) must not fall
 * back there: Google documents separate Business AI Code hosts, and a consumer URL both fails the
 * license check and leaves the configured region. Inference is sent only to the host for the
 * selected location, using the enterprise generate path:
 * `/v1beta/projects/{project}/locations/{location}:streamGenerateContent`. The body keeps the
 * Gemini request fields and names the license tier. Unknown consumer envelope fields are dropped
 * because the enterprise API rejects them."
 *
 * Asked for with `ANTIGRAVITY_LOCATION=eu|us` (or `ANTIGRAVITY_ENTERPRISE=1`), or by the official
 * CLI's `~/.gemini/antigravity-cli/settings.json` naming a regional location; it needs the
 * license's project (`ANTIGRAVITY_ENTERPRISE_PROJECT`) and tier (`ANTIGRAVITY_USER_TIER`). A
 * configuration that asks for it and is incomplete is an error, never a request to the consumer
 * endpoint.
 */
final class Enterprise
{
    private const array LOCATIONS = ['global', 'us', 'eu'];

    private const string PROJECT_ID = '/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/D';

    private const string USER_TIER = '/^[a-z0-9][a-z0-9._-]{0,80}$/D';

    private function __construct(
        public readonly string $location,
        public readonly string $projectId,
        public readonly string $userTier,
        public readonly string $host,
    ) {
    }

    /** pi-antigravity's `enterpriseHost()`. */
    public static function host(string $location): string
    {
        return $location === 'global' ? 'https://businessaicode.googleapis.com' : "https://businessaicode.{$location}.rep.googleapis.com";
    }

    /** pi-antigravity's `enterpriseStreamUrl()`. */
    public function streamUrl(): string
    {
        return "{$this->host}/v1beta/projects/" . rawurlencode($this->projectId) . "/locations/{$this->location}:streamGenerateContent?alt=sse";
    }

    /**
     * pi-antigravity's `resolveEnterpriseTarget()`: "an explicit enterprise target, or undefined
     * for the consumer path. `eu` / `us` (and `ANTIGRAVITY_ENTERPRISE=1`) never silently use
     * cloudcode-pa."
     *
     * @param string|null $cliSettings the official CLI's settings file; the person's own when null
     * @throws ProviderError when enterprise routing is asked for and its configuration is not whole
     */
    public static function resolve(?string $cliSettings = null): ?self
    {
        $settings = self::readCliSettings($cliSettings ?? Config::userHome() . '/.gemini/antigravity-cli/settings.json');
        $license = $settings['selectedLicense'] ?? $settings['license'] ?? null;
        $license = is_array($license) && ($license === [] || !array_is_list($license)) ? $license : [];
        $forced = in_array(strtolower(trim(Discovery::env('ENTERPRISE') ?? '')), ['1', 'true', 'yes', 'on'], true);
        $cloudLocation = getenv('GOOGLE_CLOUD_LOCATION');

        $location = Discovery::env('LOCATION')
            ?? self::field($settings, 'location')
            ?? self::field($license, 'location')
            ?? ($forced ? (is_string($cloudLocation) && $cloudLocation !== '' ? $cloudLocation : 'global') : '');
        $location = strtolower(trim($location));

        if ($location !== '' && !in_array($location, self::LOCATIONS, true)) {
            throw new ProviderError('ANTIGRAVITY_LOCATION must be global, us, or eu. The prompt was not sent.');
        }

        if ($location !== 'eu' && $location !== 'us' && !$forced) {
            return null;
        }

        $location = $location !== '' ? $location : 'global';
        $env = static function (string $name): ?string {
            $value = trim(Discovery::env($name) ?? '');

            return $value !== '' ? $value : null;
        };
        $projectId = $env('ENTERPRISE_PROJECT')
            ?? self::field($settings, 'project') ?? self::field($settings, 'projectId') ?? self::field($settings, 'licenseProject')
            ?? self::field($license, 'project') ?? self::field($license, 'projectId') ?? '';
        $userTier = $env('USER_TIER')
            ?? self::field($settings, 'userTier') ?? self::field($settings, 'entitlementTier')
            ?? self::field($license, 'userTier') ?? self::field($license, 'tier') ?? '';

        if (preg_match(self::PROJECT_ID, $projectId) !== 1) {
            throw new ProviderError("Gemini Enterprise routing needs ANTIGRAVITY_ENTERPRISE_PROJECT set to the license's Google Cloud project id. Refusing to send this prompt to the consumer endpoint.");
        }

        if (preg_match(self::USER_TIER, $userTier) !== 1) {
            throw new ProviderError('Gemini Enterprise routing needs ANTIGRAVITY_USER_TIER from the selected license (for example the tier id shown by agy). Refusing to send this prompt to the consumer endpoint.');
        }

        return new self($location, $projectId, $userTier, self::host($location));
    }

    /** pi-antigravity's `describeEnterpriseRouting()`, for `/antigravity.doctor`. */
    public static function describe(?string $cliSettings = null): string
    {
        try {
            $target = self::resolve($cliSettings);
        } catch (ProviderError $error) {
            // The doctor reports a broken configuration; it is the request that refuses to go.
            return "enterprise misconfigured ({$error->getMessage()})";
        }

        return $target === null ? 'consumer' : "enterprise location={$target->location} host={$target->host} project={$target->projectId}";
    }

    /**
     * pi-antigravity's `toEnterpriseGenerateBody()`: "Allowlisted enterprise generate body. Drops
     * the consumer envelope."
     *
     * @param array<string, mixed> $payload the consumer envelope `AntigravityApi::body()` builds
     * @return array<string, mixed>
     */
    public function generateBody(array $payload): array
    {
        $request = is_array($payload['request'] ?? null) ? $payload['request'] : [];
        $body = [
            'contents' => $request['contents'] ?? [],
            'aicode' => ['experience' => $payload['model'] ?? ''],
            'entitlement' => ['userTier' => $this->userTier],
        ];

        foreach (['systemInstruction', 'tools', 'toolConfig', 'generationConfig', 'labels'] as $key) {
            if (!empty($request[$key])) {
                $body[$key] = $request[$key];
            }
        }

        return $body;
    }

    /**
     * A string field of a settings object, trimmed, or null when it is absent or empty.
     *
     * @param array<mixed> $record
     */
    private static function field(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /** @return array<mixed> the file's object; empty when there is no file or it is not one */
    private static function readCliSettings(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $parsed = json_decode((string) file_get_contents($path), true);

        return is_array($parsed) && !array_is_list($parsed) ? $parsed : [];
    }
}
