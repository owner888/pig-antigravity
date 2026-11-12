<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

/**
 * A URL as the reader needs one: parsed, resolved against a base and written back the way
 * JavaScript's `new URL()` does for `http:` and `https:` — which is the form upstream compares,
 * shows and sends, and PHP has no parser that answers in it.
 *
 * Only the two web schemes are taken apart. Any other scheme (`mailto:`, `javascript:`, `data:`)
 * is kept whole: all the reader asks of those is which scheme they are.
 *
 * Not the whole WHATWG algorithm: a host is lowercased, percent-decoded, IDNA-converted where the
 * intl extension is there, and an IPv4 host in any of its spellings (`127.1`, `0x7f000001`) is
 * written as a dotted quad; paths lose their dot segments. What it refuses, it refuses.
 */
final class Url
{
    private function __construct(
        public readonly string $scheme,
        public readonly string $username,
        public readonly string $password,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $path,
        public readonly ?string $query,
        public readonly ?string $fragment,
        private readonly ?string $opaque = null,
    ) {
    }

    /** `new URL(input, base)`, or null where that throws. */
    public static function parse(string $input, ?string $base = null): ?self
    {
        // Leading and trailing C0 controls and spaces go; tabs and newlines go wherever they are.
        $input = str_replace(["\t", "\n", "\r"], '', trim($input, "\x00..\x20"));

        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $input, $match) === 1) {
            $scheme = strtolower($match[1]);

            if ($scheme !== 'http' && $scheme !== 'https') {
                return new self($scheme, '', '', '', null, '', null, null, $input);
            }

            $rest = $match[2];
            $baseUrl = $base === null ? null : self::parse($base);

            // `https:path` against a base of the same scheme is relative to it.
            if ($baseUrl !== null && $baseUrl->scheme === $scheme && preg_match('#^[/\\\\]#', $rest) !== 1) {
                return self::relative($rest, $baseUrl);
            }

            return self::absolute($scheme, (string) preg_replace('#^[/\\\\]*#', '', $rest));
        }

        $baseUrl = $base === null ? null : self::parse($base);

        if ($baseUrl === null || $baseUrl->opaque !== null) {
            return null;
        }

        return self::relative($input, $baseUrl);
    }

    private static function absolute(string $scheme, string $afterSlashes): ?self
    {
        $end = strcspn($afterSlashes, '/\\?#');
        $authority = substr($afterSlashes, 0, $end);
        $rest = substr($afterSlashes, $end);
        $username = $password = '';
        $at = strrpos($authority, '@');

        if ($at !== false) {
            $userinfo = substr($authority, 0, $at);
            $authority = substr($authority, $at + 1);
            $colon = strpos($userinfo, ':');
            $username = self::encode($colon === false ? $userinfo : substr($userinfo, 0, $colon), self::USERINFO);
            $password = $colon === false ? '' : self::encode(substr($userinfo, $colon + 1), self::USERINFO);
        }

        $port = null;

        if (preg_match('/^(\[[^\]]*\]|[^:]*)(?::(.*))?$/s', $authority, $match) !== 1) {
            return null;
        }

        if (($match[2] ?? '') !== '') {
            if (preg_match('/^\d+$/', $match[2]) !== 1 || (int) $match[2] > 65535) {
                return null;
            }

            $port = (int) $match[2];
        }

        $host = self::host($match[1]);

        if ($host === null) {
            return null;
        }

        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        [$path, $query, $fragment] = self::split($rest);

        return new self($scheme, $username, $password, $host, $port, self::normalizePath($path), $query, $fragment);
    }

    private static function relative(string $input, self $base): self
    {
        if (preg_match('#^[/\\\\]{2}#', $input) === 1) {
            return self::absolute($base->scheme, (string) preg_replace('#^[/\\\\]*#', '', $input)) ?? $base;
        }

        [$path, $query, $fragment] = self::split($input);

        if ($path === '') {
            return new self($base->scheme, $base->username, $base->password, $base->host, $base->port, $base->path, $query ?? ($input === '' || $fragment !== null ? $base->query : null), $fragment);
        }

        if ($path[0] !== '/' && $path[0] !== '\\') {
            $path = substr($base->path, 0, (int) strrpos($base->path, '/') + 1) . $path;
        }

        return new self($base->scheme, $base->username, $base->password, $base->host, $base->port, self::normalizePath($path), $query, $fragment);
    }

    /** @return array{0: string, 1: ?string, 2: ?string} path, query, fragment — the last two encoded */
    private static function split(string $rest): array
    {
        $fragment = null;
        $hash = strpos($rest, '#');

        if ($hash !== false) {
            $fragment = self::encode(substr($rest, $hash + 1), self::FRAGMENT);
            $rest = substr($rest, 0, $hash);
        }

        $query = null;
        $mark = strpos($rest, '?');

        if ($mark !== false) {
            $query = self::encode(substr($rest, $mark + 1), self::QUERY);
            $rest = substr($rest, 0, $mark);
        }

        return [$rest, $query, $fragment];
    }

    private const string USERINFO = " \"#<>?`{}/:;=@[\\]^|";

    private const string PATH = " \"#<>?`{}^";

    private const string QUERY = " \"#<>'";

    private const string FRAGMENT = " \"<>`";

    /** Percent-encode controls, non-ASCII bytes and the characters of $set; existing escapes stay. */
    private static function encode(string $text, string $set): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F-\xFF' . preg_quote($set, '/') . ']/',
            static fn (array $match): string => '%' . strtoupper(bin2hex($match[0])),
            $text,
        );
    }

    private static function normalizePath(string $path): string
    {
        $segments = [];
        $parts = explode('/', str_replace('\\', '/', $path));
        $last = count($parts) - 1;

        foreach ($parts as $index => $part) {
            $lower = strtolower($part);

            if ($lower === '..' || $lower === '.%2e' || $lower === '%2e.' || $lower === '%2e%2e') {
                array_pop($segments);

                if ($index === $last) {
                    $segments[] = '';
                }
            } elseif ($lower === '.' || $lower === '%2e') {
                if ($index === $last) {
                    $segments[] = '';
                }
            } elseif ($index !== 0 || $part !== '') {
                $segments[] = self::encode($part, self::PATH);
            }
        }

        return '/' . implode('/', $segments);
    }

    private static function host(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        if ($raw[0] === '[') {
            $packed = str_ends_with($raw, ']') ? self::quiet(static fn () => inet_pton(substr($raw, 1, -1))) : false;

            return is_string($packed) && strlen($packed) === 16 ? '[' . self::ipv6($packed) . ']' : null;
        }

        $host = rawurldecode($raw);

        if (!mb_check_encoding($host, 'UTF-8')) {
            return null;
        }

        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : mb_strtolower($host, 'UTF-8');

            if (!is_string($ascii) || $ascii === '') {
                return null;
            }

            $host = $ascii;
        }

        $host = strtolower($host);

        if ($host === '' || preg_match('/[\x00-\x20#\/:<>?@\[\\\\\]^|%\x7F]/', $host) === 1) {
            return null;
        }

        // A host whose last label is a number is an IPv4 address in one of its spellings, or nothing.
        $labels = explode('.', rtrim($host, '.'));

        if (preg_match('/^(?:\d+|0x[0-9a-f]*)$/', (string) end($labels)) === 1) {
            return self::ipv4($labels);
        }

        return $host;
    }

    /** @param list<string> $labels */
    private static function ipv4(array $labels): ?string
    {
        if (count($labels) > 4) {
            return null;
        }

        $numbers = [];

        foreach ($labels as $label) {
            $number = match (true) {
                preg_match('/^0x([0-9a-f]*)$/', $label, $hex) === 1 => $hex[1] === '' ? 0 : (strlen(ltrim($hex[1], '0')) > 8 ? null : hexdec($hex[1])),
                preg_match('/^0[0-7]*$/', $label) === 1 => strlen(ltrim($label, '0')) > 11 ? null : octdec($label),
                preg_match('/^[1-9]\d*$/', $label) === 1 => strlen($label) > 10 ? null : (int) $label,
                default => null,
            };

            if ($number === null) {
                return null;
            }

            $numbers[] = (int) $number;
        }

        $last = array_pop($numbers);

        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }
        }

        if ($last >= 256 ** (4 - count($numbers))) {
            return null;
        }

        $value = $last;

        foreach ($numbers as $index => $number) {
            $value += $number * 256 ** (3 - $index);
        }

        return long2ip($value);
    }

    /** The eight words of a packed IPv6 address, written the way the URL standard writes them. */
    private static function ipv6(string $packed): string
    {
        /** @var list<int> $words */
        $words = array_values((array) unpack('n8', $packed));
        $bestStart = -1;
        $bestLength = 0;

        for ($index = 0; $index < 8; $index++) {
            $length = 0;

            while ($index + $length < 8 && $words[$index + $length] === 0) {
                $length++;
            }

            if ($length > $bestLength && $length > 1) {
                $bestStart = $index;
                $bestLength = $length;
            }

            $index += max(0, $length - 1);
        }

        $out = '';

        for ($index = 0; $index < 8; $index++) {
            if ($index === $bestStart) {
                $out .= $index === 0 ? '::' : ':';
                $index += $bestLength - 1;

                continue;
            }

            $out .= dechex($words[$index]) . ($index < 7 ? ':' : '');
        }

        return $out;
    }

    /** A call whose only way to say "no" is a warning beside a false return. */
    private static function quiet(\Closure $call): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /** Whether this is one of the two schemes that were taken apart. */
    public function isWeb(): bool
    {
        return $this->opaque === null;
    }

    /** `url.hostname`: the host without its port, brackets kept on an IPv6 literal. */
    public function hostname(): string
    {
        return $this->host;
    }

    public function origin(): string
    {
        return $this->opaque !== null ? 'null' : "{$this->scheme}://{$this->host}" . ($this->port !== null ? ":{$this->port}" : '');
    }

    public function href(): string
    {
        if ($this->opaque !== null) {
            return $this->opaque;
        }

        $userinfo = $this->username !== '' || $this->password !== ''
            ? $this->username . ($this->password !== '' ? ":{$this->password}" : '') . '@'
            : '';

        return "{$this->scheme}://{$userinfo}{$this->host}" . ($this->port !== null ? ":{$this->port}" : '')
            . $this->path
            . ($this->query !== null ? "?{$this->query}" : '')
            . ($this->fragment !== null ? "#{$this->fragment}" : '');
    }

    public function withoutFragment(): self
    {
        return new self($this->scheme, $this->username, $this->password, $this->host, $this->port, $this->path, $this->query, null, $this->opaque);
    }

    public function withPath(string $path): self
    {
        return new self($this->scheme, $this->username, $this->password, $this->host, $this->port, $path, $this->query, $this->fragment, $this->opaque);
    }

    /** The same origin with another path and query, and no fragment. */
    public function at(string $path, ?string $query = null): self
    {
        return new self($this->scheme, $this->username, $this->password, $this->host, $this->port, $path, $query, null);
    }
}
