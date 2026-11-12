<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

/**
 * "Keep a page too long for one answer on disk, the way Pi's own tools keep long output: the
 * caller shows the beginning and points to the file for the rest" — pi-antigravity's
 * `web-fetch/save.ts`.
 *
 * "Saving is a convenience around a read that already succeeded, so it never throws: a failure
 * becomes part of the answer, and the page that was read is not lost with it."
 */
final class SavedPage
{
    private const string DIRECTORY_PREFIX = 'pig-url-';

    private function __construct(
        public readonly ?string $path,
        public readonly ?string $reason = null,
    ) {
    }

    /** Write $text to `page.<extension>` in a new private temp directory. */
    public static function save(string $text, string $extension): self
    {
        // `TMPDIR` as it is now, which `sys_get_temp_dir()` only reads once per process.
        $base = getenv('TMPDIR');
        $base = is_string($base) && $base !== '' && is_dir($base) ? rtrim($base, '/') : sys_get_temp_dir();
        $directory = $base . '/' . self::DIRECTORY_PREFIX . bin2hex(random_bytes(6));
        $reason = null;
        // The warning is the reason, and belongs in the answer rather than on the terminal.
        set_error_handler(static function (int $number, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            $path = "{$directory}/page.{$extension}";

            if (mkdir($directory, 0o700) && file_put_contents($path, $text) === strlen($text) && chmod($path, 0o600)) {
                return new self($path);
            }

            // "A failed write can leave a partial page."
            if (is_dir($directory)) {
                if (is_file($path)) {
                    unlink($path);
                }

                rmdir($directory);
            }
        } finally {
            restore_error_handler();
        }

        return new self(null, Text::clean(\PigAntigravity\Redaction::redactSecrets($reason ?? 'the page could not be written'), 200) ?? 'unknown error');
    }

    /** "Remove a page that was saved for an answer that then could not be given." */
    public function discard(): void
    {
        if ($this->path === null) {
            return;
        }

        $directory = dirname($this->path);

        // "Callers pass only results from savePage; guard against deleting an unrelated path."
        if (!str_starts_with(basename($directory), self::DIRECTORY_PREFIX) || !str_starts_with(basename($this->path), 'page.')) {
            return;
        }

        if (is_file($this->path)) {
            unlink($this->path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    /** "Footer naming the saved page, or saying why there is none." */
    public function note(int $chars, string $next = 'Read the rest with your file tools.'): string
    {
        return $this->path !== null
            ? "\n\n[Full page saved to: {$this->path} (" . number_format($chars) . " characters). {$next}]"
            : "\n\n[The full page (" . number_format($chars) . " characters) could not be saved: {$this->reason}. Only the beginning is shown.]";
    }
}
