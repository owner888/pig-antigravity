<?php

declare(strict_types=1);

namespace PigAntigravity;

/**
 * Credentials taken out of text the extension renders — errors, diagnostics and URLs:
 * pi-antigravity 0.13's `redactSecrets()` (`utils/security.ts`).
 *
 * "One redaction policy, expressed as contexts rather than as one list of names.
 *
 * - Shapes: values Google issues have stable shapes (access tokens `ya29.`, refresh tokens `1//`,
 *   authorization codes `4/0A`, JWTs), so they are hidden wherever they appear, with or without a
 *   name.
 * - URL parameters: in a query or fragment a name is secret when its last word is a credential word
 *   (`?subscription_key=`, `#access_token=`). A value is looked inside only when it carries the
 *   syntax of a context itself: a nested query or URL, or a bare `name=value` assignment.
 * - Assignments in free text (`token=...`, `"password": "..."`): the same words, minus the ones
 *   that are ordinary prose or diagnostics.
 *
 * Strings a page supplies are content, not secrets, and are never passed through here."
 */
final class Redaction
{
    private const array CREDENTIAL_WORDS = [
        'token', 'secret', 'password', 'passwd', 'pwd', 'key', 'apikey', 'signature', 'sig', 'credential',
        'credentials', 'bearer', 'jwt', 'auth', 'authorization', 'session', 'sessionid', 'sid', 'code', 'state',
        'nonce', 'verifier', 'otp',
    ];

    /** "Compound names whose last word alone is not a credential word." */
    private const array CREDENTIAL_SUFFIXES = ['accesskeyid', 'accesskey', 'privatekey', 'clientsecret'];

    /**
     * "Words that name credentials in a URL but are ordinary in prose and diagnostics ("exit
     * code=1", "model state=UNAVAILABLE", "session=expired"). In free text they are only redacted
     * when the value has a credential shape."
     */
    private const array PROSE_WORDS = ['code', 'state', 'nonce', 'session', 'sessionid', 'sid', 'auth', 'key', 'sig', 'signature', 'credential', 'jwt'];

    private const array TOKEN_SHAPES = [
        '/\bya29\.[A-Za-z0-9._~+\/-]+=*/' => '[redacted-access-token]',
        '/\b1\/\/?[A-Za-z0-9_-]{20,}/' => '[redacted-refresh-token]',
        '/\b4\/0A[A-Za-z0-9_-]{10,}/' => '[redacted-authorization-code]',
        '/\bGOCSPX-[A-Za-z0-9_-]{10,}/' => '[redacted-client-secret]',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/' => '[redacted-jwt]',
        '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [redacted]',
    ];

    /**
     * "A query or fragment parameter. The name excludes `?`, so each separator is looked at once
     * and a URL with many separators is redacted in linear time."
     */
    private const string URL_PARAMETER = '/([?&#])([^=&#?\s]{1,256})=([^&#\s"\'<>]*)/';

    /**
     * "A name followed by `:` or `=` and a value. A quoted value keeps its quotes, so a JSON
     * document stays valid JSON after redaction."
     */
    private const string ASSIGNMENT = '/(["\']?)\b([A-Za-z][\w.-]{0,63})\1(\s*[:=]\s*)(?:"((?:[^"\\\\\n]++|\\\\.)*+)"|\'([^\'\n]*+)\'|([^\s"\',;&}\]]++))/';

    public static function redactSecrets(string $text): string
    {
        $redacted = self::replace('/\b(https?:\/\/)[^\/?#\s]*@/i', '$1[redacted]@', $text);

        foreach (self::TOKEN_SHAPES as $shape => $replacement) {
            $redacted = self::replace($shape, $replacement, $redacted);
        }

        return self::redactAssignments(self::redactParameters($redacted));
    }

    /** `preg_replace()` that leaves the text as it was when the engine gives up on it. */
    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? $text;
    }

    /** @return list<string> */
    private static function nameWords(string $name): array
    {
        $spaced = strtolower(self::replace('/([a-z0-9])([A-Z])/', '$1 $2', $name));

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $spaced) ?: [], static fn (string $word): bool => $word !== ''));
    }

    /** "Whether a parameter or field name carries a credential." */
    private static function isCredentialName(string $name, bool $inProse): bool
    {
        $words = self::nameWords($name);

        if ($words === []) {
            return false;
        }

        $last = $words[count($words) - 1];
        $qualifier = false;

        if (count($words) > 1) {
            foreach ($words as $word) {
                if (preg_match('/^(?:oauth|amz|goog|api|private|subscription|signing|access|secret|client)$/', $word) === 1) {
                    $qualifier = true;

                    break;
                }
            }
        }

        if (in_array($last, self::CREDENTIAL_WORDS, true)) {
            return !$inProse || !in_array($last, self::PROSE_WORDS, true) || $qualifier;
        }

        $compact = implode('', $words);

        foreach (self::CREDENTIAL_SUFFIXES as $suffix) {
            if (str_ends_with($compact, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** "A value long and random enough to be a credential rather than a word." */
    private static function looksLikeCredential(string $value): bool
    {
        return strlen($value) >= 16 && preg_match('/[0-9]/', $value) === 1 && preg_match('/^[A-Za-z0-9._~+\/=-]+$/D', $value) === 1;
    }

    /** `decodeURIComponent()` with `+` as a space, or null where that throws. */
    private static function decodeComponent(string $value): ?string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            return null;
        }

        $decoded = rawurldecode(str_replace('+', ' ', $value));

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : null;
    }

    private static function redactParameters(string $text, int $depth = 0): string
    {
        return preg_replace_callback(self::URL_PARAMETER, static function (array $match) use ($depth): string {
            [$whole, $separator, $name, $value] = $match;
            $decodedName = self::decodeComponent($name);

            if ($decodedName === null) {
                return $whole;
            }

            if (self::isCredentialName($decodedName, false)) {
                return "{$separator}{$name}=[redacted]";
            }

            // "Look inside a value only when it carries a context's own syntax."
            $decoded = self::decodeComponent($value);

            if ($decoded === null || $decoded === '') {
                return $whole;
            }

            $nested = preg_match('/^[?&#]/', $decoded) === 1 || preg_match('/:\/\/[^\s]*[?#]/', $decoded) === 1
                ? $depth >= 8 || self::redactParameters($decoded, $depth + 1) !== $decoded
                : preg_match('/^[\w.-]{1,64}=\S+$/D', $decoded) === 1 && self::isCredentialName(substr($decoded, 0, (int) strpos($decoded, '=')), false);

            return $nested ? "{$separator}{$name}=[redacted]" : $whole;
        }, $text) ?? $text;
    }

    private static function redactAssignments(string $text): string
    {
        return preg_replace_callback(self::ASSIGNMENT, static function (array $match): string {
            // A group that took no part in the match is null here, as it is `undefined` upstream.
            [$whole, $quote, $name, $separator] = $match;
            $double = $match[4] ?? null;
            $single = $match[5] ?? null;
            $bare = $match[6] ?? null;
            $value = $double ?? $single ?? $bare ?? '';

            if ($value === '' || str_starts_with($value, '[redacted')) {
                return $whole;
            }

            $words = self::nameWords($name);
            $last = $words === [] ? '' : $words[count($words) - 1];

            if (!self::isCredentialName($name, true) && !(in_array($last, self::PROSE_WORDS, true) && self::looksLikeCredential($value))) {
                return $whole;
            }

            $redacted = $double !== null ? '"[redacted]"' : ($single !== null ? "'[redacted]'" : '[redacted]');

            return "{$quote}{$name}{$quote}{$separator}{$redacted}";
        }, $text, flags: PREG_UNMATCHED_AS_NULL) ?? $text;
    }
}
