<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Client for Antigravity's quota and model availability endpoints.
 *
 * Calls:
 * - `v1internal:loadCodeAssist` (user metadata, tier, project ID)
 * - `v1internal:retrieveUserQuotaSummary` (5h and weekly quota pools for Gemini & Claude/GPT)
 * - `v1internal:fetchAvailableModels` (runtime models and their quota remaining fraction)
 *
 * What goes out is pi-antigravity's `usage/usage.ts`, request for request: the endpoints and
 * their order (`Discovery::endpointCandidates()`), the headers, the bodies and the project in
 * them. Upstream sends the three at once; here they go one after another, in its order.
 */
final class QuotaClient
{
    public const string DEFAULT_ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';

    public function __construct(
        private readonly ?HttpClient $http = null,
        private readonly ?string $endpoint = null,
    ) {
    }

    /**
     * Fetch complete account usage and quota status.
     *
     * @return array{
     *     projectId: string,
     *     endpoint: string,
     *     planLabel: ?string,
     *     groups: list<array{displayName: string, description: ?string, buckets: list<array{bucketId: string, displayName: string, window: ?string, resetTime: ?string, description: ?string, remainingFraction: float}>}>,
     *     quotaError: ?string,
     *     models: list<array{modelId: string, displayName: string, remainingFraction: ?float, resetTime: ?string}>,
     *     defaultAgentModelId: ?string,
     * }
     */
    public function fetchUsage(string $token, ?string $projectId = null): array
    {
        $http = $this->http ?? new HttpClient(15.0);
        $candidates = $this->endpoint !== null ? [$this->endpoint] : Discovery::endpointCandidates();
        // `creds.projectId || resolveProjectId(...)`: the credential's, else the default id.
        $initialProject = $projectId !== null && $projectId !== '' ? $projectId : AntigravityOauth::defaultProjectId('antigravity-default');

        // 1. loadCodeAssist
        $assistData = $this->postJsonSafe($http, $candidates, '/v1internal:loadCodeAssist', $token, [
            'metadata' => [
                'ideType' => 'ANTIGRAVITY',
                'platform' => 'PLATFORM_UNSPECIFIED',
                'pluginType' => 'GEMINI',
            ],
        ]);

        // `resolveProjectId()`: `ANTIGRAVITY_PROJECT_ID`, then the project the answer names, then
        // the credential's, then the default id.
        $explicit = trim(Discovery::env('PROJECT_ID') ?? '');
        $resolvedProject = $explicit !== '' ? $explicit : (AntigravityOauth::extractProjectId($assistData) ?? $initialProject);

        // 2. retrieveUserQuotaSummary
        $quotaData = null;
        $quotaError = null;
        try {
            $quotaRes = $this->postJson($http, $candidates, '/v1internal:retrieveUserQuotaSummary', $token, []);
            $quotaData = $quotaRes['data'];
            $endpointUsed = $quotaRes['endpoint'];
        } catch (RateLimited $e) {
            throw $e;
        } catch (Throwable $e) {
            $quotaError = $e->getMessage();
            $endpointUsed = $candidates[0];
        }

        // 3. fetchAvailableModels — upstream's `fetchAvailableModelsCatalog()`: every endpoint,
        // merged, under the project known before the first answer; with no catalog from any of
        // them the whole read fails, as upstream's does.
        $modelsData = Discovery::fetchAvailableModelsCatalog($token, $initialProject, http: $this->http, endpoints: $candidates)['data'];

        $groups = [];
        if (is_array($quotaData) && isset($quotaData['groups']) && is_array($quotaData['groups'])) {
            $groups = self::parseQuotaGroups($quotaData['groups']);
        }

        [$models, $defaultAgentModelId] = self::parseAvailableModels($modelsData);

        $planLabel = null;
        if (is_array($assistData)) {
            $planLabel = self::parsePlanLabel($assistData);
        }

        return [
            'projectId' => $resolvedProject,
            'endpoint' => $endpointUsed,
            'planLabel' => $planLabel,
            'groups' => $groups,
            'quotaError' => $quotaError,
            'models' => $models,
            'defaultAgentModelId' => $defaultAgentModelId,
        ];
    }

    /**
     * Format a summary of quota pools with progress bars.
     *
     * Two things here are this port's and not pi-antigravity's, at the developer's request, after
     * Google's own CLI: each group's description is shown under its name (the deployment's line
     * naming the models that share the pool), and with `$paint` the parts are coloured — a bar by
     * how much is left. Without `$paint` the text is plain, which is what a page or a pipe gets.
     *
     * @param array{planLabel: ?string, groups: list<array{displayName: string, description?: ?string, buckets: list<array{displayName: string, remainingFraction: float, resetTime: ?string}>}>, quotaError: ?string} $usage
     * @param (Closure(string, string): string)|null $paint a role — `heading`, `note`, `label`, or
     *        `plenty` / `low` / `nearlyOut` for a bar — and the text to colour for it
     */
    public static function formatUsageSummary(array $usage, ?Closure $paint = null): string
    {
        $paint ??= static fn (string $role, string $text): string => $text;
        $lines = [];

        if (!empty($usage['planLabel'])) {
            $lines[] = $paint('label', $usage['planLabel']);
        }

        if (empty($usage['groups'])) {
            if (!empty($usage['quotaError'])) {
                $lines[] = $paint('label', self::quotaErrorNote($usage['quotaError']));
            } else {
                $lines[] = $paint('label', 'No quota groups returned.');
            }

            return implode("\n", $lines);
        }

        foreach ($usage['groups'] as $group) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = $paint('heading', $group['displayName']);
            $description = trim((string) ($group['description'] ?? ''));
            if ($description !== '') {
                $lines[] = '  ' . $paint('note', $description);
            }
            foreach ($group['buckets'] as $bucket) {
                $fraction = $bucket['remainingFraction'];
                $percent = round($fraction * 100, 1);
                $bar = $paint(self::barRole($fraction), self::progressBar($fraction));
                $reset = self::formatReset($bucket['resetTime'] ?? null);
                $lines[] = "  {$bar} " . $paint('label', "{$bucket['displayName']}: {$percent}% left · resets {$reset}");
            }
        }

        return implode("\n", $lines);
    }

    /** What a bar is coloured as: half or more left, a fifth or more, less than that. */
    public static function barRole(float $remaining): string
    {
        return match (true) {
            $remaining >= 0.5 => 'plenty',
            $remaining >= 0.2 => 'low',
            default => 'nearlyOut',
        };
    }

    /**
     * Format models list with remaining fractions.
     *
     * @param array{projectId?: string, defaultAgentModelId?: string, models: list<array{modelId: string, remainingFraction: ?float, resetTime: ?string}>} $usage
     */
    public static function formatModelsList(array $usage, bool $all = false): string
    {
        $lines = [];
        $lines[] = 'Antigravity available models';
        if (!empty($usage['projectId'])) {
            $lines[] = "project={$usage['projectId']}";
        }
        if (!empty($usage['defaultAgentModelId'])) {
            $lines[] = "defaultAgentModel={$usage['defaultAgentModelId']}";
        }
        $lines[] = '';

        $rows = $usage['models'] ?? [];
        if (!$all) {
            $rows = array_values(array_filter($rows, static fn (array $m): bool => !preg_match('/tab_|chat_/i', $m['modelId'] ?? '')));
        }

        if (empty($rows)) {
            $lines[] = 'No models returned.';

            return implode("\n", $lines);
        }

        foreach ($rows as $m) {
            $fraction = $m['remainingFraction'];
            $remStr = $fraction !== null ? sprintf('%5.1f%%', $fraction * 100) : '  n/a ';
            $reset = self::formatReset($m['resetTime'] ?? null);
            $bar = self::progressBar($fraction ?? 0.0, 10);
            $lines[] = sprintf('%-30s %s %s · resets %s', $m['modelId'], $bar, $remStr, $reset);
        }

        return implode("\n", $lines);
    }

    public static function progressBar(float $remaining, int $width = 20): string
    {
        $fraction = max(0.0, min(1.0, $remaining));
        $filled = (int) round($fraction * $width);
        $empty = $width - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('-', $empty) . ']';
    }

    public static function formatReset(?string $resetTime): string
    {
        if ($resetTime === null || $resetTime === '') {
            return 'n/a';
        }

        $ts = strtotime($resetTime);
        if ($ts === false) {
            return $resetTime;
        }

        $delta = $ts - time();
        if ($delta <= 0) {
            return 'now';
        }

        $totalMin = (int) round($delta / 60);
        $days = (int) floor($totalMin / (60 * 24));
        $hours = (int) floor(($totalMin % (60 * 24)) / 60);
        $mins = $totalMin % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }
        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }

        return "{$mins}m";
    }

    private static function quotaErrorNote(string $msg): string
    {
        if (preg_match('/SUBSCRIPTION_REQUIRED|#3501|(?:lack|missing).*license/i', $msg) === 1) {
            return "Aggregate quota summary needs a paid subscription (free-tier can't use that endpoint). Per-model usage is still available via /antigravity.models.";
        }

        return 'Aggregate quota summary unavailable: ' . substr($msg, 0, 160);
    }

    /**
     * @param list<string> $candidates
     * @return array{endpoint: string, data: mixed}
     */
    private function postJson(
        HttpClient $http,
        array $candidates,
        string $path,
        string $token,
        array $body,
    ): array {
        $lastError = 'No endpoint available';

        foreach ($candidates as $endpoint) {
            $url = rtrim($endpoint, '/') . $path;
            $headers = [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => AntigravityCli::userAgent(),
            ];

            try {
                $encoded = $body === [] ? '{}' : (json_encode((object) $body) ?: '{}');
                $response = $http->send(new Request('POST', $url, $headers, $encoded));
                $text = $response->body->all();
                $data = json_decode($text, true);

                if ($response->isSuccessful()) {
                    return ['endpoint' => $endpoint, 'data' => $data ?? $text];
                }

                $errMsg = is_array($data) && isset($data['error']['message'])
                    ? (string) $data['error']['message']
                    : $text;

                if ($response->status === 429) {
                    throw RateLimited::during("The request to {$path}", $text);
                }

                $lastError = "{$path} failed ({$response->status}): " . substr($errMsg, 0, 300);

                // For fatal client errors (not 403/404/5xx), don't keep hammering other candidates
                if (!in_array($response->status, [403, 404, 500, 502, 503, 504], true)) {
                    throw new RuntimeException($lastError);
                }
            } catch (RateLimited $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * @param list<string> $candidates
     */
    private function postJsonSafe(
        HttpClient $http,
        array $candidates,
        string $path,
        string $token,
        array $body,
    ): mixed {
        try {
            $res = $this->postJson($http, $candidates, $path, $token, $body);

            return $res['data'];
        } catch (RateLimited $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<mixed> $rawGroups
     * @return list<array{displayName: string, description: ?string, buckets: list<array{bucketId: string, displayName: string, window: ?string, resetTime: ?string, description: ?string, remainingFraction: float}>}>
     */
    private static function parseQuotaGroups(array $rawGroups): array
    {
        $groups = [];

        foreach ($rawGroups as $rawGroup) {
            if (!is_array($rawGroup)) {
                continue;
            }

            $buckets = [];
            $rawBuckets = $rawGroup['buckets'] ?? [];
            if (is_array($rawBuckets)) {
                foreach ($rawBuckets as $rawBucket) {
                    if (!is_array($rawBucket)) {
                        continue;
                    }
                    $fraction = isset($rawBucket['remainingFraction']) && is_numeric($rawBucket['remainingFraction'])
                        ? (float) $rawBucket['remainingFraction']
                        : 0.0;
                    $buckets[] = [
                        'bucketId' => (string) ($rawBucket['bucketId'] ?? $rawBucket['displayName'] ?? 'unknown'),
                        'displayName' => (string) ($rawBucket['displayName'] ?? $rawBucket['bucketId'] ?? 'Limit'),
                        'window' => isset($rawBucket['window']) ? (string) $rawBucket['window'] : null,
                        'resetTime' => isset($rawBucket['resetTime']) ? (string) $rawBucket['resetTime'] : null,
                        'description' => isset($rawBucket['description']) ? (string) $rawBucket['description'] : null,
                        'remainingFraction' => max(0.0, min(1.0, $fraction)),
                    ];
                }
            }

            if ($buckets !== [] || isset($rawGroup['displayName'])) {
                $groups[] = [
                    'displayName' => (string) ($rawGroup['displayName'] ?? 'Quota group'),
                    'description' => isset($rawGroup['description']) ? (string) $rawGroup['description'] : null,
                    'buckets' => $buckets,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: list<array{modelId: string, displayName: string, remainingFraction: ?float, resetTime: ?string}>, 1: ?string}
     */
    private static function parseAvailableModels(array $data): array
    {
        $models = [];
        $rawModels = $data['models'] ?? [];

        if (is_array($rawModels)) {
            foreach ($rawModels as $modelId => $info) {
                if (!is_array($info)) {
                    continue;
                }

                $fraction = null;
                $resetTime = null;
                if (isset($info['quotaInfo']) && is_array($info['quotaInfo'])) {
                    if (isset($info['quotaInfo']['remainingFraction']) && is_numeric($info['quotaInfo']['remainingFraction'])) {
                        $fraction = max(0.0, min(1.0, (float) $info['quotaInfo']['remainingFraction']));
                    }
                    if (isset($info['quotaInfo']['resetTime']) && is_string($info['quotaInfo']['resetTime'])) {
                        $resetTime = $info['quotaInfo']['resetTime'];
                    }
                }

                $models[] = [
                    'modelId' => (string) $modelId,
                    'displayName' => (string) ($info['displayName'] ?? $modelId),
                    'remainingFraction' => $fraction,
                    'resetTime' => $resetTime,
                ];
            }
        }

        $defaultAgentModelId = isset($data['defaultAgentModelId']) && is_string($data['defaultAgentModelId'])
            ? $data['defaultAgentModelId']
            : null;

        return [$models, $defaultAgentModelId];
    }

    /**
     * The account's AI credit balances — pi-antigravity 0.13's `fetchRemainingCredits()`, without
     * its `overagesEnabled` (that is `Credits::status()`, which needs no request): the paid tier's
     * `availableCredits`, else the current tier's, else the answer's own, and the plan's label.
     *
     * @return array{availableCredits: list<array{creditType: string, creditAmount: float, minimumCreditAmountForUsage: ?float}>|null, planLabel: ?string}
     */
    public function fetchRemainingCredits(string $token, ?string $projectId = null): array
    {
        $http = $this->http ?? new HttpClient(15.0);
        $assistData = $this->postJsonSafe($http, $this->endpoint !== null ? [$this->endpoint] : Discovery::endpointCandidates(), '/v1internal:loadCodeAssist', $token, [
            'metadata' => [
                'ideType' => 'ANTIGRAVITY',
                'platform' => 'PLATFORM_UNSPECIFIED',
                'pluginType' => 'GEMINI',
            ],
        ]);
        $assistData = is_array($assistData) ? $assistData : [];
        $credits = null;
        $planLabel = null;

        foreach (['paidTier', 'currentTier'] as $key) {
            $tier = $assistData[$key] ?? null;

            // `parseTier()`: a tier with neither an id nor a name is no tier.
            if (!is_array($tier) || (empty($tier['id']) && empty($tier['name']))) {
                continue;
            }

            $credits ??= self::parseCredits($tier['availableCredits'] ?? $tier['available_credits'] ?? null);

            if ($planLabel === null && !empty($tier['name']) && is_scalar($tier['name'])) {
                $planLabel = $tier['name'] . (!empty($tier['id']) && is_scalar($tier['id']) ? " ({$tier['id']})" : '');
            }
        }

        return [
            'availableCredits' => $credits ?? self::parseCredits($assistData['availableCredits'] ?? $assistData['available_credits'] ?? null),
            'planLabel' => $planLabel,
        ];
    }

    /**
     * pi-antigravity's `parseCredits()`: the entries with a finite amount, under either spelling
     * of their keys; null when there is none.
     *
     * @return list<array{creditType: string, creditAmount: float, minimumCreditAmountForUsage: ?float}>|null
     */
    public static function parseCredits(mixed $list): ?array
    {
        if (!is_array($list) || !array_is_list($list)) {
            return null;
        }

        // `typeof amt === "number" ? amt : typeof amt === "string" ? Number(amt) : undefined`.
        $number = static function (mixed $value): ?float {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if (!is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed === '' ? 0.0 : (is_numeric($trimmed) ? (float) $trimmed : NAN);
        };
        $result = [];

        foreach ($list as $item) {
            if (!is_array($item) || array_is_list($item)) {
                continue;
            }

            $amount = $number($item['creditAmount'] ?? $item['credit_amount'] ?? null);

            if ($amount === null || !is_finite($amount)) {
                continue;
            }

            $type = $item['creditType'] ?? $item['credit_type'] ?? null;
            $result[] = [
                'creditType' => is_string($type) ? $type : 'GOOGLE_ONE_AI',
                'creditAmount' => $amount,
                'minimumCreditAmountForUsage' => $number($item['minimumCreditAmountForUsage'] ?? $item['minimum_credit_amount_for_usage'] ?? null),
            ];
        }

        return $result === [] ? null : $result;
    }

    /**
     * @param array<string, mixed> $assistData
     */
    private static function parsePlanLabel(array $assistData): ?string
    {
        $paid = $assistData['paidTier'] ?? null;
        if (is_array($paid) && (!empty($paid['name']) || !empty($paid['id']))) {
            $name = $paid['name'] ?? $paid['id'] ?? '';
            $id = $paid['id'] ?? '';

            return $id !== '' && $id !== $name ? "{$name} ({$id})" : $name;
        }

        $current = $assistData['currentTier'] ?? null;
        if (is_array($current) && (!empty($current['name']) || !empty($current['id']))) {
            $name = $current['name'] ?? $current['id'] ?? '';
            $id = $current['id'] ?? '';

            return $id !== '' && $id !== $name ? "{$name} ({$id})" : $name;
        }

        return null;
    }
}
