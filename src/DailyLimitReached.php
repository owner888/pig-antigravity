<?php

declare(strict_types=1);

namespace PigAntigravity;

use RuntimeException;

/**
 * An account reached today's limit of 429s (`DailyRateLimits`), so the request was not sent.
 *
 * Worded for the person and for nobody else: it ends the turn as its error, which pig keeps out
 * of every later request (an errored assistant message is not sent to the model), and it names
 * no status code on its own and no rate limit (`Retry::isRetryableAssistantError()` is false for
 * it), so pig's session does not send the turn again by itself.
 */
final class DailyLimitReached extends RuntimeException
{
    public function __construct(public readonly int $count, public readonly int $limit)
    {
        parent::__construct(
            "Antigravity is stopped on this account for today: Google refused it {$count} times since midnight, and the daily limit is {$limit} "
            . '(`antigravity.daily429Limit` in ~/.pig/agent/settings.json). Nothing more was sent, so Google sees no further requests from this account today. '
            . 'Next: wait until tomorrow, or switch account with /antigravity.accounts rotate.',
        );
    }
}
