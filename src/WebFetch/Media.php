<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

/** "Media types have one owner for detection and PDF refusal" — pi-antigravity's `web-fetch/media.ts`. */
final class Media
{
    /** @return 'html'|'text'|'json'|'xml'|'pdf'|'unknown' */
    public static function kind(?string $value): string
    {
        $type = strtolower(trim(explode(';', $value ?? '')[0]));

        return match (true) {
            $type === 'text/html', $type === 'application/xhtml+xml' => 'html',
            $type === 'application/pdf' => 'pdf',
            $type === 'application/json', str_ends_with($type, '+json') => 'json',
            $type === 'text/xml', $type === 'application/xml', str_ends_with($type, '+xml') => 'xml',
            in_array($type, ['text/plain', 'text/markdown', 'text/x-markdown'], true) => 'text',
            default => 'unknown',
        };
    }
}
