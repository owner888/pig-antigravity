<?php

declare(strict_types=1);

namespace PigAntigravity;

use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Pricing;

/**
 * The models this provider sells, as pig last saw them — the fallback the extension registers
 * before `Catalog` has a chance to replace them with what the deployment says today.
 *
 * This table used to be `Pig\Ai\Models::ANTIGRAVITY_MODELS`, the one subscription table in the
 * core registry that had no `generate-models.php` row because models.dev does not carry this
 * deployment. It is the extension's now, like everything else about this provider; what the
 * deployment's catalogue endpoint answers is in `Catalog`, and `scripts/fetch-antigravity-models.php`
 * in pig's repository prints this shape from it.
 *
 * `claude-sonnet-5-5` and friends are Anthropic's own ids under this provider: `Provider::$resold`
 * is true for exactly that reason, so `--model sonnet` keeps meaning Anthropic's.
 */
final class Models
{
    public const string PROVIDER = 'antigravity';

    /**
     * id => [name, context window, max output, reasoning, accepts images, thinkingLevelMap]
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: array<string, ?string>}>
     */
    private const array TABLE = [
        'gemini-3.8-flash' => ['Gemini 3.8 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.5-flash' => ['Gemini 3.5 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.5-flash-lite' => ['Gemini 3.5 Flash Lite (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3.1-flash-lite' => ['Gemini 3.1 Flash Lite (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-3-flash' => ['Gemini 3 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-2.5-flash' => ['Gemini 2.5 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-2.5-flash-lite' => ['Gemini 2.5 Flash Lite (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'claude-opus-5-5' => ['Claude Opus 5.5 (Antigravity)', 250_000, 64_000, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5 (Antigravity)', 200_000, 64_000, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gemini-2.5-pro' => ['Gemini 2.5 Pro (Antigravity)', 1_048_576, 65_535, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]],
        'gpt-oss-120b' => ['GPT-OSS 120B (Antigravity)', 131_072, 32_768, true, false, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => null, 'xhigh' => null, 'max' => null]],
    ];

    /**
     * List prices in USD per million tokens, by public model id: [input, output, cache read, cache
     * write] — pi-antigravity 0.13's per-model costs (`models/models.ts`), which "come from the
     * LiteLLM public catalog (the same source Pi's own cost reporting uses), which tracks Google's
     * published per-model pricing". A subscription is not billed by the token; this is what the
     * session's cost line shows the same usage would list at.
     *
     * "gemini-3.8-flash sits on Google's 50%-off launch rate ($0.75 / $3.75 / $0.075), which is
     * valid through 2026-12-31 and becomes $1.50 / $7.50 / $0.15 on 2027-01-01. Revisit this table
     * when that date passes." "Gemini bills cached content for storage per hour rather than per
     * token, so there is no cache-write rate to publish; the input rate is used."
     *
     * The ids are upstream's priced ones — its static rows and its `ANTIGRAVITY_DISCOVERED_MODEL_COSTS`
     * — and, for the two Claude rows pig ships under the deployment's current ids, the rate
     * upstream's Opus and Sonnet rows carry (which is what its family template gives 5.5 too). A
     * model that is not here has no price of its own: `cost()` answers null, "so callers can fall
     * back rather than publish a guess".
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private const array COSTS = [
        'gemini-3.8-flash' => [0.75, 3.75, 0.075, 0.75],
        'gemini-3.7-flash' => [0.75, 3.75, 0.075, 0.75],
        'gemini-3.6-flash' => [0.75, 3.75, 0.075, 0.75],
        'gemini-3.5-flash' => [1.5, 9.0, 0.15, 1.5],
        'gemini-3.5-flash-lite' => [0.3, 2.5, 0.03, 0.3],
        'gemini-3.1-flash-lite' => [0.25, 1.5, 0.025, 0.25],
        'gemini-2.5-flash' => [0.3, 2.5, 0.03, 0.3],
        'gemini-2.5-flash-lite' => [0.1, 0.4, 0.01, 0.1],
        'gemini-3.1-pro' => [2.0, 12.0, 0.2, 2.0],
        'gemini-2.5-pro' => [1.25, 10.0, 0.125, 1.25],
        'claude-opus-4-6' => [5.0, 25.0, 0.5, 6.25],
        'claude-opus-5-5' => [5.0, 25.0, 0.5, 6.25],
        'claude-sonnet-4-6' => [3.0, 15.0, 0.3, 3.75],
        'claude-sonnet-5-5' => [3.0, 15.0, 0.3, 3.75],
        'gpt-oss-120b' => [0.6, 2.4, 0.15, 0.6],
    ];

    /**
     * pi-antigravity's `getAntigravityModelCost()`: a model's list price by public id, in the
     * catalog's shape, or null when it is not priced here.
     *
     * @return array{input: float, output: float, cacheRead: float, cacheWrite: float}|null
     */
    public static function cost(string $id): ?array
    {
        $cost = self::COSTS[$id] ?? null;

        return $cost === null ? null : ['input' => $cost[0], 'output' => $cost[1], 'cacheRead' => $cost[2], 'cacheWrite' => $cost[3]];
    }

    /** @return list<Model> */
    public static function fallback(): array
    {
        $models = [];

        foreach (self::TABLE as $id => [$name, $window, $maxTokens, $reasoning, $images, $thinking]) {
            $cost = self::COSTS[$id] ?? null;
            $models[] = new Model(
                $id,
                $name,
                Api::Extension,
                self::PROVIDER,
                AntigravityApi::ENDPOINT,
                $window,
                $maxTokens,
                $reasoning,
                $images ? ['text', 'image'] : ['text'],
                $cost === null ? new Pricing() : new Pricing($cost[0], $cost[1], $cost[2], $cost[3]),
                thinkingLevelMap: $thinking,
                // What upstream's generator (`applyImageInputMetadata()`) gives a model of a provider
                // it has no limits for: an image-taking model the default resize profile, and
                // nothing more; a text-only model nothing at all.
                inputLimits: \Pig\Ai\Models::inputLimits(self::PROVIDER, $images ? ['text', 'image'] : ['text'], $window),
            );
        }

        return $models;
    }
}
