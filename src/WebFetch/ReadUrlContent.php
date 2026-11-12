<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use Pig\Ai\Http\HttpClient;
use Pig\Async\AbortSignal;
use PigAntigravity\Redaction;
use RuntimeException;
use Throwable;

/**
 * The `read_url_content` tool's answer — pi-antigravity's `features/read-url-content.ts`, from the
 * read to the text and details a model is given.
 *
 * "Returned text plus serialized details share the configured character cap. Provenance and the
 * saved-page footer count too. Small budgets can omit older attempt details; the omission count is
 * explicit." A page that does not fit is saved whole to a private temp file named in the answer and
 * in `details.fullOutputPath`; "a budget too small to answer leaves no file behind".
 */
final class ReadUrlContent
{
    /**
     * @param list<string>|null $endpoints the hosts to ask; the deployment's when null
     * @return array{0: string, 1: array<string, mixed>} the text, and the details
     * @throws WebFetchError|RuntimeException
     */
    public static function run(string $apiKey, string $url, ?AbortSignal $signal = null, ?HttpClient $http = null, ?array $endpoints = null): array
    {
        $maxChars = WebFetch::resolveMaxChars();
        $result = WebFetch::execute($apiKey, $url, signal: $signal, http: $http, endpoints: $endpoints);

        if ($signal?->aborted() ?? false) {
            throw new RuntimeException('Web fetch aborted');
        }

        $details = [
            'source' => $result['source'],
            'bytes' => $result['bytes'],
            'truncated' => false,
            'partial' => $result['partial'],
            'assessment' => $result['assessment'],
            'representationUrl' => $result['representationUrl'] === null ? null : Redaction::redactSecrets($result['representationUrl']),
            'attempts' => $result['attempts'],
            'omittedAttempts' => 0,
            'fullOutputPath' => null,
        ];

        // What `JSON.stringify(details)` counts: a key whose value is undefined is not written.
        $serialized = static fn (array $details): array => array_filter($details, static fn (mixed $value, string $key): bool => $value !== null || $key === 'partial', ARRAY_FILTER_USE_BOTH);
        $size = static function () use (&$details, $serialized): int {
            return Text::length((string) json_encode($serialized($details), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        };
        $detailsBudget = (int) floor($maxChars * 0.4);

        while ($size() > $detailsBudget && count($details['attempts']) > 1) {
            array_shift($details['attempts']);
            $details['omittedAttempts']++;
        }

        if ($size() > $detailsBudget && count($details['attempts']) === 1) {
            $last = $details['attempts'][0];
            $details['attempts'] = [array_filter(['source' => $last['source'], 'stage' => $last['stage'] ?? null, 'outcome' => $last['outcome']], static fn (mixed $value): bool => $value !== null)];
        }

        if ($size() > $detailsBudget) {
            $details['representationUrl'] = null;
        }

        if ($size() > $detailsBudget && $details['attempts'] !== []) {
            $details['attempts'] = [];
            $details['omittedAttempts']++;
        }

        $full = $result['content'];
        $captured = [...$result, 'maxChars' => $maxChars - $size()];
        $footer = '';
        $saved = null;

        if (Text::length(WebFetch::formatHeader($captured)) + Text::length($full) > $captured['maxChars']) {
            // "Pi's convention for long tool output: show the start, keep the whole on disk."
            $saved = SavedPage::save(WebFetch::formatHeader($result) . $full, 'md');
            $footer = $saved->note(Text::length($full));
            $details['fullOutputPath'] = $saved->path;
            $details['truncated'] = true;
            // "The path is part of details too, so charge it before formatting."
            $captured['maxChars'] = $maxChars - $size();
        }

        try {
            if ($signal?->aborted() ?? false) {
                throw new RuntimeException('Web fetch aborted');
            }

            $text = WebFetch::formatResult($captured, $footer);
        } catch (Throwable $error) {
            // "No answer will name the file, so leave none behind."
            $saved?->discard();

            throw $error;
        }

        return [$text, $serialized($details)];
    }
}
