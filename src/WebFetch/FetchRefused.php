<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use RuntimeException;

/**
 * A step of a read that failed, carrying what upstream hangs on its `Error` objects: whether
 * trying further is pointless (`fatal`), the HTTP status behind it, and whether it is a URL the
 * policy refuses (`unsafeTarget`).
 */
final class FetchRefused extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $fatal = false,
        public readonly ?int $status = null,
        public readonly bool $unsafeTarget = false,
    ) {
        parent::__construct($message);
    }
}
