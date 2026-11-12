<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

/**
 * "Which URLs the extension may ask anyone to fetch" — pi-antigravity's `utils/address.ts`.
 *
 * "Address literals are filtered against special-purpose IPv4 ranges and global IPv6 space. This
 * conservative filter is not a claim about DNS or future allocations."
 */
final class PublicUrl
{
    /** "Normalised URLs longer than this are refused rather than echoed or requested." */
    private const int MAX_URL_LENGTH = 2_048;

    /** "IPv4 ranges outside the global unicast space (IANA special-purpose registry)." */
    private const array IPV4_NOT_GLOBAL = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
        ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
        ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** "Special-purpose blocks inside 2000::/3 that are not globally reachable." */
    private const array IPV6_NOT_GLOBAL = [[[0x2001, 0x0000], 23], [[0x2001, 0x0db8], 32], [[0x2002], 16], [[0x3fff, 0x0000], 20]];

    /** "Special-use names that only resolve on a local network (RFC 6761 and later)." */
    private const array LOCAL_NAME_SUFFIXES = ['localhost', 'local', 'localdomain', 'internal', 'home.arpa', 'invalid', 'test', 'onion', 'alt'];

    public const array MESSAGES = [
        'missing' => 'A URL is required.',
        'invalid' => 'Not a valid public HTTP(S) URL.',
        'scheme' => 'Only http and https URLs can be read.',
        'credentials' => 'URLs with embedded credentials are not supported.',
        'local' => 'That URL is a private or local address, which the fetch service cannot reach.',
        'too-long' => 'The URL exceeds the 2,048-character limit.',
    ];

    /**
     * "May this URL be requested on the user's behalf? The answer is about the URL alone: a public
     * http(s) address with no embedded credentials and a bounded length."
     *
     * @return array{ok: true, url: string}|array{ok: false, reason: 'missing'|'invalid'|'scheme'|'credentials'|'local'|'too-long'}
     */
    public static function check(string $raw): array
    {
        $trimmed = Text::trim($raw);

        if ($trimmed === '') {
            return ['ok' => false, 'reason' => 'missing'];
        }

        $parsed = Url::parse($trimmed);

        if ($parsed === null) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        if (!$parsed->isWeb()) {
            return ['ok' => false, 'reason' => 'scheme'];
        }

        if ($parsed->username !== '' || $parsed->password !== '') {
            return ['ok' => false, 'reason' => 'credentials'];
        }

        if (self::isLocalHost($parsed->hostname())) {
            return ['ok' => false, 'reason' => 'local'];
        }

        $url = $parsed->href();

        if (strlen($url) > self::MAX_URL_LENGTH) {
            return ['ok' => false, 'reason' => 'too-long'];
        }

        return ['ok' => true, 'url' => $url];
    }

    /** "True for an IP literal outside the global space. Host names return false." */
    public static function isPrivateAddress(string $address): bool
    {
        $bare = self::bareAddress($address);

        return filter_var($bare, FILTER_VALIDATE_IP) !== false && !self::isGloballyRoutable($bare);
    }

    /** "Strip URL brackets and an interface zone (`fe80::1%eth0`)." */
    private static function bareAddress(string $address): string
    {
        $bare = str_starts_with($address, '[') && str_ends_with($address, ']') ? substr($address, 1, -1) : $address;

        return explode('%', $bare)[0];
    }

    private static function isGloballyRoutable(string $address): bool
    {
        $bare = self::bareAddress($address);

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isGlobalIpv4((int) ip2long($bare));
        }

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::isGlobalIpv6($bare);
        }

        return false;
    }

    private static function isGlobalIpv4(int $value): bool
    {
        if ($value === 0xFFFFFFFF) {
            return false;
        }

        foreach (self::IPV4_NOT_GLOBAL as [$base, $prefix]) {
            $start = (int) ip2long($base);

            if ($value >= $start && $value < $start + 2 ** (32 - $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function isGlobalIpv6(string $address): bool
    {
        $packed = inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        /** @var list<int> $words */
        $words = array_values((array) unpack('n8', $packed));

        // "IPv4-mapped (::ffff:a.b.c.d) and IPv4-translated (::ffff:0:a.b.c.d) forms carry an IPv4
        // destination: judge that address, so public IPv4 stays reachable."
        $mapped = array_sum(array_slice($words, 0, 5)) === 0 && $words[5] === 0xFFFF;
        $translated = array_sum(array_slice($words, 0, 4)) === 0 && $words[4] === 0xFFFF && $words[5] === 0;

        if ($mapped || $translated) {
            return self::isGlobalIpv4($words[6] * 65536 + $words[7]);
        }

        // "global unicast is 2000::/3"
        if (($words[0] & 0xE000) !== 0x2000) {
            return false;
        }

        foreach (self::IPV6_NOT_GLOBAL as [$base, $prefix]) {
            $inside = true;

            for ($bit = 0; $bit < $prefix; $bit += 16) {
                $index = intdiv($bit, 16);
                $mask = (0xFFFF << (16 - min(16, $prefix - $bit))) & 0xFFFF;

                if (($words[$index] & $mask) !== (($base[$index] ?? 0) & $mask)) {
                    $inside = false;

                    break;
                }
            }

            if ($inside) {
                return false;
            }
        }

        return true;
    }

    /** "Whether a host name or literal can only mean the local machine or network." */
    private static function isLocalHost(string $hostname): bool
    {
        $host = (string) preg_replace('/\.$/', '', strtolower($hostname));

        if ($host === '') {
            return true;
        }

        if (filter_var(self::bareAddress($host), FILTER_VALIDATE_IP) !== false) {
            return !self::isGloballyRoutable($host);
        }

        // "Decimal, hex, and short dotted forms (127.1, 0x7f000001) are IP literals too."
        if (preg_match('/^(?:0x[0-9a-f]+|\d+)$/', $host) === 1 || preg_match('/^(?:\d{1,3}|0x[0-9a-f]{1,2})(?:\.(?:\d{1,3}|0x[0-9a-f]{1,2})){1,3}$/', $host) === 1) {
            return true;
        }

        // "A single-label name resolves through a local search domain, never on the internet."
        if (!str_contains($host, '.')) {
            return true;
        }

        foreach (self::LOCAL_NAME_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, ".{$suffix}")) {
                return true;
            }
        }

        return false;
    }
}
