<?php

declare(strict_types=1);

namespace PigAntigravity;

use Closure;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Utils\Oauth\OauthCredentials;
use Pig\Async\AbortSignal;

/**
 * `AntigravityOauth`, built the moment it is used and not before.
 *
 * A client pair set by halves is refused (`AntigravityOauth::client()`) — and this extension has
 * to load, register its provider and list its sign-in all the same, so that `/login antigravity`
 * can say *what is wrong* rather than the provider being silently absent. So the row in the list
 * is this, and the refusal happens on `login()` or `refresh()`, where there is somebody to tell.
 */
final class LazyAntigravityOauth implements OauthFlow
{
    /**
     * @param Closure(): array{0: string, 1: string} $client
     * @param (Closure(OauthCredentials): void)|null $onLogin told about a sign-in that finished
     */
    public function __construct(private readonly Closure $client, private readonly ?Closure $onLogin = null)
    {
    }

    #[\Override]
    public function id(): string
    {
        return Models::PROVIDER;
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

    #[\Override]
    public function login(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
        ?Closure $onSelect = null,
    ): ?OauthCredentials {
        $credentials = $this->flow()->login($onAuth, $onPrompt, $onProgress, $signal, $onSelect);

        if ($credentials !== null && $this->onLogin !== null) {
            ($this->onLogin)($credentials);
        }

        return $credentials;
    }

    #[\Override]
    public function refresh(OauthCredentials $credentials): OauthCredentials
    {
        return $this->flow()->refresh($credentials);
    }

    #[\Override]
    public function apiKey(OauthCredentials $credentials): string
    {
        // Needs no client: it is arithmetic on the credential, and it runs on every turn.
        return AntigravityOauth::keyFor($credentials);
    }

    private function flow(): AntigravityOauth
    {
        [$id, $secret] = ($this->client)();

        return new AntigravityOauth($id, $secret);
    }
}
