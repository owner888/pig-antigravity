<?php

declare(strict_types=1);

namespace PigAntigravity;


use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Extension\StreamApi;
use Pig\Ai\Providers\AssistantMessageBuilder;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Providers\GoogleShared;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StreamOptions;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\SseParser;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\Usage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\Retry;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\Utf8;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Closure;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * Google Antigravity: Gemini, Claude and GPT-OSS on a subscription, through Code Assist.
 *
 * **Adapted from pig's own Gemini CLI provider rather than from the implementation this was
 * ported from** — that provider has since been removed, the way upstream removed its own.
 * Both speak `v1internal:streamGenerateContent?alt=sse` and both wrap a Gemini request in an
 * envelope, so the streaming, the SSE unwrap, the error shape and `GoogleShared` are all already
 * here and already tested. What the reference implementation had that this needed was the
 * envelope's *contents* — the labels, the request id shape, the model enum — and those are copied
 * field for field. Porting its hand-rolled SSE loop as well would have meant two of them.
 *
 * **The model on the wire is not the model on screen.** `Routing` turns the id
 * somebody chose plus the thinking level into a runtime id and the enum that names it; see that
 * class for why pig has no other provider shaped this way.
 *
 * **Three constants here are not what pig used to send**, and each was wrong in a way that fails
 * as a 4xx rather than as anything legible:
 *
 * - the host has no `sandbox` in it;
 * - the User-Agent is the Antigravity CLI's, and this deployment checks it;
 * - the envelope's `userAgent` field says `antigravity`, not `pig`. Upstream's changelog has that
 *   one as a fix for 429s specifically.
 */
final class AntigravityApi implements StreamApi
{
    /** Where it answers. `daily-` is the live host despite the name; no `sandbox` in it. */
    public const string ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';

    /** Where to try when the first one will not have us. */
    public const string FALLBACK_ENDPOINT = 'https://cloudcode-pa.googleapis.com';

    /**
     * The statuses after which the next endpoint is tried — pi-antigravity's `endpointCandidates()`
     * loop: `if (![403, 404, 429, 500, 502, 503, 504].includes(response.status)) break` — except a
     * 429 that is a quota wall, which every endpoint would answer the same way.
     */
    private const array FALL_BACK_ON = [403, 404, 429, 500, 502, 503, 504];

    /**
     * pi-antigravity's `turnRequestNonces`: how many requests have gone out for each turn, by
     * `<session>:<turns answered so far>`. 0 is the first request ever sent for that turn; anything
     * above is one sent again — the session's retry, the empty-response loop, the other host, the
     * next account. Process-wide, as upstream's module-level map is, and capped the same way.
     *
     * @var array<string, int>
     */
    private static array $turnRequestNonces = [];

    /**
     * @param string|null $fallback where to try when the primary refuses us, or null to work it
     *        out — which means Google's second host when the model points at Google's first, and
     *        no fallback at all when a `models.json` points this provider somewhere of its own.
     *        **Injectable for the same reason `$http` is**: a test cannot point a model at
     *        Google's host and also have the request arrive at a canned server, so under a
     *        hardcoded pair the one branch that matters is the one no test can see.
     */
    /**
     * @param (Closure(list<string> $triedAccessTokens): ?string)|null $failover pi-antigravity's
     *        `failoverToNextAccount()`: the next stored account whose access token has not been tried
     *        yet, made the active one, as an api key (`{"token", "projectId"}`) — or null when there
     *        is none. Asked when a request hits a quota wall.
     */
    public function __construct(
        private readonly HttpTransport $http = new DailyLimitedTransport(new HttpClient()),
        private readonly ?string $fallback = null,
        private readonly ?Closure $failover = null,
    ) {
    }

    /**
     * Simple options into Gemini's dialect — what `Stream::translate()` used to do for this
     * provider as its `antigravity()` arm, and the `StreamApi` contract puts here. Thinking is
     * said as a level and only when it was asked for; the routing table turns the level into a
     * runtime model, which is this provider's own business and nobody else's.
     */
    #[\Override]
    public function translate(Model $model, ?SimpleStreamOptions $options, string $apiKey): StreamOptions
    {
        return new GoogleOptions(
            $options?->temperature,
            $options?->maxTokens ?? $model->maxTokens,
            $options?->signal,
            $apiKey,
            thinkingEnabled: $options?->reasoning !== null,
            thinkingLevel: $options?->reasoning?->value,
            // `opts.sessionId`: what the envelope's session id is made from, and what tells one
            // session's turns from another's when a request is sent again (`nextRequestNonce()`).
            sessionId: $options?->sessionId,
        );
    }

    /** Returns at once; the response fills in as it arrives. */
    #[\Override]
    public function stream(Model $model, TranscriptContext $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        // Options that did not come through `translate()` — a caller of `Stream::start()` with
        // base options — carry the key and nothing Gemini-shaped; `GoogleOptions` is what the
        // rest of this class reads, so they are widened here.
        if ($options !== null && !$options instanceof GoogleOptions) {
            $options = new GoogleOptions($options->temperature, $options->maxTokens, $options->signal, $options->apiKey, thinkingEnabled: false);
        }

        $stream = new AssistantMessageEventStream();

        Async::spawn(function () use ($stream, $model, $context, $options): void {
            $this->run($stream, $model, $context, $options);
        });

        return $stream;
    }

    private function run(
        AssistantMessageEventStream $stream,
        Model $model,
        TranscriptContext $context,
        ?GoogleOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        $signal = $options?->signal;

        try {
            $received = false;

            // pi-antigravity's `emptyAttempt` loop: a response that carried no text, thinking or
            // tool call is asked for again, after 500 ms and then 1 s, before the turn fails with
            // "Antigravity API returned an empty response". Nothing goes out on the stream until
            // something has arrived (`ensureStarted()`), so an empty attempt leaves no trace.
            $emptyAttempt = 0;

            while (true) {
                if ($signal?->aborted() ?? false) {
                    throw new ProviderError('Request was aborted');
                }

                // The remembered capacity route is tried "if capacityAttempt === 0 && emptyAttempt === 0".
                $sent = $this->send($model, $context, $options, $signal, $emptyAttempt === 0);

                // "output.content = []; output.usage = {…}; output.stopReason = "stop"": each attempt
                // starts from nothing.
                $builder = new AssistantMessageBuilder($model);

                try {
                    $received = $this->read($sent['response'], $builder, $stream);
                } catch (Throwable $error) {
                    // A remembered route whose stream failed before anything arrived is forgotten
                    // and the turn walks the whole chain again.
                    if ($sent['probed']) {
                        unset(self::$capacityRoutes[$sent['routeKey']]);

                        if (!($signal?->aborted() ?? false) && $builder->snapshot()->content === []) {
                            continue;
                        }
                    }

                    throw $error;
                }

                if ($received) {
                    if ($builder->stopReason() !== StopReason::Error && $builder->stopReason() !== StopReason::Aborted) {
                        // "Remember the live fallback route only after the SSE stream actually
                        // succeeded (not merely on HTTP 200 headers)."
                        if ($sent['cacheable'] && ($sent['runtime'] !== $sent['initial'] || $sent['endpoint'] !== $sent['firstEndpoint'])) {
                            self::rememberCapacityRoute($sent['routeKey'], $sent['runtime'], $sent['endpoint']);
                        }
                    } elseif ($sent['probed']) {
                        unset(self::$capacityRoutes[$sent['routeKey']]);
                    }

                    break;
                }

                if ($sent['probed']) {
                    unset(self::$capacityRoutes[$sent['routeKey']]);
                }

                if ($emptyAttempt < 2) {
                    $emptyAttempt++;
                    self::sleep(0.5 * 2 ** ($emptyAttempt - 1), $signal);

                    continue;
                }

                break;
            }

            if (!$received) {
                throw new ProviderError('Antigravity API returned an empty response');
            }

            $signal?->throwIfAborted();

            // The direct Gemini path's check after the stream (`Google::run()`): an error reason
            // ends the turn with the reason itself, once the whole body has been read.
            if ($builder->stopReason() === StopReason::Error || $builder->stopReason() === StopReason::Aborted) {
                $raw = $builder->rawStopReason();

                throw new ProviderError($raw !== null && $raw !== '' ? "Provider stopped with: {$raw}" : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result, with any
            // secret in it redacted (pi-antigravity's `safeError()`).
            $builder->fail(self::redactSecrets($error->getMessage()), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * Read one response into the builder — pi-antigravity's `streamResponse()` — and say whether
     * anything arrived: a part with text (thinking or not) or a function call. The stream's
     * `start` goes out with the first of those, and not before.
     */
    private function read(Response $response, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): bool
    {
        $parser = new SseParser();
        $open = null;
        $hasContent = false;
        $sawFinishReason = false;

        foreach ($response->body as $chunk) {
            foreach ($parser->feed($chunk) as $event) {
                $raw = trim($event->data);

                // Detect mid-stream stray error objects
                if (!str_starts_with($raw, '{') && str_contains($raw, '{')) {
                    $raw = substr($raw, (int) strpos($raw, '{'));
                }

                $data = json_decode($raw, true);

                if (!is_array($data)) {
                    continue;
                }

                // "if (chunk.error) throw new Error(chunk.error.message || JSON.stringify(chunk.error))".
                if (isset($data['error']) && $data['error'] !== null && $data['error'] !== false) {
                    $status = is_int($data['error']['code'] ?? null) ? $data['error']['code'] : null;
                    $message = is_array($data['error']) ? ($data['error']['message'] ?? null) : null;
                    $friendly = self::friendlyAntigravityError($status, json_encode(['error' => $data['error']]) ?: '');

                    throw new ProviderError($status !== null ? "{$friendly} ({$status})" : (is_string($message) && $message !== '' ? $message : (string) json_encode($data['error'])));
                }

                // `chunk.response || chunk`.
                $responseData = is_array($data['response'] ?? null) ? $data['response'] : $data;

                // "Record the model the gateway actually served (may differ from the requested
                // runtime model)." Each chunk that names one, the last winning, as upstream's
                // plain assignments do.
                if (is_string($responseData['modelVersion'] ?? null) && $responseData['modelVersion'] !== '') {
                    $builder->setResponseModel($responseData['modelVersion']);
                }

                if (is_string($responseData['responseId'] ?? null) && $responseData['responseId'] !== '') {
                    $builder->setResponseId($responseData['responseId']);
                }

                if (isset($responseData['candidates'][0]['finishReason'])) {
                    $sawFinishReason = true;
                }

                foreach ($responseData['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (is_array($part) && (array_key_exists('text', $part) || isset($part['functionCall']))) {
                        if (!$hasContent) {
                            $stream->push(new StartEvent($builder->snapshot()));
                        }

                        $hasContent = true;

                        break;
                    }
                }

                // As the direct Gemini path reads it: an error finish reason is recorded and the
                // rest of the stream — usage included — is read before the turn fails on it.
                $open = GoogleShared::onChunk($responseData, $builder, $stream, $open);
            }
        }

        GoogleShared::close($builder, $stream, $open);

        if (!$sawFinishReason && $hasContent) {
            throw new ProviderError('Antigravity stream ended without a finish reason; the response was terminated before completion.');
        }

        return $hasContent;
    }

    /** pi-antigravity's backoff between empty attempts, cut short by an abort. */
    private static function sleep(float $seconds, ?AbortSignal $signal): void
    {
        $done = new \Pig\Async\Deferred();
        $timer = \Pig\Async\Loop::get()->delay($seconds, static fn () => $done->isComplete() ? null : $done->complete(null));
        $listener = $signal?->onAbort(static function () use ($done, $timer): void {
            \Pig\Async\Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(null);
            }
        });

        try {
            $done->future->await();
        } finally {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

    /**
     * The request, through the runtime models, the endpoints and the stored accounts —
     * pi-antigravity's request loop.
     *
     * The runtime model `Routing` resolves, then `Routing::fallback()`'s (pi-antigravity's
     * `getFallbackRuntimeModel()`) when that one is answered 404. For each, every endpoint in turn
     * while the answer is one of `FALL_BACK_ON`, stopping at a success, at any other status, or at
     * a quota wall (`isQuotaWall()`). A generic resource exhaustion — a 429 that names no limit —
     * walks the endpoints too, then the same-generation tiers (`Routing::siblings()`), four
     * runtime models at most, then the next candidate: "capacity pressure when another endpoint
     * or same-generation tier might still be live." An endpoint that fails before it answers
     * (cannot connect, no response head in time) is passed over for the next one too, and only
     * when none answered is that failure the turn's.
     *
     * Then, as pi-antigravity 0.15: a quota wall asks `$failover` for an account not yet tried and
     * starts over with it; with none left, the turn fails with the wall's own sentence ("Quota
     * reached. …"). A capacity refusal (`isCapacityFailure()`: that generic 429, or a 503 "No
     * capacity available") "says nothing about the model yet: the same request succeeds once the
     * shared pool drains" — so it is sent again after `capacityBackoffMs()` (3, 8, 15, 25 s,
     * jittered) while `capacityAttemptLimit()` allows. Any other refusal is `Antigravity API error
     * (<status>, <diagnostics>): <friendlyAntigravityError()>`.
     *
     * The route a capacity walk ended on is remembered (`rememberCapacityRoute()`, by the caller,
     * once the stream succeeded); with $probe, the first pass sends to it once on its own, and
     * forgets it the moment it fails.
     *
     * Every request after the first for a turn goes out under a fresh session and trajectory
     * (`retrySessionSeed()`): "a crashed stream can keep generating server-side while the backend
     * serializes later same-session requests behind it — the literal retry then hangs almost
     * deterministically even though a fresh request succeeds."
     *
     * Not ported: pi-antigravity's dynamic model discovery (`fetchAvailableRuntimeModel()`), for a
     * model its tables do not know or a 404 on every candidate — pig's catalogue is the tables.
     *
     * @return array{response: Response, routeKey: string, runtime: string, endpoint: string, initial: string, firstEndpoint: string, probed: bool, cacheable: bool}
     */
    private function send(Model $model, TranscriptContext $context, ?GoogleOptions $options, ?AbortSignal $signal, bool $probe = true): array
    {
        $apiKey = $options?->apiKey;
        [$token, $project] = self::credentials($apiKey);
        $tried = [$token];

        // A `models.json` pointing this provider somewhere of its own — a proxy, a local gateway
        // — is believed rather than silently second-guessed with Google's other host.
        $primary = $model->baseUrl !== '' ? $model->baseUrl : self::ENDPOINT;
        $second = $this->fallback ?? ($primary === self::ENDPOINT ? self::FALLBACK_ENDPOINT : null);
        $hosts = $second === null || $second === $primary ? [$primary] : [$primary, $second];

        // pi-antigravity 0.13: a Gemini Enterprise license goes to its location's own host and to
        // no other — never on to the consumer endpoints, which refuse it and are outside its region.
        $enterprise = Enterprise::resolve();

        if ($enterprise !== null) {
            $primary = $enterprise->host;
            $hosts = [$enterprise->host];
        }

        // "allowCapacityCache = !enterpriseTarget && !RUNTIME_MODEL && !BASE_URL".
        $cacheable = $enterprise === null && Discovery::env('BASE_URL') === null;

        // Both, so that the two cannot contradict each other: `thinkingEnabled: false` with a level
        // set means off, which is the reading every other provider here gives it.
        $level = ($options?->thinkingEnabled ?? false) ? $options?->thinkingLevel : null;
        $initial = Routing::resolve($model->id, $level)[0];

        // "Turn identity mirrors buildRequest's requestIndex (count of successful assistant
        // messages): it stays constant across retries of one turn and changes the moment any turn
        // succeeds." Without a session id the conversation's own seed stands in for upstream's
        // `anonymous`, which in a process serving several sessions at once would be one counter
        // for all of them.
        $sessionId = $options?->sessionId;
        $turnKey = ($sessionId !== null && trim($sessionId) !== '' ? $sessionId : 'anonymous:' . self::seed($context)) . ':' . self::turnsAnswered($context);

        $attempt = function (string $runtime, string $host) use ($model, $context, $options, $enterprise, &$project, &$token, $sessionId, $turnKey, $signal): Response {
            $nonce = self::nextRequestNonce($turnKey);
            $payload = $this->body($model, $context, $options, $enterprise?->projectId ?? $project, $runtime, $nonce === 0 ? null : self::retrySessionSeed($sessionId, $nonce));

            if ($enterprise !== null) {
                $payload = $enterprise->generateBody($payload);
            }

            // pi-antigravity 0.13 (#126): `onPayload` sees the request as it is about to go, and
            // what it answers goes instead.
            $rewritten = $options?->onPayload !== null ? ($options->onPayload)($payload, $model) : null;
            $body = $this->encode(is_array($rewritten) ? $rewritten : $payload);
            $url = $enterprise?->streamUrl() ?? rtrim($host, '/') . '/v1internal:streamGenerateContent?alt=sse';

            return $this->http->send($this->request($url, $token, $model, $body, $enterprise !== null), $signal);
        };

        $capacityAttempts = self::capacityAttemptLimit();
        $capacityAttempt = 0;
        $result = fn (Response $response, string $routeKey, string $runtime, string $host, bool $probed): array => [
            'response' => $response, 'routeKey' => $routeKey, 'runtime' => $runtime, 'endpoint' => $host,
            'initial' => $initial, 'firstEndpoint' => $hosts[0], 'probed' => $probed, 'cacheable' => $cacheable,
        ];

        while (true) {
            if ($signal?->aborted() ?? false) {
                throw new ProviderError('Request was aborted');
            }

            // `resolveCapacityCandidates()`, "at the start of every outer pass so sibling tiers
            // discovered in pass 1 are retried cleanly on pass 2+".
            $routeKey = self::accountTag($token) . "\0{$project}\0{$initial}";
            $cached = $cacheable ? self::rememberedCapacityRoute($routeKey) : null;
            $candidates = [$initial];
            $fallback = Routing::fallback($initial, $level);

            if ($fallback !== null && $fallback !== $initial) {
                $candidates[] = $fallback;
            }

            $last = null;
            $lastText = '';
            $lastHost = $primary;
            $runtime = $initial;

            // "Single-probe cached capacity route outside normal candidate de-duplication: if it
            // fails (transport, non-OK, or body read failure), evict it immediately and fall
            // through to the full normal chain."
            if ($cached !== null && $probe && $capacityAttempt === 0) {
                $runtime = $cached['runtime'];
                $lastHost = $cached['endpoint'];

                try {
                    $probeResponse = $attempt($runtime, $cached['endpoint']);

                    if ($probeResponse->isSuccessful()) {
                        return $result($probeResponse, $routeKey, $runtime, $cached['endpoint'], true);
                    }

                    unset(self::$capacityRoutes[$routeKey]);
                    $lastText = $probeResponse->body->all();
                } catch (Throwable $error) {
                    // A day over its limit of 429s is the turn's answer, never a host to pass over.
                    if ($error instanceof DailyLimitReached) {
                        throw $error;
                    }

                    if ($signal?->aborted() ?? false) {
                        throw $error;
                    }

                    unset(self::$capacityRoutes[$routeKey]);
                }
            }

            // Not `foreach`: a sibling tier is put into the list while it is being walked.
            for ($index = 0; $index < count($candidates); $index++) {
                $runtime = $candidates[$index];
                $last = null;
                $lastEndpointError = null;

                foreach ($hosts as $host) {
                    $lastHost = $host;

                    try {
                        $last = $attempt($runtime, $host);
                    } catch (Throwable $error) {
                        // A day over its limit of 429s is the turn's answer, never a host to pass over.
                        if ($error instanceof DailyLimitReached) {
                            throw $error;
                        }

                        // "Caller cancellation is not an endpoint failure — propagate it. A transport
                        // failure or our own header deadline abort means this endpoint may be wedged;
                        // ENDPOINT_FALLBACKS exist for that, so fall through instead of dying
                        // without trying them."
                        if ($signal?->aborted() ?? false) {
                            throw $error;
                        }

                        $lastEndpointError = $error;

                        continue;
                    }

                    if ($last->isSuccessful()) {
                        return $result($last, $routeKey, $runtime, $host, false);
                    }

                    $lastText = $last->body->all();

                    if ($last->status === 429 && self::isQuotaWall($lastText)) {
                        break;
                    }

                    // "Generic RESOURCE_EXHAUSTED is capacity pressure when another endpoint or
                    // same-generation tier might still be live."
                    if ($last->status === 429 && self::isGenericResourceExhausted($lastText)) {
                        continue;
                    }

                    if ($last->status === 429 && !self::isRetryableRateLimit($lastText)) {
                        break;
                    }

                    if (!in_array($last->status, self::FALL_BACK_ON, true)) {
                        break;
                    }
                }

                // "Every endpoint threw before sending headers: rethrow the original transport error
                // so its message (which pi classifies for auto-retry) survives instead of being
                // masked by the generic failure path below."
                if ($last === null && $lastEndpointError !== null) {
                    throw $lastEndpointError;
                }

                // "if (response?.status === 404) { if (candIdx + 1 < runtimeCandidates.length) continue; }"
                if ($last?->status === 404 && $index + 1 < count($candidates)) {
                    continue;
                }

                if ($last?->status === 429 && self::isGenericResourceExhausted($lastText)) {
                    $sibling = array_values(array_diff(Routing::siblings($runtime), $candidates))[0] ?? null;

                    if ($sibling !== null && count($candidates) < 4) {
                        // "Same-generation tiers before a cross-generation compatibility fallback."
                        array_splice($candidates, $index + 1, 0, [$sibling]);

                        continue;
                    }

                    if ($index + 1 < count($candidates)) {
                        continue;
                    }
                }

                break;
            }

            // Unreachable with a non-empty host list, and `$hosts` always has one.
            if ($last === null) {
                throw new ProviderError('No endpoint to try.');
            }

            $friendly = self::friendlyAntigravityError($last->status, $lastText);

            if ($last->status === 429 && preg_match('/Quota reached\./i', $friendly) === 1) {
                $next = $this->failover !== null ? ($this->failover)($tried) : null;

                if ($next !== null) {
                    [$token, $project] = self::credentials($next);
                    $tried[] = $token;
                    $capacityAttempt = 0;

                    continue;
                }

                throw new ProviderError($friendly);
            }

            // "A capacity refusal says nothing about the model yet: the same request succeeds once
            // the shared pool drains. Walk the backoff ladder while attempts remain instead of
            // surfacing a terminal "unavailable"."
            if (self::isCapacityFailure($last->status, $lastText) && $capacityAttempt < $capacityAttempts - 1) {
                $capacityAttempt++;
                self::sleep(self::capacityBackoffMs($capacityAttempt) / 1000, $signal);

                continue;
            }

            throw new ProviderError(
                'Antigravity API error (' . self::publicErrorStatus($last->status, $friendly) . ', ' . self::formatRequestDiagnostics($lastHost, $project, $runtime) . "): {$friendly}",
            );
        }
    }

    /**
     * pi-antigravity's `publicErrorStatus()`, for the 429 it was written for: "Keep the numeric
     * status out of non-retryable errors. Pi treats the digits 429 as a retryable provider error
     * even when the friendly text says the model is unavailable." (`Retry`'s pattern is upstream's,
     * so pig does too.)
     *
     * **Narrower than upstream on purpose.** Upstream hides the status of every refusal whose
     * sentence does not say "retrying automatically", which takes the digits off a 502 and a 503
     * as well — and their sentences ("bad gateway", "temporarily unavailable", "no capacity")
     * match nothing in the retry pattern, so those would stop being retried. Nothing upstream says
     * that was meant; here the other statuses keep their number.
     */
    private static function publicErrorStatus(int $status, string $friendly): string
    {
        return $status === 429 && preg_match('/retrying automatically/i', $friendly) !== 1 ? 'request-failed' : (string) $status;
    }

    /** `nextRequestNonce()`: how many requests went out for this turn before this one. */
    private static function nextRequestNonce(string $turnKey): int
    {
        $nonce = self::$turnRequestNonces[$turnKey] ?? 0;
        // Set, not moved to the end: a JavaScript `Map` keeps a key where it was first put.
        self::$turnRequestNonces[$turnKey] = $nonce + 1;

        if (count(self::$turnRequestNonces) > 64) {
            unset(self::$turnRequestNonces[array_key_first(self::$turnRequestNonces)]);
        }

        return $nonce;
    }

    /** Forget every turn's count. For tests, which send the same first message again and again. */
    public static function forgetRequestNonces(): void
    {
        self::$turnRequestNonces = [];
    }

    /**
     * pi-antigravity's `CAPACITY_BACKOFF_DEFAULT_MS`: "Backoff ladder for transient capacity
     * refusals. Consumer endpoints answer generic `429 RESOURCE_EXHAUSTED` (no reset hint) and
     * `503 No capacity available` while the model pool still reports quota remaining, and then
     * serve the same request once the queue drains."
     */
    private const array CAPACITY_BACKOFF_DEFAULT_MS = [3_000, 8_000, 15_000, 25_000];

    /** `CAPACITY_ROUTE_TTL_MS`: a remembered route is believed for ten minutes. */
    private const int CAPACITY_ROUTE_TTL_MS = 10 * 60_000;

    /**
     * `preferredCapacityRoutes`: "cross-turn memory of the (runtimeModel, endpoint) that actually
     * answered after a generic-429 capacity walk, keyed by account tag, projectId, and the initial
     * runtime model. Without it every turn of a session replays dead-tier HTTP hops and burns a
     * fresh retry nonce, mutating the upstream sessionId and wiping the prompt cache."
     *
     * @var array<string, array{runtime: string, endpoint: string, expiresAt: int}>
     */
    private static array $capacityRoutes = [];

    /** @var array<string, int> access token => `accountTag()`'s number */
    private static array $accountTags = [];

    private static int $nextAccountTag = 1;

    /** `capacityBackoffSchedule()`: `CAPACITY_BACKOFF_MS`, comma-separated, else the default. @return list<int> */
    private static function capacityBackoffSchedule(): array
    {
        $raw = trim((string) Discovery::env('CAPACITY_BACKOFF_MS'));

        if ($raw === '') {
            return self::CAPACITY_BACKOFF_DEFAULT_MS;
        }

        $parsed = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if (is_numeric($part) && (float) $part >= 0 && (float) $part <= 120_000) {
                $parsed[] = (int) $part;
            }
        }

        return $parsed !== [] ? $parsed : self::CAPACITY_BACKOFF_DEFAULT_MS;
    }

    /** `capacityAttemptLimit()`: "the first request plus one per backoff step", or `CAPACITY_ATTEMPTS` (1–10). */
    public static function capacityAttemptLimit(): int
    {
        $raw = trim((string) Discovery::env('CAPACITY_ATTEMPTS'));

        if (is_numeric($raw) && (float) $raw >= 1 && (float) $raw <= 10) {
            return (int) floor((float) $raw);
        }

        return count(self::capacityBackoffSchedule()) + 1;
    }

    /** `capacityBackoffMs()`: "Jitter keeps sessions sharing one quota pool from retrying in lockstep." */
    private static function capacityBackoffMs(int $attempt): int
    {
        $schedule = self::capacityBackoffSchedule();
        $base = $schedule[min($attempt - 1, count($schedule) - 1)] ?? 0;

        return (int) round($base * (0.9 + mt_rand() / mt_getrandmax() * 0.2));
    }

    /** `isCapacityFailure()`: "Capacity refusals that describe load, not a retired model or a quota wall." */
    public static function isCapacityFailure(?int $status, string $text): bool
    {
        return match ($status) {
            429 => self::isGenericResourceExhausted($text),
            503 => preg_match('/no capacity available/i', $text) === 1,
            default => false,
        };
    }

    /** `rememberCapacityRoute()`, at most 64, the oldest going first. */
    public static function rememberCapacityRoute(string $routeKey, string $runtime, string $endpoint, ?int $now = null): void
    {
        unset(self::$capacityRoutes[$routeKey]);
        self::$capacityRoutes[$routeKey] = ['runtime' => $runtime, 'endpoint' => $endpoint, 'expiresAt' => ($now ?? self::nowMs()) + self::CAPACITY_ROUTE_TTL_MS];

        while (count(self::$capacityRoutes) > 64) {
            unset(self::$capacityRoutes[array_key_first(self::$capacityRoutes)]);
        }
    }

    /** `rememberedCapacityRoute()`: the route for $routeKey while it has not expired. @return array{runtime: string, endpoint: string}|null */
    public static function rememberedCapacityRoute(string $routeKey, ?int $now = null): ?array
    {
        $entry = self::$capacityRoutes[$routeKey] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAt'] <= ($now ?? self::nowMs())) {
            unset(self::$capacityRoutes[$routeKey]);

            return null;
        }

        return ['runtime' => $entry['runtime'], 'endpoint' => $entry['endpoint']];
    }

    /** `clearCapacityRouteCache()`. For tests. */
    public static function forgetCapacityRoutes(): void
    {
        self::$capacityRoutes = [];
        self::$accountTags = [];
        self::$nextAccountTag = 1;
    }

    /**
     * `accountTag()`: "an opaque in-process identifier per distinct access token instead of
     * embedding or hashing it", at most 64.
     */
    private static function accountTag(string $token): string
    {
        if (isset(self::$accountTags[$token])) {
            return 'acct-' . self::$accountTags[$token];
        }

        $id = self::$nextAccountTag++;
        self::$accountTags[$token] = $id;

        while (count(self::$accountTags) > 64) {
            unset(self::$accountTags[array_key_first(self::$accountTags)]);
        }

        return "acct-{$id}";
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * `retrySessionSeed()`: what a request sent again is identified by — the session's id with the
     * count on it, or something random when there is no session id to hang it on.
     */
    private static function retrySessionSeed(?string $sessionId, int $nonce): string
    {
        return $sessionId !== null && trim($sessionId) !== '' ? "{$sessionId}:r{$nonce}" : 'retry:' . bin2hex(random_bytes(16));
    }

    /** Assistant turns that were answered — not the ones that failed or were aborted, which a retry leaves behind. */
    private static function turnsAnswered(TranscriptContext $context): int
    {
        return count(array_filter(
            $context->messages,
            static fn (mixed $message): bool => $message instanceof AssistantMessage
                && $message->stopReason !== StopReason::Error
                && $message->stopReason !== StopReason::Aborted,
        ));
    }

    /** "Short throttles. Pi retries these; persistent model unavailability must not match." */
    private static function isRetryableRateLimit(string $text): bool
    {
        return preg_match('/rate.?limit|too many requests/i', $text) === 1;
    }

    /**
     * pi-antigravity's `isGenericResourceExhausted()`: "Generic capacity 429 with no reset hint.
     * Dead tiers and live-but-throttled tiers share this body. Callers decide whether to walk
     * endpoints and sibling tiers; the public error stays non-retryable for Pi once those attempts
     * are exhausted."
     */
    private static function isGenericResourceExhausted(string $text): bool
    {
        if (self::isQuotaWall($text) || self::isRetryableRateLimit($text)) {
            return false;
        }

        return preg_match('/resource has been exhausted|RESOURCE_EXHAUSTED/i', $text) === 1;
    }

    /** Whether pig's session would send a turn that failed with $message again by itself. */
    private static function sessionWouldRetry(string $message): bool
    {
        return Retry::isRetryableAssistantError(new AssistantMessage([], Api::Extension, Models::PROVIDER, '', new Usage(), StopReason::Error, $message));
    }

    /**
     * pi-antigravity's `formatRequestDiagnostics()`. `matched` and `available` come from its
     * dynamic per-request model lookup, which pig has not got, so they are what upstream's
     * `recordStaticRouteDiagnostics()` records for a request that skipped it: the runtime model
     * the tables gave, and the models the catalogue in use has.
     */
    private static function formatRequestDiagnostics(string $endpoint, string $project, string $runtime): string
    {
        $available = implode(',', Routing::models());

        return "endpoint={$endpoint}, project={$project}, runtimeModel={$runtime}, matched=static:{$runtime}, available=" . ($available !== '' ? $available : 'static-catalog');
    }

    /**
     * pi-antigravity's quota-wall test on a 429's text: "Individual quota reached", a "Resets in …"
     * hint, an AI credits balance that has run out, or quota wording that is not about rate limiting. Public for `ImageGenerator`, whose
     * request loop fails over on the same wall.
     */
    public static function isQuotaWall(string $text): bool
    {
        return preg_match('/Individual quota reached/i', $text) === 1
            || preg_match('/Resets? in /i', $text) === 1
            || preg_match('/AI credits balance is too low|INSUFFICIENT_G1_CREDITS/i', $text) === 1
            || (preg_match('/rate.?limit/i', $text) !== 1 && preg_match('/quota exceeded|exceeded your|daily limit/i', $text) === 1);
    }

    private function request(string $url, string $token, Model $model, string $body, bool $enterprise = false): Request
    {
        $userAgent = AntigravityCli::userAgent();

        return new Request(
            'POST',
            $url,
            [
                'accept' => 'text/event-stream',
                'content-type' => 'application/json',
                'authorization' => "Bearer {$token}",
                // The CLI's own, which this deployment checks: a version and a platform that have
                // nothing to do with the machine running pig. pig's core holds it (`AntigravityCli`).
                // An enterprise license signs in as a Cloud identity, and says so.
                'user-agent' => $enterprise ? str_replace('auth_method=consumer', 'auth_method=gcp', $userAgent) : $userAgent,
                ...$model->headers,
            ],
            $body,
        );
    }

    /**
     * The largest `systemInstruction` some consumer endpoints take before answering a generic 429
     * RESOURCE_EXHAUSTED — pi-antigravity 0.15's `SYSTEM_INSTRUCTION_PART_LIMIT`: "empirically 126
     * passes, 127+ fails regardless of wording or splitting across multiple parts."
     */
    private const int SYSTEM_INSTRUCTION_PART_LIMIT = 126;

    /**
     * "Backend-neutral replacement for Pi's default preamble opener. Replacing only the 'running
     * inside pi' phrase leaves a sentence pair that still matches the backend's prompt classifier on
     * consumer endpoints; replacing the whole opener sentence group passes when wire-native
     * `systemInstruction` is retained."
     */
    private const string NEUTRAL_OPENER = 'You are a coding agent helping the user with their project.';

    /**
     * pi-antigravity 0.15's `sanitizeSystemPrompt()`: "Neutralize Pi and Pi-Bolt system prompt
     * fingerprints that trigger false 429 RESOURCE_EXHAUSTED responses from the Antigravity consumer
     * proxy when wire-native `systemInstruction` is sent." The whole-opener rewrite runs first.
     *
     * pig's own opener (`SystemPrompt::ROLE`) is pi's word for word with pig's name in it, so the
     * opener rewrite neutralizes it exactly as it does pi's. The other phrases are pi's and Pi-Bolt's,
     * which a custom prompt, an `--append-system-prompt` or a context file can quote.
     */
    public static function sanitizeSystemPrompt(string $text): string
    {
        // The opener: anchored to the start, and replaced once, as upstream's has neither `g` nor `m`.
        $text = (string) preg_replace(
            '/\AYou are a coding assistant running inside [^.]*\.\s*Help users inspect files, run commands, and modify code\./i',
            self::NEUTRAL_OPENER,
            $text,
            1,
        );

        // `\z`, not `$`: JavaScript's `$` does not match before a trailing newline, PCRE's does.
        return (string) preg_replace(
            [
                '/running inside pi-bolt,\s*a build of pi\s*\(a coding agent harness\)\s*compiled ahead of time/i',
                '/inside pi-bolt,\s*a build of pi\s*\(a coding agent harness\)\s*compiled ahead of time/i',
                '/running inside pi(?:-bolt)?,\s*a coding agent harness/i',
                '/inside pi(?:-bolt)?,\s*a coding agent harness/i',
                '/(coding assistant )running inside pi(?:-bolt)?(?=[.,!?;:\s]|\z)(?!\.\w)/i',
                '/(coding agent )inside pi(?:-bolt)?(?=[.,!?;:\s]|\z)(?!\.\w)/i',
                '/\bpractical,\s*and tool-aware\./i',
                '/codemode scripts and non-LLM models/i',
            ],
            [
                'running inside your development environment',
                'inside your development environment',
                'running inside your development environment',
                'inside your development environment',
                '$1running inside your development environment',
                '$1inside your development environment',
                'practical, and tool aware.',
                'codemode scripts (docs/codemode.md), non-LLM models',
            ],
            $text,
        );
    }

    /**
     * pi-antigravity 0.15's `inlineSystemPreference()`: `ANTIGRAVITY_INLINE_SYSTEM=1|true|on` folds the
     * prompt into the first user turn, `0|false|off` keeps `systemInstruction`, and anything else
     * leaves it to the cap. `ANTIGRAVITY_PROMPT_GUARD=off`, the earlier workaround's switch, keeps it
     * when `INLINE_SYSTEM` says nothing.
     *
     * @return 'on'|'off'|'auto'
     */
    public static function inlineSystemPreference(): string
    {
        $raw = strtolower(trim(Discovery::env('INLINE_SYSTEM') ?? ''));

        return match (true) {
            in_array($raw, ['1', 'true', 'on'], true) => 'on',
            in_array($raw, ['0', 'false', 'off'], true) => 'off',
            $raw === '' && strtolower(trim(Discovery::env('PROMPT_GUARD') ?? '')) === 'off' => 'off',
            default => 'auto',
        };
    }

    /**
     * pi-antigravity 0.15's `shouldInlineSystem()`: "Some consumer endpoints answer a
     * `systemInstruction` exceeding 126 characters (whether in a single part or split across multiple
     * parts) with a generic 429 RESOURCE_EXHAUSTED regardless of wording, while accepting the identical
     * text inside `contents` at any length." Counted in characters across every part.
     *
     * @param list<array{text: string}> $parts
     */
    public static function shouldInlineSystem(array $parts): bool
    {
        return match (self::inlineSystemPreference()) {
            'on' => true,
            'off' => false,
            default => array_sum(array_map(static fn (array $part): int => mb_strlen($part['text']), $parts)) > self::SYSTEM_INSTRUCTION_PART_LIMIT,
        };
    }

    /**
     * pi-antigravity 0.15's `inlineSystemInstruction()`: the system parts, joined, become the first
     * part of the first user turn, or a user turn of their own ahead of a transcript that does not
     * start with one. (Upstream also drops a placeholder turn its `buildRequest()` injected; pig
     * injects none.)
     *
     * @param list<array{role: string, parts: list<array<string, mixed>>}> $contents
     * @param list<array{text: string}> $parts
     * @return list<array{role: string, parts: list<array<string, mixed>>}>
     */
    public static function inlineSystemInstruction(array $contents, array $parts): array
    {
        $text = Utf8::sanitize(implode("\n\n", array_map(static fn (array $part): string => $part['text'], $parts)));

        if ($text === '') {
            return $contents;
        }

        if (($contents[0]['role'] ?? null) === 'user') {
            $contents[0]['parts'] = [['text' => $text], ...$contents[0]['parts']];

            return $contents;
        }

        return [['role' => 'user', 'parts' => [['text' => $text]]], ...$contents];
    }

    /**
     * pi-antigravity 0.15 (#156, #157): "Claude and GPT-OSS backends require every functionCall in a
     * model turn to have a matching functionResponse at the start of the immediately following user
     * turn before any inlineData/text parts. When parallel tool results include images or an earlier
     * turn was interrupted before its toolResult was recorded, keep all functionResponse parts
     * contiguous first and synthesize missing responses." Otherwise the backend answers 400
     * `tool_use ids were found without tool_result blocks`.
     *
     * The last turn is left alone: its calls are the ones being answered now.
     *
     * @param list<array{role: string, parts: list<array<string, mixed>>}> $contents
     * @return list<array{role: string, parts: list<array<string, mixed>>}>
     */
    public static function pairToolResults(array $contents, string $modelId, string $runtime): array
    {
        // Upstream's `toolCallIdNeeded()`.
        $needed = false;

        foreach ([$modelId, $runtime] as $id) {
            $needed = $needed || str_starts_with($id, 'claude-') || str_starts_with($id, 'gpt-oss-');
        }

        if (!$needed) {
            return $contents;
        }

        // `count()` each time round: a turn inserted below is walked past like any other.
        for ($index = 0; $index < count($contents) - 1; $index++) {
            if ($contents[$index]['role'] !== 'model') {
                continue;
            }

            $calls = array_values(array_filter($contents[$index]['parts'], static fn (array $part): bool => isset($part['functionCall'])));

            if ($calls === []) {
                continue;
            }

            if ($contents[$index + 1]['role'] !== 'user') {
                array_splice($contents, $index + 1, 0, [['role' => 'user', 'parts' => []]]);
            }

            $next = $contents[$index + 1]['parts'];
            $responses = array_values(array_filter($next, static fn (array $part): bool => isset($part['functionResponse'])));
            $rest = array_values(array_filter($next, static fn (array $part): bool => !isset($part['functionResponse'])));
            $answered = [];

            foreach ($responses as $part) {
                $id = (string) ($part['functionResponse']['id'] ?? '');

                if ($id !== '') {
                    $answered[$id] = true;
                }
            }

            foreach ($calls as $part) {
                $id = (string) ($part['functionCall']['id'] ?? '');

                if ($id !== '' && !isset($answered[$id])) {
                    $responses[] = ['functionResponse' => [
                        'name' => $part['functionCall']['name'],
                        'id' => $id,
                        'response' => ['error' => 'Tool execution was interrupted before a result was recorded.'],
                    ]];
                    $answered[$id] = true;
                }
            }

            $contents[$index + 1]['parts'] = [...$responses, ...$rest];
        }

        return $contents;
    }

    /**
     * The envelope, with a Gemini request inside it.
     *
     * Every field is the reference implementation's, including the ones whose purpose is not
     * obvious from here: `requestType: agent` and the `used_claude` labels are what the
     * deployment reads to decide which quota a turn spends and which beta behaviour it gets.
     *
     * @return array<string, mixed>
     */
    private function body(Model $model, TranscriptContext $context, ?GoogleOptions $options, string $project, ?string $runtime = null, ?string $retrySeed = null): array
    {
        // Both, so that the two cannot contradict each other: `thinkingEnabled: false` with a
        // level set means off, which is the reading every other provider here gives it.
        $level = ($options?->thinkingEnabled ?? false) ? $options?->thinkingLevel : null;
        [$resolved, $enum] = Routing::resolve($model->id, $level);

        // A runtime model `send()` fell back to goes out under its own enum.
        if ($runtime !== null && $runtime !== $resolved) {
            $enum = Routing::enumOf($runtime);
        } else {
            $runtime = $resolved;
        }

        $inner = ['contents' => self::pairToolResults(GoogleShared::contents($model, $context), $model->id, $runtime)];
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        // The prompt and the tools are the transcript's, replayed (pi-antigravity's
        // `resolveCurrentSystemPrompt()` / `resolveCurrentTools()` over pi-ai's
        // `getCurrentSystemPrompt()` / `getCurrentTools()`): Code Assist has no mid-conversation
        // system messages, so the current state goes in the request's own fields.
        $systemPrompt = Transcript::getCurrentSystemPrompt($context->messages);
        $tools = Transcript::getCurrentTools($context->messages);

        if ($systemPrompt !== '') {
            $instruction = GoogleShared::systemInstruction(self::sanitizeSystemPrompt($systemPrompt));

            // pi-antigravity 0.15: over the consumer cap, or when forced, the prompt goes in `contents`.
            if (self::shouldInlineSystem($instruction['parts'])) {
                $inner['contents'] = self::inlineSystemInstruction($inner['contents'], $instruction['parts']);
            } else {
                $inner['systemInstruction'] = $instruction;
            }
        }

        if ($tools !== []) {
            $inner['tools'] = GoogleShared::tools($tools);

            if ($options?->toolChoice !== null) {
                $inner['toolConfig'] = GoogleShared::toolConfig($options->toolChoice);
            }
        }

        // The budget comes from the routing tables and not from the caller: here a thinking
        // level already chose a different upstream model, and the budget is part of that choice
        // rather than a separate dial. `-1` is "as much as you like".
        $budget = Routing::thinkingBudget($runtime, $level);

        if ($model->reasoning && $budget !== 0) {
            $config['thinkingConfig'] = ['includeThoughts' => true, 'thinkingBudget' => $budget];
        }

        if ($config !== []) {
            $inner['generationConfig'] = $config;
        }

        // A request sent again is a different conversation as far as the deployment can tell:
        // the session, the trajectory and the agent id all come from `$retrySeed`.
        $seed = $retrySeed ?? self::seed($context);
        $trajectory = md5("antigravity:traj:{$seed}");
        $steps = count($inner['contents']);

        $sessionSeed = $retrySeed ?? $options?->sessionId ?? $seed;
        $inner['sessionId'] = self::toInt64SessionId($sessionSeed);
        $inner['labels'] = [
            'last_step_index' => (string) max(0, $steps - 1),
            'request_id' => $trajectory . '-' . self::turnsSoFar($context),
            'trajectory_id' => $trajectory,
            // Claude on this deployment wants a beta header's worth of behaviour, and these are
            // how it is asked for. `conservative` carries the same answer in the reference
            // implementation — both are sent, both the same.
            'used_claude' => self::isClaude($runtime) ? 'true' : 'false',
            'used_claude_conservative' => self::isClaude($runtime) ? 'true' : 'false',
            'used_non_gemini_model' => str_starts_with($runtime, 'gemini-') ? 'false' : 'true',
            'model_enum' => $enum,
        ];

        return [
            'project' => $project,
            'model' => $runtime,
            'request' => $inner,
            'requestType' => 'agent',
            'userAgent' => 'antigravity',
            'requestId' => sprintf(
                'agent/%s/%d/%s/%d',
                substr(md5("antigravity:agent:{$seed}"), 0, 16),
                Timestamp::nowMs(),
                $trajectory,
                max(1, $steps),
            ),
            // Only when the person turned overages on: this is what lets a request spend them.
            ...(Credits::enabled() ? ['enabledCreditTypes' => Credits::CREDIT_TYPES] : []),
        ];
    }

    /**
     * Something stable to derive this conversation's ids from.
     *
     * The first 64 **bytes** of the first message, which is the reference implementation's and is
     * deliberately not widened to characters: the only thing asked of this is that the same
     * conversation produces the same trajectory id every turn, and it goes straight into `md5()`.
     * A cut that lands inside a character is a different byte string, not a broken one — and
     * changing how it is cut would change every id, which is what the deployment caches against.
     */
    private static function seed(TranscriptContext $context): string
    {
        $text = '';
        // The first message of the conversation, not the system message that leads the transcript:
        // that one is the prompt, which is the same in every session.
        $conversation = array_values(array_filter(
            $context->messages,
            static fn (mixed $message): bool => !$message instanceof \Pig\Ai\SystemMessage,
        ));

        foreach ($conversation[0]->content ?? [] as $part) {
            if ($part instanceof TextContent) {
                $text = $part->text;

                break;
            }
        }

        // An opening turn that is only a picture has no text to key on. A constant is right
        // rather than something random: every turn of *this* conversation then agrees with
        // itself, which is the only property asked of the seed.
        return $text === '' ? 'conv' : substr($text, 0, 64);
    }

    /**
     * Signed int64 session ID: deterministic for nonempty seeds, random otherwise.
     */
    public static function toInt64SessionId(?string $seed = null): string
    {
        $trimmed = $seed !== null ? trim($seed) : '';

        if ($trimmed !== '') {
            if (preg_match('/^-?\d+$/', $trimmed) === 1) {
                if (PHP_INT_SIZE >= 8) {
                    $val = (int) $trimmed;
                    if ((string) $val === $trimmed) {
                        return $trimmed;
                    }
                }
            }

            $hash = hash('sha256', "antigravity:session:{$trimmed}", true);
            $unpacked = unpack('q', substr($hash, 0, 8));

            return (string) ($unpacked[1] ?? random_int(PHP_INT_MIN, PHP_INT_MAX));
        }

        return (string) random_int(PHP_INT_MIN, PHP_INT_MAX);
    }

    /** How many turns the assistant has already taken, which is the reference's `requestIndex`. */
    private static function turnsSoFar(TranscriptContext $context): int
    {
        return count(array_filter(
            $context->messages,
            static fn (mixed $message): bool => $message instanceof AssistantMessage,
        ));
    }

    private static function isClaude(string $runtime): bool
    {
        return str_starts_with($runtime, 'claude-');
    }

    /**
     * The token and the project, out of the one string an api key field can carry.
     *
     * @return array{0: string, 1: string}
     */
    public static function credentials(?string $apiKey): array
    {
        $decoded = $apiKey === null || $apiKey === '' ? null : json_decode($apiKey, true);
        $token = is_array($decoded) ? ($decoded['token'] ?? null) : null;
        $project = is_array($decoded) ? ($decoded['projectId'] ?? null) : null;

        // pi-antigravity 0.13: printable ASCII only, so neither can carry a line break into a header.
        $printable = static fn (mixed $value): bool => is_string($value) && preg_match('/^[\x21-\x7e]+$/D', $value) === 1;

        if (!$printable($token) || !$printable($project)) {
            throw new ProviderError(
                'Antigravity needs a token and a Cloud project together, which is what signing in '
                . 'stores. An ordinary Gemini API key cannot be used with antigravity models.',
            );
        }

        return [$token, $project];
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * What went wrong — pi-antigravity's `friendlyAntigravityError()`, word for word. The backend's
     * text is `jsonOrTextError()`'s (the error body's `error.message`, else the body), with secrets
     * redacted, cut at 500 characters.
     *
     * The 429 wording is what decides whether the session retries: a quota wall ("Individual quota
     * reached", a "Resets in …" hint, or quota wording that is not about rate limiting) is `Quota
     * reached. …` — which matches none of the retryable patterns, so the turn fails at once with
     * the time to wait — and any other 429 is transient throttling, `Rate limited by Antigravity
     * (429 ResourceExhausted)…`, which the session's backoff waits out.
     *
     * One 403 is worded here and not upstream: an account Google has suspended.
     */
    public static function friendlyAntigravityError(?int $status, string $text): string
    {
        $decoded = json_decode($text, true);
        $message = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['message'] ?? null) : null;
        // `typeof parsed.error?.message === "string"`: an empty message is the message.
        $msg = mb_substr(self::redactSecrets(is_string($message) ? $message : $text), 0, 500, 'UTF-8');

        if ($status === 400) {
            if (preg_match('/Requests ending with a model turn are not supported/i', $msg) === 1) {
                return 'Antigravity rejected an invalid conversation message boundary. Next: update the extension or add a user message / start a new session, then retry.';
            }

            if (preg_match('/function call turn comes immediately after a user turn or after a function response turn/i', $msg) === 1) {
                return 'Antigravity rejected an invalid function-call message boundary. Next: update the extension or start a new session, then retry; re-login is not required.';
            }

            if (preg_match('/API key not valid|API_KEY_INVALID/i', $msg) === 1) {
                return 'Antigravity login expired or credentials are invalid. Next: run /login antigravity, then retry.';
            }

            if (preg_match('/Invalid JSON payload|Unknown name/i', $msg) === 1) {
                return "Antigravity request format was rejected by the backend ({$msg}). Next: switch to a simpler model or retry after updating the extension.";
            }

            if (preg_match('/Request contains an invalid argument/i', $msg) === 1) {
                return "Antigravity rejected this request ({$msg}). Next: retry once; if it keeps failing, switch models or re-login.";
            }

            return "Bad request from Antigravity. Next: retry once, then run /login antigravity if it keeps failing. Backend said: {$msg}";
        }

        if ($status === 401) {
            return 'Antigravity authentication failed. Next: run /login antigravity, then retry.';
        }

        if ($status === 403) {
            $verifyUrl = self::extractValidationUrl($text);
            $verifyHint = $verifyUrl !== null
                ? " Open this link in a browser signed in to the same Google account to verify: {$verifyUrl}"
                : '';

            // Not upstream's wording — the developer's choice. Google answers a suspended
            // account with this sentence on every model, and upstream's "re-login or try another
            // model" sends the person round a loop that cannot end.
            if (preg_match('/disabled in this account for violation of Terms of Service/i', $msg) === 1) {
                return "Google has disabled Antigravity for this account (Terms of Service). Next: submit an appeal to Google; signing in again or switching models will not lift it. Backend said: {$msg}";
            }

            if (preg_match('/#3501|do not have a valid license of this product/i', $msg) === 1) {
                return "This account's Gemini Enterprise license was not accepted on the consumer endpoint. Next: set ANTIGRAVITY_LOCATION to eu, us, or global, plus ANTIGRAVITY_ENTERPRISE_PROJECT and ANTIGRAVITY_USER_TIER from the license selector, then retry. A regional location is not sent to cloudcode-pa.";
            }

            if (preg_match('/permission|forbidden|access/i', $msg) === 1 && $verifyUrl === null) {
                return 'Antigravity access was denied for this account or project. Next: try another model, re-login, or use an account with access.';
            }

            return "Antigravity denied this request. Next: re-login or try another model. Backend said: {$msg}{$verifyHint}";
        }

        if ($status === 404) {
            if (preg_match('/Requested entity was not found/i', $msg) === 1) {
                return 'This model is not available right now. Next: switch to gemini-3.8-flash, gemini-3.5-flash, claude-sonnet-5-5, gemini-2.5-pro, or another working model.';
            }

            return "Antigravity could not find the requested resource. Next: retry or switch models. Backend said: {$msg}";
        }

        if ($status === 408) {
            return 'Antigravity timed out. Next: retry the same request.';
        }

        if ($status === 409) {
            return 'Antigravity reported a conflict for this request. Next: retry once or start a new chat session.';
        }

        if ($status === 429) {
            if (preg_match('/AI credits balance is too low|INSUFFICIENT_G1_CREDITS/i', $msg) === 1) {
                return 'Your AI credits balance is exhausted. Please refill credits in Google One or wait for your regular quota to reset. Next: switch models or retry later.';
            }

            $wait = preg_match('/Resets? in ([^.\n]+)/i', $msg, $match) === 1 ? trim($match[1]) : '';
            $hint = Credits::enabled()
                ? ' (AI credits overage active; credit balance may be depleted)'
                : ' (Enable AI credits fallback with /antigravity.credits on)';

            if (preg_match('/Individual quota reached/i', $msg) === 1) {
                return 'Quota reached. Please wait ' . ($wait !== '' ? $wait : 'for reset') . ".{$hint} Next: switch models or try again after reset.";
            }

            $hardLimit = $wait !== ''
                || (preg_match('/rate.?limit/i', $msg) !== 1
                    && preg_match('/quota exceeded|exceeded your|limit reached|reached your|daily limit/i', $msg) === 1);

            if ($hardLimit) {
                return 'Quota reached.' . ($wait !== '' ? " Please wait {$wait}." : '') . "{$hint} Next: switch models or retry later.";
            }

            if (self::isRetryableRateLimit($msg)) {
                return 'Rate limited by Antigravity (429 ResourceExhausted). Next: retrying automatically; if it persists, switch models.';
            }

            // "A generic RESOURCE_EXHAUSTED body, with no reset hint, is how unavailable models
            // such as gemini-3.1-pro fail. Do not include 429/ResourceExhausted: those tokens make
            // Pi burn its automatic retry budget and look like an account quota wall." A body
            // that is not JSON carries them itself (`"code": 429`), so it is left out.
            return 'This model is unavailable right now (' . ($msg !== '' && !self::sessionWouldRetry($msg) ? $msg : 'resource exhausted') . '). Next: switch to gemini-3.8-flash or another Flash model. This is not necessarily an account quota wall.';
        }

        if ($status === 500) {
            return 'Antigravity had an internal server error. Next: retry in a moment or switch models.';
        }

        if ($status === 502) {
            return 'Antigravity returned a bad gateway error. Next: retry in a moment.';
        }

        if ($status === 503) {
            if (preg_match('/No capacity available/i', $msg) === 1) {
                return 'This model has no capacity right now. Next: retry later or switch to another model.';
            }

            return 'Antigravity is temporarily unavailable. Next: retry in a moment or switch models.';
        }

        if ($status === 504) {
            return 'Antigravity timed out upstream. Next: retry in a moment.';
        }

        return $msg;
    }

    /** Pull `validation_url` from a 403 body before message slicing can drop it. */
    public static function extractValidationUrl(string $text): ?string
    {
        try {
            $parsed = json_decode($text, true);
            $details = is_array($parsed) && is_array($parsed['error']['details'] ?? null)
                ? $parsed['error']['details']
                : null;
            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (is_array($detail) && is_array($detail['metadata'] ?? null)) {
                        $url = $detail['metadata']['validation_url'] ?? null;
                        if (is_string($url) && str_starts_with($url, 'https://accounts.google.com/')) {
                            return $url;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        if (preg_match('/https:\/\/accounts\.google\.com\/[^\s"\'<>]+/', $text, $match) === 1) {
            return rtrim($match[0], '),.;');
        }

        return null;
    }

    /** pi-antigravity's `redactSecrets()` — `Redaction`'s, kept here for the callers that ask this class. */
    public static function redactSecrets(string $text): string
    {
        return Redaction::redactSecrets($text);
    }
}
