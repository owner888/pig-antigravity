<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use RuntimeException;

/**
 * A read that could not be given, in words safe to show: `Could not read <url>: <why>` and the
 * trail of what was tried — pi-antigravity's `WebFetchError`.
 */
final class WebFetchError extends RuntimeException
{
    /**
     * @param list<array{url: string, source: string, outcome: string, endpoint?: string, stage?: string}> $attempts
     * @param string|null $unconvertedCopy Google's copy, when it was beyond the conversion limits,
     *        for the caller to save
     */
    public function __construct(
        string $url,
        string $detail,
        public readonly array $attempts = [],
        public readonly ?string $unconvertedCopy = null,
    ) {
        $trail = WebFetch::formatAttemptTrail($attempts);

        parent::__construct(Text::repair(\PigAntigravity\Redaction::redactSecrets("Could not read {$url}: {$detail}" . ($trail !== '' ? " (Attempts: {$trail})" : ''))));
    }

    /**
     * pi-antigravity's `withSavedCopy()`: the message, pointing to Google's unconverted copy saved
     * for searching when there is one.
     */
    public function withSavedCopy(): string
    {
        if ($this->unconvertedCopy === null || $this->unconvertedCopy === '') {
            return $this->getMessage();
        }

        $saved = SavedPage::save($this->unconvertedCopy, 'html');
        $where = $saved->path !== null ? "was saved to: {$saved->path}. Search it with your file tools." : "could not be saved: {$saved->reason}.";

        return "{$this->getMessage()} Google's unconverted HTML (" . number_format(Text::length($this->unconvertedCopy)) . " characters) {$where}";
    }
}
