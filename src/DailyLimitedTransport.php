<?php

declare(strict_types=1);

namespace PigAntigravity;

use Override;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Async\AbortSignal;

/**
 * The transport every request to Google goes through: it holds back a request whose account has
 * had today's limit of 429s, and counts each 429 an account is answered with (`DailyRateLimits`).
 *
 * Every client this extension makes is wrapped in one, so no request can go round the limit;
 * `DailyLimitsTest` fails on an `HttpClient` made anywhere else.
 */
final class DailyLimitedTransport implements HttpTransport
{
    public function __construct(private readonly HttpTransport $inner)
    {
    }

    /** @throws DailyLimitReached before anything is sent, when the account has reached the limit */
    #[Override]
    public function send(Request $request, ?AbortSignal $signal = null): Response
    {
        $account = DailyRateLimits::accountOf($request);

        if ($account !== null) {
            DailyRateLimits::assertUnder($account);
        }

        $response = $this->inner->send($request, $signal);

        if ($account !== null && $response->status === 429) {
            DailyRateLimits::record($account);
        }

        return $response;
    }
}
