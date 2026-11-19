<?php

declare(strict_types=1);

namespace PigAntigravity;

use RuntimeException;

/**
 * Google answered 429, and nothing more is asked on that account's behalf.
 *
 * The developer's choice, not upstream's, which goes on to the next endpoint, model or account.
 * Google suspends accounts, and a burst of 429s comes first; whether to ask again is the
 * person's to decide. A class of its own so that the loops which pass over a failed endpoint
 * (`catch (Throwable)`) can let this one through.
 */
final class RateLimited extends RuntimeException
{
    /** @param string $what what was being done, as the start of a sentence: "Image generation" */
    public static function during(string $what, string $backendText): self
    {
        return new self("{$what} stopped at the first refusal, and nothing was sent again: " . AntigravityApi::friendlyAntigravityError(429, $backendText));
    }
}
