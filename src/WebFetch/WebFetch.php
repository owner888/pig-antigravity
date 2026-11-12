<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use ArrayObject;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use PigAntigravity\AntigravityApi;
use PigAntigravity\Discovery;
use PigAntigravity\Redaction;
use RuntimeException;
use Throwable;

/**
 * "Read a public page through Google's Antigravity fetch service" — pi-antigravity 0.13's
 * `web-fetch/web-fetch.ts` and `header.ts`.
 *
 * "This integration uses the Google-only retrieval path observed in the official `agy` CLI. The
 * crawl runs on Google's side using the account's OAuth token:
 *
 *   1. `v1internal:rewriteUri` resolves a redirect chain and reports the final URI.
 *   2. `v1internal:fetchFromTrawlerCache` answers from Google's crawl cache, and a 404 there means
 *      "not cached" rather than "no such page".
 *   3. The same RPC with `liveFetch: true` retries missing or inadequate copies.
 *
 * The response is crawled source, usually HTML. Inspection preserves metadata and recovery links
 * before Markdown cleanup; output is capped before reaching a model. No local HTTP client or
 * JavaScript renderer contacts the target site."
 *
 * A result is `{requestedUrl, redirected, resolvedUrl, content, title, description, updated,
 * source, bytes, truncated, partial, assessment, notes, author, published, language,
 * representationUrl, attempts, maxChars}`: `source` is `cache` or `live`; `partial` is true for a
 * copy that is cut off, false for a whole one, null when the source cannot say.
 */
final class WebFetch
{
    public const int DEFAULT_MAX_CHARS = 60_000;

    public const int TIMEOUT_MS = 10_000;

    public const int MAX_RESPONSE_BYTES = 5_000_000;

    private const string REWRITE_URI_PATH = '/v1internal:rewriteUri';

    private const string TRAWLER_FETCH_PATH = '/v1internal:fetchFromTrawlerCache';

    public const string PDF_GUIDANCE = 'This tool does not support PDF content. Do not retry this URL with read_url_content. '
        . 'If permitted, use an available download tool or terminal to download it directly, then read the local file with a PDF reader. '
        . 'Downloading alone does not extract its text.';

    /** "A small leading text window for signature detection, not PDF parsing." */
    private const int PDF_HEADER_WINDOW = 1_024;

    /** The trail of the last read, for `/antigravity.doctor`: "the bounded fetch history". */
    private static ?string $lastTrace = null;

    public static function lastTrace(): ?string
    {
        return self::$lastTrace;
    }

    /**
     * "Validate a target URL, returning its normalized form.
     *
     * The fetch runs on Google's machines, so a private address is not a request-forgery risk here,
     * but it can never be reached from there: rejecting it locally turns a confusing remote failure
     * into a clear message. URLs with embedded credentials are refused because they would be sent
     * to a third party. Sensitive query values are still sent with the request, but are redacted
     * from displayed output and errors."
     *
     * @throws FetchRefused
     */
    public static function validateFetchTarget(string $raw): string
    {
        $checked = PublicUrl::check($raw);

        if ($checked['ok']) {
            return $checked['url'];
        }

        throw new FetchRefused(PublicUrl::MESSAGES[$checked['reason']], unsafeTarget: $checked['reason'] !== 'invalid');
    }

    private static function pdfError(?string $url = null): FetchRefused
    {
        return new FetchRefused(($url !== null ? 'Unsupported representation ' . Redaction::redactSecrets($url) . ': ' : '') . self::PDF_GUIDANCE, fatal: true, status: 415);
    }

    /** "PDF detection is not limited to a URL suffix; inspect the returned representation too." */
    private static function isPdfContent(string $content, ?string $contentType): bool
    {
        // "A PDF signature must begin a line, followed by a version. Prose mentioning "%PDF-1.7"
        // is not a binary document."
        return Media::kind($contentType) === 'pdf' || preg_match('/(?:^|[\r\n])%PDF-\d\.\d(?:[\r\n]|$)/D', Text::cap($content, self::PDF_HEADER_WINDOW)) === 1;
    }

    /** "Reject known PDF URLs with guidance for the calling agent." */
    private static function rejectPdfUrl(string $url): void
    {
        if (preg_match('/\.pdf$/Di', Url::parse($url)?->path ?? '') === 1) {
            throw self::pdfError($url);
        }
    }

    /** "Some Cloud Code Assist methods answer with a `response` wrapper; accept both shapes." */
    private static function field(mixed $data, string ...$names): ?string
    {
        $body = Inspect::isRecord($data) && Inspect::isRecord($data['response'] ?? null) ? $data['response'] : $data;

        foreach (Inspect::isRecord($body) ? $names : [] as $name) {
            if (is_string($body[$name] ?? null) && !Text::blank($body[$name])) {
                return $body[$name];
            }
        }

        return null;
    }

    /** "Character budget, from the caller or `ANTIGRAVITY_WEB_FETCH_MAX_CHARS`." */
    public static function resolveMaxChars(?int $explicit = null): int
    {
        if ($explicit !== null && $explicit >= 1) {
            return $explicit;
        }

        $configured = Discovery::env('WEB_FETCH_MAX_CHARS');

        return $configured !== null && is_numeric(trim($configured)) && (float) $configured >= 1 && (float) $configured <= PHP_INT_MAX
            ? (int) floor((float) $configured)
            : self::DEFAULT_MAX_CHARS;
    }

    /**
     * "A cache miss is not a page failure; all other stages share the same status policy."
     *
     * @param 'cache'|'live'|'representation' $stage
     * @return 'content'|'missing'|'recover'|'stop'
     */
    public static function statusAction(string $stage, int $status): string
    {
        return match (true) {
            $status === 200 => 'content',
            $status === 401, $status === 403 => 'stop',
            $stage === 'cache' && ($status < 400 || $status === 404 || $status === 410) => 'missing',
            $status === 429, $status >= 500 => 'recover',
            default => 'stop',
        };
    }

    /** "Error carrying a stage-independent retry/refusal decision." */
    private static function fetchError(string $text, int $status, string $stage): FetchRefused
    {
        $detail = Text::cap(Text::repair(Redaction::redactSecrets(self::jsonOrTextError($text))), 400);
        $detail = $detail !== '' ? $detail : "HTTP {$status}";

        return new FetchRefused(
            $stage === 'cache'
                ? "The crawl cache could not answer (HTTP {$status}: {$detail})."
                : "The live fetch failed (HTTP {$status}: {$detail}). Try again later, or open the page in a browser.",
            fatal: self::statusAction($stage, $status) === 'stop',
            status: $status,
        );
    }

    /** pi-antigravity's `jsonOrTextError()`: the error body's `error.message`, else the body. */
    private static function jsonOrTextError(string $text): string
    {
        $parsed = json_validate($text) ? json_decode($text, true) : null;
        $message = is_array($parsed) && is_array($parsed['error'] ?? null) ? ($parsed['error']['message'] ?? null) : null;

        return is_string($message) ? $message : $text;
    }

    /**
     * One JSON request, its answer read without "allowing an unbounded body into the parser".
     *
     * @param array<string, mixed> $body
     * @return array{status: int, data: mixed, text: string}
     */
    private static function postJson(HttpClient $http, string $endpoint, string $path, string $token, array $body, AbortSignal $signal): array
    {
        $response = $http->send(new Request('POST', rtrim($endpoint, '/') . $path, Discovery::headers($token), (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $signal);
        $tooLarge = static fn (): FetchRefused => new FetchRefused('The fetch response was too large to read safely.', fatal: true, status: 413);
        $declared = $response->header('content-length');

        if ($declared !== null && is_numeric($declared) && (float) $declared > self::MAX_RESPONSE_BYTES) {
            throw $tooLarge();
        }

        $text = '';

        foreach ($response->body as $chunk) {
            $text .= $chunk;

            if (strlen($text) > self::MAX_RESPONSE_BYTES) {
                throw $tooLarge();
            }
        }

        return ['status' => $response->status, 'data' => $text !== '' && json_validate($text) ? json_decode($text, true) : null, 'text' => $text];
    }

    /** `safeError()`: an error's message with credentials taken out. */
    private static function safeError(Throwable $error): string
    {
        return Redaction::redactSecrets($error->getMessage());
    }

    /**
     * @param iterable<ArrayObject<string, string>|array<string, string>> $attempts
     */
    public static function formatAttemptTrail(iterable $attempts): string
    {
        $trail = [];

        foreach ($attempts as $attempt) {
            $trail[] = ($attempt['stage'] ?? $attempt['source']) . '@' . ($attempt['endpoint'] ?? 'Google') . ' ' . Text::elideMiddle($attempt['url'], 256) . ": {$attempt['outcome']}";
        }

        return implode(' → ', $trail);
    }

    /**
     * "Prefer readable article evidence, then a closed source, then additional content."
     *
     * @param array<string, mixed> $candidate
     */
    private static function candidateRank(array $candidate): int
    {
        $page = $candidate['inspection'];

        return (['readable' => 4_000_000, 'suspected-shell' => 1_000_000][$page['assessment']] ?? 0)
            + (['complete' => 2_000_000, 'unknown' => 1_000_000][$page['completeness']] ?? 0)
            + min(Text::length($page['rendered']), 999_999);
    }

    /**
     * "A readable fragment is usable; only positive signs of a problem trigger recovery."
     *
     * @param array<string, mixed> $candidate
     */
    private static function needsRecovery(array $candidate): bool
    {
        return $candidate['inspection']['assessment'] !== 'readable'
            || $candidate['inspection']['completeness'] === 'incomplete'
            // "Google cuts these sites' ordinary pages; only a verified copy is complete."
            || ($candidate['inspection']['completeness'] !== 'complete' && $candidate['representationUrl'] === null && Representation::site($candidate['resolvedUrl']) !== null);
    }

    /**
     * Read a page.
     *
     * @param list<string>|null $endpoints the hosts to ask; `Discovery::endpointCandidates()` when null
     * @return array<string, mixed> the result (see the class)
     * @throws WebFetchError in words safe to show
     */
    public static function execute(
        ?string $apiKey,
        string $url,
        bool $raw = false,
        ?int $maxChars = null,
        ?int $timeoutMs = null,
        ?AbortSignal $signal = null,
        ?HttpClient $http = null,
        ?array $endpoints = null,
    ): array {
        try {
            $result = self::fetchPage($apiKey, $url, $raw, $maxChars, $timeoutMs ?? self::TIMEOUT_MS, $signal, $http, $endpoints);
            self::$lastTrace = Text::clean(Redaction::redactSecrets(self::formatAttemptTrail($result['attempts'])), 6_000);

            return $result;
        } catch (WebFetchError $error) {
            self::$lastTrace = Text::clean(Redaction::redactSecrets(self::formatAttemptTrail($error->attempts)), 6_000);

            throw $error;
        } catch (Throwable $error) {
            // Anything else is told in the same shape, its cause's text redacted and nothing more kept.
            self::$lastTrace = null;
            $checked = PublicUrl::check($url);

            throw new WebFetchError($checked['ok'] ? Redaction::redactSecrets($checked['url']) : 'the supplied URL', self::safeError($error));
        }
    }

    /**
     * "Read through Google only: inspect cache, recover live when inadequate, and try one
     * advertised same-origin article representation. Keep the best usable copy if recovery fails;
     * never splice different versions of an article together."
     *
     * @param list<string>|null $endpointList
     * @return array<string, mixed>
     */
    private static function fetchPage(?string $apiKey, string $url, bool $raw, ?int $maxCharsAsked, int $timeoutMs, ?AbortSignal $callerSignal, ?HttpClient $http, ?array $endpointList): array
    {
        if ($callerSignal?->aborted() ?? false) {
            throw new RuntimeException('Web fetch aborted');
        }

        $requestedUrl = self::validateFetchTarget($url);
        self::rejectPdfUrl($requestedUrl);
        [$token] = AntigravityApi::credentials($apiKey);
        $maxChars = self::resolveMaxChars($maxCharsAsked);
        $http ??= new HttpClient($timeoutMs / 1000);
        $endpoints = $endpointList ?? Discovery::endpointCandidates();

        // One deadline for the whole read, and the caller's abort, as one signal.
        $controller = new AbortController();
        $signal = $controller->signal;
        $timedOut = false;
        $timer = Loop::get()->delay($timeoutMs / 1000, static function () use ($controller, &$timedOut): void {
            $timedOut = true;
            $controller->abort('The web fetch exceeded its deadline.');
        });
        $listener = $callerSignal?->onAbort(static fn () => $controller->abort('Web fetch aborted'));

        try {
            return self::retrieve($requestedUrl, $token, $raw, $maxChars, $http, $endpoints, $signal, $callerSignal, $timedOut);
        } finally {
            Loop::get()->cancel($timer);

            if ($listener !== null) {
                $callerSignal?->removeListener($listener);
            }
        }
    }

    /**
     * @param list<string> $endpoints
     * @return array<string, mixed>
     */
    private static function retrieve(string $requestedUrl, string $token, bool $raw, int $maxChars, HttpClient $http, array $endpoints, AbortSignal $signal, ?AbortSignal $callerSignal, bool &$timedOut): array
    {
        /** @var list<ArrayObject<string, string>> $attempts each mutated in place, as upstream's objects are */
        $attempts = [];
        $best = null;
        $lastError = 'no endpoint available';
        // "Google's copy, kept when it is beyond the conversion limits so the caller can save it."
        $unconvertedCopy = null;
        $lastContent = null;
        $requests = 0;
        $endpointAnswered = false;
        $alternateTried = false;
        $unavailable = [];
        $requested = [];

        // "Per-request dispatch owns endpoint failover; never repeat the whole retrieval flow."
        $request = static function (string $path, array $body) use (&$attempts, &$requests, &$endpointAnswered, &$unavailable, &$requested, &$lastError, $endpoints, $http, $token, $signal): array {
            if ($path === self::REWRITE_URI_PATH) {
                $attempts[] = new ArrayObject([
                    'url' => Text::clean(Redaction::redactSecrets((string) ($body['originalUri'] ?? '')), 2_048) ?? '',
                    'source' => 'cache',
                    'stage' => 'redirect',
                    'outcome' => 'transport failed',
                ]);
            }

            $attempt = $attempts === [] ? null : $attempts[count($attempts) - 1];

            foreach ($endpoints as $endpoint) {
                $key = (string) json_encode([$endpoint, $path, $body]);

                if (isset($unavailable[$endpoint]) || isset($requested[$key])) {
                    continue;
                }

                if ($requests >= 10) {
                    throw new FetchRefused('The bounded fetch request budget was exhausted. Open the page in a browser.', fatal: true);
                }

                $requested[$key] = true;
                $requests++;

                if ($attempt !== null) {
                    $attempt['endpoint'] = $endpoint;
                }

                try {
                    $response = self::postJson($http, $endpoint, $path, $token, $body, $signal);
                } catch (Throwable $error) {
                    if ($signal->aborted() || ($error instanceof FetchRefused && $error->fatal)) {
                        throw $error;
                    }

                    // A host that could not be asked: the next one. "Endpoint failover is transport-only."
                    $unavailable[$endpoint] = true;

                    if ($attempt !== null) {
                        // "Preserve this failed endpoint, then keep the caller's live record at the end
                        // so later inspection updates the endpoint that actually answered."
                        $failed = new ArrayObject([...$attempt->getArrayCopy(), 'outcome' => 'transport failed']);
                        array_splice($attempts, count($attempts) - 1, 0, [$failed]);
                    }

                    $lastError = Text::cap(Text::repair(self::safeError($error)), 400);

                    continue;
                }

                if ($attempt !== null) {
                    $attempt['outcome'] = "HTTP {$response['status']}";
                }

                if ($path === self::TRAWLER_FETCH_PATH) {
                    $endpointAnswered = true;
                }

                return $response;
            }

            // "No physical request corresponds to the pending record."
            if ($attempt !== null) {
                array_pop($attempts);
            }

            throw new FetchRefused("No Google endpoint could complete this request: {$lastError}", fatal: true);
        };

        $aborted = static function () use ($callerSignal): void {
            if ($callerSignal?->aborted() ?? false) {
                throw new RuntimeException('Web fetch aborted');
            }
        };
        $usable = static fn (?array $candidate): bool => $candidate !== null && (!Text::blank($candidate['inspection']['rendered']) || ($raw && !Text::blank($candidate['content'])));
        $retain = static function (array $candidate) use (&$best): void {
            if ($best === null || self::candidateRank($candidate) > self::candidateRank($best)) {
                $best = $candidate;
            }
        };
        $result = static function (array $candidate) use ($requestedUrl, $raw, $maxChars, &$attempts): array {
            return self::toResult($requestedUrl, $candidate, $raw ? $candidate['content'] : $candidate['inspection']['rendered'], $maxChars, $attempts);
        };
        $fatal = static fn (Throwable $error): bool => $error instanceof FetchRefused && $error->fatal;

        foreach (array_slice($endpoints, 0, 1) as $endpoint) {
            $endpointAnswered = false;
            $aborted();

            if ($timedOut) {
                break;
            }

            $resolvedUrl = self::resolveRedirect($requestedUrl, false, $request) ?? $requestedUrl;
            $aborted();
            self::rejectPdfUrl($resolvedUrl);
            $page = null;
            $alternatePage = null;

            foreach (['cache', 'live'] as $source) {
                $aborted();

                if ($timedOut) {
                    break;
                }

                $attempt = new ArrayObject(['url' => Text::clean(Redaction::redactSecrets($resolvedUrl), 2_048) ?? '', 'endpoint' => $endpoint, 'stage' => $source, 'source' => $source, 'outcome' => 'transport failed']);
                $attempts[] = $attempt;

                try {
                    $response = $request(self::TRAWLER_FETCH_PATH, $source === 'live' ? ['url' => $resolvedUrl, 'liveFetch' => true] : ['url' => $resolvedUrl]);
                    $aborted();
                    $attempt['outcome'] = "HTTP {$response['status']}";

                    if ($response['status'] !== 200) {
                        // "The cache saying "not here" leaves the live fetch to try."
                        if (self::statusAction($source, $response['status']) === 'missing') {
                            continue;
                        }

                        throw self::fetchError($response['text'], $response['status'], $source);
                    }

                    $contentType = self::field($response['data'], 'contentType', 'content_type', 'mimeType', 'mime_type');
                    $contentType = $contentType === null ? null : strtolower(trim(explode(';', $contentType)[0]));

                    if (Media::kind($contentType) === 'pdf') {
                        throw self::pdfError();
                    }

                    $content = self::field($response['data'], 'content', 'markdownContent');
                    $content = $content === null ? null : Text::repair($content);
                    $lastContent = $content;

                    if ($content === null) {
                        $attempt['outcome'] = 'empty response';
                        $lastError = preg_match('/error|message/i', $response['text']) === 1
                            ? Text::cap(Redaction::redactSecrets(self::jsonOrTextError($response['text'])), 400)
                            : 'Google returned no readable article content.';

                        continue;
                    }

                    if (self::isPdfContent($content, $contentType)) {
                        throw self::pdfError();
                    }

                    $candidate = ['content' => $content, 'resolvedUrl' => $resolvedUrl, 'representationUrl' => null, 'source' => $source, 'inspection' => self::inspectSource($content, $resolvedUrl, $contentType, $raw)];
                    $attempt['outcome'] = "{$candidate['inspection']['assessment']}; source {$candidate['inspection']['completeness']}";
                    $retain($candidate);

                    if ($candidate['inspection']['alternates'] !== [] && ($alternatePage === null || self::candidateRank($candidate) > self::candidateRank($alternatePage))) {
                        $alternatePage = $candidate;
                    }

                    if ($page === null || self::candidateRank($candidate) > self::candidateRank($page)) {
                        $page = $candidate;
                    }

                    if (!self::needsRecovery($candidate)) {
                        return $result($candidate);
                    }
                } catch (Throwable $error) {
                    // Every failure of one stage is weighed here: told at once when nothing usable
                    // is in hand and trying further is pointless, kept as the reason otherwise.
                    $aborted();
                    self::recordFailure($attempt, $error, $source !== 'cache' && $usable($best), $timedOut);

                    if ($fatal($error) && ($source === 'cache' || !$usable($best))) {
                        throw new WebFetchError($requestedUrl, self::safeError($error), self::attemptList($attempts));
                    }

                    $lastError = Text::cap(Text::repair(self::safeError($error)), 400);
                    $unconvertible = $error instanceof DocumentLimitError;

                    if ($unconvertible && $lastContent !== null && $lastContent !== '') {
                        $unconvertedCopy = str_replace(
                            [$requestedUrl, $resolvedUrl],
                            [Redaction::redactSecrets($requestedUrl), Redaction::redactSecrets($resolvedUrl)],
                            Text::repair($lastContent),
                        );
                    }

                    if (!$endpointAnswered || $unconvertible) {
                        break;
                    }
                }
            }

            // "Raw mode means the requested page source, not a substituted representation."
            $discovery = $alternatePage ?? $page;
            $representation = $raw ? null : Representation::for($resolvedUrl, $discovery['inspection']['alternates'][0] ?? null);

            if ($representation !== null && !$timedOut && !$alternateTried) {
                $alternateTried = true;
                $aborted();
                $representationAttempt = null;

                try {
                    $target = self::validateFetchTarget($representation->url);
                    self::rejectPdfUrl($target);
                    $redirected = $representation->resolve ? self::resolveRedirect($target, true, $request) : null;
                    $aborted();

                    if ($timedOut) {
                        break;
                    }

                    if ($redirected !== null && Url::parse($redirected)?->origin() !== Url::parse($target)?->origin()) {
                        $attempts[] = new ArrayObject(['url' => Text::clean(Redaction::redactSecrets($target), 2_048) ?? '', 'source' => 'live', 'endpoint' => $endpoint, 'stage' => 'representation', 'outcome' => 'cross-origin redirect rejected']);
                    } else {
                        $representationUrl = $redirected ?? $target;
                        $representationAttempt = new ArrayObject(['url' => Text::clean(Redaction::redactSecrets($representationUrl), 2_048) ?? '', 'endpoint' => $endpoint, 'stage' => 'representation', 'source' => 'live', 'outcome' => 'transport failed']);
                        $attempts[] = $representationAttempt;
                        self::rejectPdfUrl($representationUrl);
                        $response = $request(self::TRAWLER_FETCH_PATH, ['url' => $representationUrl, 'liveFetch' => true]);
                        $aborted();
                        $representationAttempt['outcome'] = "HTTP {$response['status']}";

                        if ($response['status'] === 200) {
                            $content = self::field($response['data'], 'content', 'markdownContent');
                            $content = $content === null ? null : Text::repair($content);
                            $reportedType = self::field($response['data'], 'contentType', 'content_type', 'mimeType', 'mime_type');
                            $reportedType = $reportedType === null ? null : strtolower(trim(explode(';', $reportedType)[0]));

                            if ($content !== null && self::isPdfContent($content, $reportedType)) {
                                throw self::pdfError();
                            }

                            $document = $content === null ? null : $representation->read($content, $reportedType, $resolvedUrl);

                            if ($document !== null && $content !== null && !Text::blank((string) $document['body'])) {
                                $articleUrl = $document['articleUrl'] ?? $resolvedUrl;
                                $inspection = Inspect::page($document['body'], $articleUrl, $document['contentType'] ?? null);

                                if (isset($document['completeness'])) {
                                    $inspection['completeness'] = $document['completeness'];
                                }

                                if (isset($document['notes'])) {
                                    $inspection['notes'] = $document['notes'];
                                }

                                $defined = static fn (array $metadata): array => array_filter($metadata, static fn (mixed $value): bool => $value !== null);
                                $inspection['metadata'] = [...($discovery['inspection']['metadata'] ?? []), ...$defined($document['metadata'] ?? []), ...$defined($inspection['metadata'])];
                                $alternateLanguage = $inspection['metadata']['language'] ?? $document['language'] ?? $representation->language;
                                $pageLanguage = $discovery['inspection']['metadata']['language'] ?? null;

                                if ($alternateLanguage !== null) {
                                    $inspection['metadata']['language'] = $alternateLanguage;
                                }

                                // "The alternate was chosen by base language, so the note compares the same."
                                $base = static fn (?string $language): ?string => $language === null ? null : strtolower(explode('-', $language)[0]);
                                $representationAttempt['outcome'] = $alternateLanguage !== null && $pageLanguage !== null && $base($alternateLanguage) !== $base($pageLanguage)
                                    ? "{$inspection['assessment']}; alternate language {$alternateLanguage}"
                                    : $inspection['assessment'];
                                $candidate = ['content' => $content, 'resolvedUrl' => $articleUrl, 'representationUrl' => $representationUrl, 'source' => 'live', 'inspection' => $inspection];
                                $retain($candidate);

                                if (!self::needsRecovery($candidate)) {
                                    return $result($candidate);
                                }
                            } else {
                                $representationAttempt['outcome'] = 'no supported article body';

                                if (preg_match('/error|message/i', $content ?? '') === 1) {
                                    $lastError = Text::cap(Redaction::redactSecrets(self::jsonOrTextError((string) $content)), 400);
                                }
                            }
                        } else {
                            $lastError = Text::cap(self::safeError(self::fetchError($response['text'], $response['status'], 'representation')), 400);
                            $representationAttempt['outcome'] = "HTTP {$response['status']}" . ($usable($best) ? '; retained usable copy' : '');
                        }
                    }
                } catch (Throwable $error) {
                    // The other copy is a recovery: its failure is only the turn's when nothing
                    // usable was read before it.
                    $aborted();

                    if ($representationAttempt !== null) {
                        self::recordFailure($representationAttempt, $error, $usable($best), $timedOut);
                    }

                    if ($fatal($error) && !$usable($best)) {
                        throw new WebFetchError($requestedUrl, self::safeError($error), self::attemptList($attempts));
                    }

                    $lastError = Text::cap(Text::repair(self::safeError($error)), 400);
                }
            }

            // "A useful partial result beats an error; recovery is bounded to this endpoint."
            if ($usable($best)) {
                return $result($best);
            }
        }

        $aborted();

        if ($usable($best)) {
            return $result($best);
        }

        if ($best !== null && $best['inspection']['completeness'] !== 'complete' && Markdown::looksLikeHtml($best['content'])) {
            return $result($best);
        }

        if ($best !== null) {
            $lastError = $best['inspection']['assessment'] === 'suspected-shell'
                ? 'This appears to be a JavaScript-rendered page; this tool cannot render it. Open it in a browser.'
                : 'Google returned no readable article content. The response may be truncated, blocked, or a page shell; try another representation or open it in a browser.';
        }

        if ($timedOut) {
            $lastError = 'The web fetch exceeded its overall 10-second deadline. Try again later or open the page in a browser.';
        }

        throw new WebFetchError($requestedUrl, $lastError, self::attemptList($attempts), $unconvertedCopy);
    }

    /**
     * @param list<ArrayObject<string, string>> $attempts
     * @return list<array{url: string, source: string, outcome: string, endpoint?: string, stage?: string}>
     */
    private static function attemptList(array $attempts): array
    {
        return array_map(static fn (ArrayObject $attempt): array => $attempt->getArrayCopy(), $attempts);
    }

    /**
     * "Inspect a page; raw mode keeps a source beyond the conversion limits as it is."
     *
     * @return array<string, mixed>
     */
    private static function inspectSource(string $content, string $url, ?string $contentType, bool $raw): array
    {
        try {
            return Inspect::page($content, $url, $contentType);
        } catch (DocumentLimitError $error) {
            if ($raw) {
                return Inspect::unconverted($content, $error->getMessage());
            }

            throw $error;
        }
    }

    /** @param ArrayObject<string, string> $attempt */
    private static function recordFailure(ArrayObject $attempt, Throwable $error, bool $retained, bool $timedOut): void
    {
        $status = $error instanceof FetchRefused ? $error->status : null;

        if ($timedOut) {
            $attempt['outcome'] = 'overall deadline exceeded';
        } elseif ($status === 415) {
            $attempt['outcome'] = 'unsupported PDF content';
        } elseif ($status === 413) {
            $attempt['outcome'] = 'response too large to read safely';
        } elseif ($status !== null) {
            $attempt['outcome'] = "HTTP {$status}";
        } elseif ($attempt['outcome'] === 'HTTP 200') {
            $attempt['outcome'] = 'content inspection failed: ' . (Text::clean(self::safeError($error), 120) ?? 'unknown error');
        }

        if ($retained) {
            $attempt['outcome'] .= '; retained usable copy';
        }
    }

    /**
     * "Resolve best-effort, but reject any unsafe destination the service reports."
     *
     * @param \Closure(string, array<string, mixed>): array{status: int, data: mixed, text: string} $request
     */
    private static function resolveRedirect(string $url, bool $strict, \Closure $request): ?string
    {
        $redirect = null;

        try {
            $response = $request(self::REWRITE_URI_PATH, ['originalUri' => $url]);

            if ($response['status'] !== 200) {
                if ($strict) {
                    throw new RuntimeException("Alternate redirect resolver failed (HTTP {$response['status']}).");
                }

                return null;
            }

            $redirect = self::field($response['data'], 'redirectUri', 'redirect_uri', 'rewrittenUri');
            $redirect = $redirect === null ? null : Text::trim($redirect);
        } catch (Throwable $error) {
            // "Original-page resolution is best-effort. Alternate recovery must fail closed."
            if ($strict) {
                throw $error;
            }
        }

        if ($redirect === null || $redirect === '' || $redirect === $url) {
            return null;
        }

        $absolute = Url::parse($redirect, $url)?->href();

        if ($absolute === null) {
            // "A malformed destination says nothing about the requested page; keep reading it."
            if ($strict) {
                throw new RuntimeException('The service reported a redirect that is not a URL.');
            }

            return null;
        }

        if ($absolute === $url) {
            return null;
        }

        try {
            return self::validateFetchTarget($absolute);
        } catch (FetchRefused $error) {
            // "An unsafe destination the service reports fails closed. A malformed or unsupported
            // target only costs the redirect, so the read continues."
            if ($strict || $error->unsafeTarget) {
                throw new RuntimeException('The service reported an unsafe redirect: ' . self::safeError($error), 0, $error);
            }

            return null;
        }
    }

    /**
     * "Format the rendered page, extract metadata, and apply the character budget."
     *
     * @param array<string, mixed> $candidate
     * @param list<ArrayObject<string, string>> $attempts
     * @return array<string, mixed>
     */
    private static function toResult(string $requestedUrl, array $candidate, string $rendered, int $maxChars, array $attempts): array
    {
        // "Redact caller/service URLs wherever the page echoes them, before the page is shown or saved."
        $rendered = Text::repair($rendered);

        foreach ([$requestedUrl, $candidate['resolvedUrl'], $candidate['representationUrl']] as $url) {
            if ($url !== null && $url !== '') {
                $rendered = str_replace($url, Redaction::redactSecrets($url), $rendered);
            }
        }

        $inspection = $candidate['inspection'];
        $metadata = $inspection['metadata'];
        $result = [
            'requestedUrl' => Redaction::redactSecrets($requestedUrl),
            'resolvedUrl' => Redaction::redactSecrets($candidate['resolvedUrl']),
            'redirected' => $requestedUrl !== $candidate['resolvedUrl'],
            'content' => $rendered,
            'title' => $metadata['title'] ?? null,
            'description' => $metadata['description'] ?? null,
            'updated' => $metadata['updated'] ?? null,
            'source' => $candidate['source'],
            'bytes' => strlen($candidate['content']),
            'truncated' => false,
            'partial' => ['complete' => false, 'incomplete' => true][$inspection['completeness']] ?? null,
            'assessment' => $inspection['assessment'],
            'notes' => $inspection['notes'] ?? [],
            'author' => $metadata['author'] ?? null,
            'published' => $metadata['published'] ?? null,
            'language' => $metadata['language'] ?? null,
            'representationUrl' => $candidate['representationUrl'] === null ? null : Redaction::redactSecrets($candidate['representationUrl']),
            'attempts' => self::attemptList($attempts),
            'maxChars' => $maxChars,
        ];
        $result['truncated'] = Text::length($rendered) + Text::length(self::formatHeader($result)) > $maxChars;

        return $result;
    }

    // ---- header.ts ---------------------------------------------------------------------------

    /**
     * "Format the page header, with bounded fields and redacted URLs."
     *
     * @param array<string, mixed> $result
     */
    public static function formatHeader(array $result): string
    {
        $maxChars = $result['maxChars'] ?? self::DEFAULT_MAX_CHARS;
        // "Provenance must fit inside the output budget, so a long URL cannot take it all."
        $headerBudget = max(0, $maxChars - min(256, intdiv($maxChars, 4)));
        $dropped = [];
        $build = static function (int $urlLimit, int $titleLimit) use ($result, &$dropped): array {
            $text = static fn (?string $value, int $limit): ?string => Text::clean($value === null ? null : Redaction::redactSecrets($value), $limit);
            $url = static fn (string $value): string => Text::elideMiddle(Text::clean(Redaction::redactSecrets($value), 4_096) ?? '', $urlLimit);
            $header = ['Page metadata (publisher claims):', 'Title: ' . ($text($result['title'] ?? null, $titleLimit) ?? 'Untitled page')];
            $add = static function (string $label, ?string $value) use (&$header, &$dropped): void {
                if ($value !== null && !isset($dropped[$label])) {
                    $header[] = "{$label}: {$value}";
                }
            };

            foreach (['Description' => 'description', 'Updated' => 'updated', 'Author' => 'author', 'Published' => 'published', 'Language' => 'language'] as $label => $field) {
                $add($label, $text($result[$field] ?? null, Markdown::METADATA_LIMITS[$field]));
            }

            if (($result['representationUrl'] ?? null) !== null) {
                $header[] = 'Representation: ' . $url($result['representationUrl']);
            }

            $attempts = $result['attempts'] ?? [];

            if (!isset($dropped['Retrieval']) && count($attempts) > 1) {
                $header[] = 'Retrieval: ' . implode(' → ', array_map(
                    static fn (array $attempt): string => ($attempt['stage'] ?? $attempt['source']) . '@' . (isset($attempt['endpoint']) ? (parse_url($attempt['endpoint'], PHP_URL_HOST) ?: $attempt['endpoint']) : 'Google') . ': ' . ($text($attempt['outcome'], 200) ?? ''),
                    $attempts,
                ));
            }

            $assessment = $result['assessment'] ?? null;

            if ($assessment !== null && $assessment !== 'readable') {
                $header[] = "Content assessment: {$assessment} (heuristic; source completeness does not establish article coverage).";
            }

            $header[] = 'Retrieval provenance:';

            if (($result['redirected'] ?? false) || $result['requestedUrl'] !== $result['resolvedUrl']) {
                $header[] = 'Requested: ' . $url($result['requestedUrl']);
            }

            $header[] = 'Source: ' . $url($result['resolvedUrl']) . ' (' . ($result['source'] === 'live' ? 'live' : 'cache') . ')';

            if ($assessment === 'empty' || $assessment === 'suspected-access-wall') {
                $header[] = 'Note: no reliable article was identified. Open the page in a browser or use another permitted source; do not keep retrying this URL.';
            }

            if ($assessment === 'suspected-shell') {
                $header[] = 'Note: this appears to be a JavaScript-rendered page; this tool cannot render it. Open it in a browser.';
            }

            $hasContent = !Text::blank($result['content']);

            if ($result['partial'] === true) {
                $header[] = $hasContent
                    ? "Note: the crawler's copy ends mid-page, so some content may be missing."
                    : "Note: the crawler's copy ends mid-page and holds no readable content.";
            } elseif ($result['partial'] === null) {
                $header[] = $hasContent
                    ? "Note: the crawler's copy has no clear end marker, so completeness is unknown."
                    : "Note: the crawler's copy has no clear end marker and holds no readable content.";
            }

            foreach ($result['notes'] ?? [] as $note) {
                $add('Note', $text($note, 300));
            }

            return $header;
        };
        $fits = static fn (array $header): bool => Text::length(implode("\n", $header)) + 7 <= $headerBudget;
        $urlLimit = max(64, min(2_048, intdiv($headerBudget, 4)));
        $titleLimit = Markdown::METADATA_LIMITS['title'];
        $header = $build($urlLimit, $titleLimit);

        // "Optional metadata must not crowd provenance and the next captured range out."
        foreach (['Description', 'Updated', 'Author', 'Published', 'Language', 'Retrieval'] as $label) {
            if ($fits($header)) {
                break;
            }

            $dropped[$label] = true;
            $header = $build($urlLimit, $titleLimit);
        }

        // "A long URL must not crowd out the page content either."
        while (!$fits($header) && $urlLimit > 64) {
            $urlLimit = max(64, intdiv($urlLimit, 2));
            $header = $build($urlLimit, $titleLimit);
        }

        while (!$fits($header) && $titleLimit > 40) {
            $titleLimit = max(40, intdiv($titleLimit, 2));
            $header = $build($urlLimit, $titleLimit);
        }

        return implode("\n", $header) . "\n\n---\n\n";
    }

    /**
     * "Render a result as the block a model or the user reads.
     *
     * The requested URL is only printed when it differs from the source, so a redirect is visible
     * without repeating the same URL twice on ordinary pages, and an incomplete crawler copy is
     * called out rather than passed off as the whole page."
     *
     * @param array<string, mixed> $result
     */
    public static function formatResult(array $result, string $footer = ''): string
    {
        $prefix = self::formatHeader($result);
        $maxChars = $result['maxChars'] ?? self::DEFAULT_MAX_CHARS;
        $available = $maxChars - Text::length($prefix) - Text::length($footer);

        if ($available < 1) {
            throw new RuntimeException('The character budget is too small for page provenance. Open the page in a browser or ask the user to increase ANTIGRAVITY_WEB_FETCH_MAX_CHARS.');
        }

        $content = $result['content'];

        if (Text::length($content) <= $available) {
            return $prefix . $content . $footer;
        }

        // "A saved-page footer already says where the rest is."
        $note = $footer !== '' ? "\n\n…" : "\n\n… [output truncated at {$maxChars} characters]";
        $end = max(0, $available - Text::length($note));

        while (true) {
            $head = mb_substr($content, 0, $end, 'UTF-8');

            // A cut inside a fence's delimiter line moves back to the start of that line, so the
            // delimiter is either whole or not there.
            $lineStart = (int) strrpos("\n" . $head, "\n");
            $tail = mb_substr($content, $end, null, 'UTF-8');
            $rest = strstr($tail, "\n", true);
            $line = substr($head, $lineStart) . ($rest === false ? $tail : $rest);

            if ($lineStart > 0 && $lineStart < strlen($head) && ($rest === false ? $tail : $rest) !== '' && Fences::isLine($line)) {
                $head = substr($head, 0, $lineStart);
                $end = Text::length($head);
            }

            $open = Fences::unclosed($head);
            $body = ($open === null ? $head : "{$head}\n{$open['prefix']}" . str_repeat($open['marker'], $open['length'])) . $note;
            $length = Text::length($body);

            if ($length <= $available) {
                return $prefix . $body . $footer;
            }

            if ($end === 0) {
                return $prefix . Text::cap($note, $available) . $footer;
            }

            $end = max(0, $end - ($length - $available));
        }
    }

    /**
     * "Parse `/antigravity.fetch [--raw] <url>`."
     *
     * @return array{url: string, raw: bool}
     */
    public static function parseFetchCommandArgs(string $args): array
    {
        $rest = [];
        $raw = false;

        foreach (preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($token === '--raw') {
                $raw = true;
            } else {
                $rest[] = $token;
            }
        }

        return ['url' => implode(' ', $rest), 'raw' => $raw];
    }
}
