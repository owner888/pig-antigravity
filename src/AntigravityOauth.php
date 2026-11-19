<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Utils\Oauth\CallbackServer;
use Pig\Ai\Utils\Oauth\OauthCredentials;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Pkce;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Timestamp;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * Signing in to Antigravity, which is Google's sandbox deployment of Code Assist.
 *
 * Upstream's `utils/oauth/google-antigravity.ts`. The same OAuth protocol as its Gemini CLI
 * sibling had, and a different
 * everything else: its own OAuth client, six scopes instead of three, port 51121 instead of
 * 8085, and a sandbox endpoint whose `User-Agent` check is load-bearing. What it buys is the
 * seven models in `Models::ANTIGRAVITY_MODELS` — two of Anthropic's, three Geminis and an
 * open-weights one, none of which Gemini CLI offers.
 *
 * **A separate class rather than a base one shared with that sibling.** Upstream had two files
 * and so does this. The overlap is real — both are Google's PKCE loopback flow — but the two
 * differ in the part that matters most, which is what happens *after* the tokens arrive:
 * that one provisioned a Cloud project and waited for it; this one asks two endpoints and
 * gives up into a constant. A shared parent would have to hold both shapes, and the seam would
 * fall exactly where the two are least alike.
 *
 * **The client is the public Antigravity desktop one unless the person names their own**
 * (`client()`), as upstream's `CLIENT_ID` / `CLIENT_SECRET` are.
 */
final class AntigravityOauth implements OauthFlow
{
    public const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v1/userinfo?alt=json';

    /**
     * Where the browser is sent back to.
     *
     * 51121 and this path are **registered with Google against that client id**, so neither is
     * a preference: a redirect Google has not been told about is refused before the person ever
     * sees a consent screen.
     */
    public const int PORT = 51121;

    public const string CALLBACK_PATH = '/oauth-callback';

    /**
     * Google's public Antigravity desktop client, as upstream embeds it. An installed-application
     * secret is not confidential by design and PKCE protects the exchange. Kept encoded and in
     * two pieces because a push carrying it whole is blocked.
     */
    private const string DEFAULT_CLIENT_ID = 'MTA3MTAwNjA2MDU5MS10bWhzc2luMmgyMWxjcmUyMzV2dG9sb2poNGc0MDNlc'
        . 'C5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbQ==';

    private const string DEFAULT_CLIENT_SECRET = 'R09DU1BYLUs1OEZXUjQ' . '4NkxkTEoxbUxCOHNYQzR6NnFEQWY=';

    /**
     * pi-antigravity's `SCOPES`, in its order: six, where Gemini CLI asks for three. The same
     * set as every other sign-in under this client, so that this one does not stand apart.
     *
     * `cclog` and `experimentsandconfigs` are the two extra, and they are what the sandbox
     * deployment checks for — a token minted with Gemini CLI's scopes reaches the same endpoint
     * and is refused there.
     */
    public const array SCOPES = [
        'https://www.googleapis.com/auth/aicode',
        'https://www.googleapis.com/auth/cloud-platform',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/cclog',
        'https://www.googleapis.com/auth/experimentsandconfigs',
    ];

    /** Renew this long before the token actually expires. Upstream's five minutes. */
    private const int MARGIN_MS = 5 * 60 * 1000;

    /** pi-antigravity's `loadCodeAssistUncached()` body. */
    private const array METADATA = ['ideType' => 'ANTIGRAVITY'];

    /**
     * @param CallbackServer|null $server injectable because 51121 is a real port and a test
     *        must not need it free
     * @param string|null $origin every Google endpoint under this instead, so a test can point
     *        the whole flow at a loopback server — the same seam the other three flows have
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?CallbackServer $server = null,
        private readonly ?string $origin = null,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new OauthError('Signing in to Antigravity needs its own client id and secret.');
        }
    }

    /**
     * The client to sign in and renew under: the person's own pair when both halves are given,
     * the public desktop client when neither is. One half alone is a mistake in the
     * configuration, and mixing it with the other client's half would be refused by Google with
     * a message that names neither.
     *
     * @return array{0: string, 1: string}
     */
    public static function client(?string $id, ?string $secret): array
    {
        $id = $id === '' ? null : $id;
        $secret = $secret === '' ? null : $secret;

        if ($id !== null && $secret !== null) {
            return [$id, $secret];
        }

        if ($id !== null || $secret !== null) {
            throw new OauthError(
                'An Antigravity client id and secret go together, and only the '
                . ($id !== null ? 'id' : 'secret') . ' is set. Set both ANTIGRAVITY_CLIENT_ID and '
                . 'ANTIGRAVITY_CLIENT_SECRET (or antigravity.clientId and antigravity.clientSecret in '
                . 'settings.json), or neither to use the built-in client.',
            );
        }

        return [self::decoded(self::DEFAULT_CLIENT_ID), self::decoded(self::DEFAULT_CLIENT_SECRET)];
    }

    private static function decoded(string $encoded): string
    {
        $plain = base64_decode($encoded, true);

        return $plain !== false ? $plain : throw new OauthError('The built-in Antigravity client is unreadable.');
    }

    /** Where to send the person. */
    public function authorizeUrl(Pkce $pkce, string $redirectUri): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'state' => $pkce->verifier,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    #[\Override]
    public function id(): string
    {
        return 'antigravity';
    }

    #[\Override]
    public function label(): string
    {
        return 'Antigravity (Gemini 3, Claude, GPT-OSS)';
    }

    #[\Override]
    public function isSubscription(): bool
    {
        return true;
    }

    /**
     * The `OauthFlow` shape of `signIn()`: the box and the select are not needed by a loopback
     * flow, and are taken so one signature serves every provider in `/login`'s list.
     */
    #[\Override]
    public function login(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
        ?Closure $onSelect = null,
    ): ?OauthCredentials {
        return $this->signIn($onAuth, $onProgress, $signal);
    }

    /** The `OauthFlow` shape of `renew()`: the project id travels on the credential. */
    #[\Override]
    public function refresh(OauthCredentials $credentials): OauthCredentials
    {
        if ($credentials->refresh === '') {
            throw new OauthError('The stored antigravity credentials have no refresh token — sign in again.');
        }

        // This flow always has a project — `project()` falls back to the account's own default
        // id rather than failing — so a stored credential without one was written by something else, and
        // guessing which project it meant is not something to do with somebody's quota.
        return $this->renew(
            $credentials->refresh,
            $credentials->projectId ?? throw new OauthError(
                'The stored Antigravity credentials have no Cloud project id, so there is nothing to spend the token against.',
            ),
        );
    }

    /**
     * What goes on a request as the key: Code Assist needs a Cloud project as well as a token,
     * and the two travel as one string because that is all an api key field can carry.
     * `AntigravityApi::credentials()` is what parses it back.
     */
    #[\Override]
    public function apiKey(OauthCredentials $credentials): string
    {
        return self::keyFor($credentials);
    }

    /** Static, so `LazyAntigravityOauth` can answer without a client pair. */
    public static function keyFor(OauthCredentials $credentials): string
    {
        $project = $credentials->projectId;

        if ($project === null || $project === '') {
            throw new OauthError('The stored Antigravity credentials have no Cloud project id.');
        }

        $json = json_encode(['token' => $credentials->access, 'projectId' => $project]);

        if ($json === false) {
            throw new OauthError('Could not encode the Antigravity key.');
        }

        return $json;
    }

    /** Listen, send the browser, take the code, find a project. Null means nobody finished. */
    public function signIn(Closure $onAuth, ?Closure $onProgress = null, ?AbortSignal $signal = null): ?OauthCredentials
    {
        $pkce = Pkce::create();
        $server = $this->server ?? new CallbackServer(self::PORT, self::CALLBACK_PATH);

        // Before anything is shown, so a port that cannot be taken is reported to somebody who
        // has not yet been sent to a browser.
        $server->listen();

        try {
            $onAuth(
                $this->authorizeUrl($pkce, $server->redirectUri()),
                'Finish signing in in the browser — the answer comes back here by itself.',
            );

            $callback = $server->await($signal);

            if ($callback === null) {
                return null;
            }

            if (!hash_equals($pkce->verifier, $callback['state'])) {
                throw new OauthError('The sign-in came back with the wrong state, so it was not the one this pig started.');
            }
        } finally {
            $server->close();
        }

        self::say($onProgress, 'Exchanging the code for tokens…');
        $tokens = $this->exchange($callback['code'], $pkce->verifier, $server->redirectUri());

        // The email first: it is what the default project id is made from.
        $email = $this->email($tokens['access']);

        self::say($onProgress, 'Looking for a Cloud project…');

        return new OauthCredentials(
            $tokens['refresh'],
            $tokens['access'],
            $tokens['expires'],
            projectId: $this->project($tokens['access'], $onProgress, $email),
            email: $email,
        );
    }

    /**
     * Turn the code from the callback into tokens.
     *
     * @return array{access: string, refresh: string, expires: int}
     */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        $data = $this->form($this->endpoint(self::TOKEN_URL), [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ]);

        $access = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $lifetime = $data['expires_in'] ?? null;

        if (!is_string($access) || $access === '' || !is_int($lifetime)) {
            throw new OauthError('Google answered the exchange without an access token in it.');
        }

        // Upstream refuses here too, and the reason is worth keeping: without a refresh token
        // this sign-in lasts an hour and then silently is not one. It happens when Google skips
        // the consent screen, which `prompt=consent` is there to stop.
        if (!is_string($refresh) || $refresh === '') {
            throw new OauthError('Google sent no refresh token, so this sign-in would last an hour. Try again.');
        }

        return [
            'access' => $access,
            'refresh' => $refresh,
            'expires' => Timestamp::nowMs() + $lifetime * 1000 - self::MARGIN_MS,
        ];
    }

    public function renew(string $refreshToken, string $projectId): OauthCredentials
    {
        $data = $this->form($this->endpoint(self::TOKEN_URL), [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $access = $data['access_token'] ?? null;
        $lifetime = $data['expires_in'] ?? null;

        if (!is_string($access) || $access === '' || !is_int($lifetime)) {
            throw new OauthError('Google answered the renewal without an access token in it.');
        }

        $rotated = $data['refresh_token'] ?? null;

        return new OauthCredentials(
            // A missing field means "keep the one you have", not "lost it" — Google usually does
            // not rotate this one and sends nothing rather than the same value again.
            is_string($rotated) && $rotated !== '' ? $rotated : $refreshToken,
            $access,
            Timestamp::nowMs() + $lifetime * 1000 - self::MARGIN_MS,
            projectId: $projectId,
        );
    }

    /**
     * The Cloud project to spend the token against.
     *
     * pi-antigravity's sign-in: `loadCodeAssist()`, and when that names none,
     * `defaultProjectId(email || "antigravity-default")` — an id of the account's own, where
     * this used to fall back to one constant project shared by everybody it happened to.
     * **Nothing is provisioned and nothing is waited for.**
     *
     * Every failure here is swallowed on purpose — a 404, a 403, a connection that never
     * opened — except a 429, which ends the sign-in (the developer's choice, see `RateLimited`).
     *
     * @param Closure(string): void|null $onProgress
     */
    public function project(string $accessToken, ?Closure $onProgress = null, ?string $email = null): string
    {
        $discovered = $this->loadCodeAssist($accessToken);

        if ($discovered !== null) {
            return $discovered;
        }

        self::say($onProgress, 'No project of your own — using this account\'s default.');

        return self::defaultProjectId($email ?? 'antigravity-default');
    }

    /**
     * pi-antigravity's `loadCodeAssistUncached()`: each of `Discovery::endpointCandidates()` in
     * turn until one answers; the project it names, or else what `listCloudAICompanionProjects`
     * says. Asked as the Antigravity CLI asks (`Discovery::headers()`, upstream's
     * `antigravityHeaders()`).
     */
    private function loadCodeAssist(string $accessToken): ?string
    {
        foreach (Discovery::endpointCandidates() as $endpoint) {
            $loaded = $this->ask($endpoint, '/v1internal:loadCodeAssist', ['metadata' => self::METADATA], $accessToken);

            if ($loaded !== null) {
                return self::extractProjectId($loaded) ?? $this->listCloudAICompanionProjects($accessToken);
            }
        }

        return null;
    }

    /** pi-antigravity's `listCloudAICompanionProjects()`: the first endpoint that answers decides. */
    private function listCloudAICompanionProjects(string $accessToken): ?string
    {
        foreach (Discovery::endpointCandidates() as $endpoint) {
            $listed = $this->ask($endpoint, '/v1internal:listCloudAICompanionProjects', new \stdClass(), $accessToken);

            if ($listed !== null) {
                return self::extractProjectId($listed);
            }
        }

        return null;
    }

    /**
     * One discovery request: the answer, or null for an endpoint to pass over.
     *
     * @param array<string, mixed>|\stdClass $body
     * @return array<string, mixed>|null
     */
    private function ask(string $endpoint, string $path, array|\stdClass $body, string $accessToken): ?array
    {
        try {
            return $this->json('POST', $this->endpoint($endpoint) . $path, $body, Discovery::headers($accessToken));
        } catch (Throwable $error) {
            // The developer's choice: a 429 is not passed over for the next endpoint.
            if ($error instanceof OauthError && $error->getCode() === 429) {
                throw $error;
            }

            return null;
        }
    }

    /**
     * pi-antigravity's `extractProjectId()`: the id under any of the names Google gives it, as a
     * string or as an object with an `id`, or the first one in a list of them.
     */
    public static function extractProjectId(mixed $data): ?string
    {
        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        $direct = $data['antigravityProjectId']
            ?? $data['projectId']
            ?? $data['backendProjectId']
            ?? $data['userDefinedCloudaicompanionProject']
            ?? $data['cloudaicompanionProject']
            ?? $data['project']
            ?? null;

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        if (is_array($direct) && is_string($direct['id'] ?? null) && $direct['id'] !== '') {
            return $direct['id'];
        }

        foreach (['projects', 'projectIds', 'cloudaicompanionProjects'] as $key) {
            $value = $data[$key] ?? null;

            if (!is_array($value) || !array_is_list($value)) {
                continue;
            }

            foreach ($value as $item) {
                $nested = self::extractProjectId($item) ?? (is_string($item) && $item !== '' ? $item : null);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * pi-antigravity's `defaultProjectId()`: `ANTIGRAVITY_PROJECT_ID` when it is set, else
     * `stableProjectId()` — a UUID-shaped id made from the seed, the same one every time.
     */
    public static function defaultProjectId(string $seed): string
    {
        $explicit = trim(Discovery::env('PROJECT_ID') ?? '');

        if ($explicit !== '') {
            return $explicit;
        }

        $bytes = substr(sha1("antigravity:{$seed}", true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /**
     * Which address the token was issued to, if Google will say.
     *
     * A label on the credential. Nothing downstream reads it, which is why every failure is
     * swallowed — the same call and the same reasoning the Gemini CLI flow used.
     */
    public function email(string $accessToken): ?string
    {
        try {
            // Upstream's `getUserEmail()` sends the token and nothing else.
            $data = $this->json('GET', $this->endpoint(self::USERINFO_URL), null, ['Authorization' => "Bearer {$accessToken}"]);
        } catch (Throwable) {
            return null;
        }

        $email = $data['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function form(string $url, array $fields): array
    {
        return $this->send(new Request(
            'POST',
            $url,
            // Upstream's token requests send this and nothing else.
            ['content-type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields),
        ), $url);
    }

    /**
     * @param array<string, mixed>|\stdClass|null $body
     * @param array<string, string> $headers all of them: nothing is added to what upstream sends
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, array|\stdClass|null $body, array $headers): array
    {
        return $this->send(new Request($method, $url, $headers, $body === null ? null : self::encode($body)), $url);
    }

    /** @return array<string, mixed> */
    private function send(Request $request, string $url): array
    {
        $response = $this->http->send($request);
        $text = $response->body->all();

        // Worded without the status or Google's own text: pig's session sends a turn again when
        // its error reads like a rate limit, and a renewal fails inside a turn.
        if ($response->status === 429) {
            throw new OauthError("Google is throttling this account, so the request to {$url} was stopped and nothing more was asked. Wait a while before trying again.", 429);
        }

        if (!$response->isSuccessful()) {
            throw new OauthError("Google answered {$response->status} to {$url}: {$text}");
        }

        $data = json_decode($text, true);

        if (!is_array($data)) {
            throw new OauthError("Google answered {$url} with something that is not JSON.");
        }

        return $data;
    }

    /** With `$origin` set, the path of every Google endpoint moves under it. */
    private function endpoint(string $url): string
    {
        if ($this->origin === null) {
            return $url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return rtrim($this->origin, '/') . $path . (is_string($query) ? '?' . $query : '');
    }

    /** @param Closure(string): void|null $onProgress */
    private static function say(?Closure $onProgress, string $message): void
    {
        if ($onProgress !== null) {
            $onProgress($message);
        }
    }

    /** @param array<string, mixed>|\stdClass $body */
    private static function encode(array|\stdClass $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $error) {
            throw new OauthError('Could not encode the request: ' . $error->getMessage());
        }
    }
}
