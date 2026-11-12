<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use DOMElement;

/**
 * What a crawled copy is, read before it is cleaned into Markdown — pi-antigravity's
 * `web-fetch/inspect.ts`: its metadata, the other forms the publisher advertises for it, whether
 * the copy ends where the page does, and whether what it holds is an article, nothing, a
 * JavaScript shell or an access wall. "Never execute page scripts."
 *
 * An inspection is `{rendered, completeness, assessment, notes?, metadata, alternates}`:
 * completeness is `complete`, `incomplete` or `unknown`; assessment is `readable`, `empty`,
 * `suspected-shell` or `suspected-access-wall`.
 */
final class Inspect
{
    /**
     * "Headings that are a wall on their own. They stay exact: "429 Too Many Requests" is also
     * the title of a real article about that status, and "Forbidden error handling" is a real
     * article title."
     */
    private const array ACCESS_WALL_HEADINGS = ['forbidden', '403 forbidden', 'unauthorized', '401 unauthorized', 'access denied'];

    /**
     * "Openings of the interstitials that stand in for an article. The heading of a real wall
     * usually carries more than the phrase itself ("Please enable JavaScript to continue"), so a
     * phrase that opens the heading marks a wall."
     */
    private const array ACCESS_WALL_OPENINGS = [
        'just a moment', 'just a second', 'one more step', 'robot or human', 'please wait while we verify',
        'access to this page has been denied', "you don't have permission", 'verifying your browser', 'too many requests',
        'verify you are human', 'verifying you are human', 'attention required', 'you have been blocked',
        'sorry, you have been blocked', 'checking your browser', 'security check', 'enable javascript',
        'please enable javascript', 'javascript is required', 'javascript required', 'are you a robot', 'unusual traffic',
        'request blocked', 'rate limited', 'you are being rate limited',
    ];

    /** "Sign-in pages read like a wall, but an article title may also start with one." */
    private const array ACCESS_WALL_SIGN_IN_OPENINGS = ['sign in', 'log in'];

    /** "How much text may follow the wall phrase before the heading is an article title." */
    private const int ACCESS_WALL_TAIL = 40;

    private const int ACCESS_WALL_SIGN_IN_TAIL = 24;

    private const string HEADING_LINES = '/^#{1,6} .*$/m';

    /** "A short block/sign-in heading, not an article that happens to discuss one." */
    private static function isAccessWallHeading(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $text = mb_strtolower(Text::trim((string) preg_replace(['/\s*[|•–—-]\s*cloudflare\s*$/Diu', '/\s+/'], ['', ' '], $value)), 'UTF-8');

        if ($text === '') {
            return false;
        }

        if (in_array(preg_replace('/[.!…\s]*$/Du', '', $text), self::ACCESS_WALL_HEADINGS, true)) {
            return true;
        }

        $opens = static fn (string $opening, int $tail): bool => str_starts_with($text, $opening)
            && (Text::length($text) - Text::length($opening) <= $tail || preg_match('/browser|human|verify|access/', $text) === 1);

        foreach (self::ACCESS_WALL_OPENINGS as $opening) {
            if ($opens($opening, self::ACCESS_WALL_TAIL)) {
                return true;
            }
        }

        foreach (self::ACCESS_WALL_SIGN_IN_OPENINGS as $opening) {
            if ($opens($opening, self::ACCESS_WALL_SIGN_IN_TAIL)) {
                return true;
            }
        }

        return false;
    }

    private static function withoutHeadings(string $rendered): string
    {
        return Text::trim((string) preg_replace(self::HEADING_LINES, '', $rendered));
    }

    /** "A wall heading alone cannot overrule substantial article content." */
    private static function hasArticleContent(string $rendered): bool
    {
        $body = self::withoutHeadings($rendered);
        $length = Text::length($body);

        return ($length >= 500 && preg_match('/[.!?:]/', $body) === 1)
            || ($length >= 80 && preg_match('/\b(?:HTTP|status code|response|client|server)\b/i', $body) === 1);
    }

    /** "Match structured data to this page without treating fragments as separate pages." */
    private static function pageIdentity(mixed $value, string $baseUrl): ?string
    {
        if (self::isRecord($value)) {
            $value = $value['@id'] ?? null;
        }

        return is_string($value) ? Url::parse($value, $baseUrl)?->withoutFragment()->href() : null;
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    public static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * "Inspect data before destructive Markdown cleanup."
     *
     * @return array{rendered: string, completeness: string, assessment: string, notes?: list<string>, metadata: array<string, ?string>, alternates: list<array{url: string, type: string, language?: string}>}
     * @throws DocumentLimitError
     */
    public static function page(string $content, string $url, ?string $contentType = null): array
    {
        if (!Markdown::looksLikeHtml($content, $contentType)) {
            $rendered = Text::trim(Text::repair($content));
            $title = null;
            $fence = null;

            foreach (explode("\n", $rendered) as $line) {
                $previous = $fence;
                $fence = Fences::advance($fence, $line);

                if ($previous === null && $fence === null && !Fences::isLine($line) && preg_match('/^ {0,3}# +(.+?)(?: +#+)?\s*$/D', $line, $match) === 1) {
                    $title = $match[1];

                    break;
                }
            }

            return [
                'rendered' => $rendered,
                'completeness' => 'unknown',
                'assessment' => $rendered !== '' ? 'readable' : 'empty',
                'metadata' => ['title' => Text::clean($title, 300)],
                'alternates' => [],
            ];
        }

        $document = Document::parse($content);
        $metadata = Markdown::extractPageMetadata($document);
        $lang = $document->documentElement?->getAttribute('lang') ?? '';
        $metadata['language'] = Text::clean($lang !== '' ? $lang : null, 50);

        // Structured data: at most sixteen blocks and 256,000 characters of it.
        $nodes = [];
        $budget = 256_000;

        foreach (array_slice(Document::select($document, '//script[@type="application/ld+json"]'), 0, 16) as $script) {
            $text = $script->textContent;
            $budget -= Text::length($text);

            if ($budget < 0) {
                break;
            }

            // "Invalid or truncated structured data is not evidence."
            $value = json_validate($text) ? json_decode($text, true) : null;

            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $root) {
                if (!self::isRecord($root)) {
                    continue;
                }

                $nodes[] = $root;

                foreach (is_array($root['@graph'] ?? null) && array_is_list($root['@graph']) ? $root['@graph'] : [] as $node) {
                    if (self::isRecord($node)) {
                        $nodes[] = $node;
                    }
                }
            }
        }

        $identity = self::pageIdentity($url, $url);
        $article = null;

        foreach ($nodes as $node) {
            $types = is_array($node['@type'] ?? null) ? $node['@type'] : [$node['@type'] ?? null];
            $isArticle = array_filter($types, static fn (mixed $type): bool => is_string($type) && preg_match('/^(Article|NewsArticle|BlogPosting|TechArticle)$/D', $type) === 1) !== [];
            $isThisPage = array_filter(
                [$node['url'] ?? null, $node['mainEntityOfPage'] ?? null, $node['@id'] ?? null],
                static fn (mixed $value): bool => $identity !== null && self::pageIdentity($value, $url) === $identity,
            ) !== [];

            if ($isArticle && $isThisPage) {
                $article = $node;

                break;
            }
        }

        if ($article !== null) {
            $text = static fn (mixed $value, string $field): ?string => is_string($value) && !Text::blank($value)
                ? Text::clean($value, Markdown::METADATA_LIMITS[$field])
                : null;
            $metadata['title'] ??= $text($article['headline'] ?? null, 'title');
            $metadata['description'] ??= $text($article['description'] ?? null, 'description');
            $metadata['updated'] ??= $text($article['dateModified'] ?? null, 'updated');
            $metadata['published'] = $text($article['datePublished'] ?? null, 'published');
            $metadata['language'] ??= $text($article['inLanguage'] ?? null, 'language');
            $author = $article['author'] ?? null;
            $author = is_array($author) && array_is_list($author) ? ($author[0] ?? null) : $author;
            $person = $author;

            if (self::isRecord($author) && empty($author['name']) && is_string($author['@id'] ?? null)) {
                $person = null;

                foreach ($nodes as $node) {
                    if (($node['@id'] ?? null) === $author['@id']) {
                        $person = $node;

                        break;
                    }
                }
            }

            $metadata['author'] = self::isRecord($person) ? $text($person['name'] ?? null, 'author') : $text($person, 'author');
        }

        $pageUrl = Url::parse($url);
        $alternates = [];

        foreach (Document::select($document, '//link[@href and @type]') as $link) {
            $alternate = self::alternate($link, $url, $pageUrl, $identity, $alternates);

            if ($alternate !== null) {
                $alternates[] = $alternate;
            }
        }

        $pageLanguage = isset($metadata['language']) ? strtolower(explode('-', $metadata['language'])[0]) : null;
        $sameLanguage = $pageLanguage !== null
            ? array_values(array_filter($alternates, static fn (array $item): bool => !isset($item['language']) || explode('-', $item['language'])[0] === $pageLanguage))
            : $alternates;
        $preferred = $sameLanguage !== [] ? $sameLanguage : $alternates;
        // A stable sort, Markdown before JSON.
        usort($preferred, static fn (array $a, array $b): int => (int) ($b['type'] === 'text/markdown') <=> (int) ($a['type'] === 'text/markdown'));

        $appScript = Document::first($document, '//script[@src or @type="module"]') !== null;
        $rendered = Markdown::htmlToMarkdown($content, $url, $document);
        $heading = preg_match('/^# +(.+)$/m', $rendered, $match) === 1 ? $match[1] : '';
        $completeness = Markdown::classifyCopyCompleteness($content);
        $withoutHeadings = self::withoutHeadings($rendered);

        // "A page that loads application JavaScript and shows only a placeholder is a shell. A
        // cut-off copy holding only a heading has lost its body; report it as empty."
        $bodyMissing = Text::blank($rendered) || ($completeness === 'incomplete' && $withoutHeadings === '');
        $assessment = match (true) {
            // "A copy that ends before the content is cut off, not an application shell."
            $bodyMissing => $appScript && $completeness !== 'incomplete' ? 'suspected-shell' : 'empty',
            self::isAccessWallHeading($heading !== '' ? $heading : ($metadata['title'] ?? null))
                && preg_match('/\b(?:check(?:ing)?|verif\w*|captcha|blocked|denied|permission|enable javascript|sign in|log in)\b/i', (string) preg_replace(self::HEADING_LINES, '', $rendered)) === 1
                && !self::hasArticleContent($rendered) => 'suspected-access-wall',
            $appScript && preg_match('/^(?:loading|please wait|redirecting|initializing)[.!…\s]*$/Diu', $withoutHeadings) === 1 => 'suspected-shell',
            default => 'readable',
        };

        return [
            'rendered' => $rendered,
            'completeness' => $completeness,
            'assessment' => $assessment,
            'metadata' => $metadata,
            'alternates' => array_slice($preferred, 0, 8),
        ];
    }

    /**
     * One `<link rel="alternate">` as a copy worth asking for: Markdown or JSON, on the page's own
     * origin, not the page itself, not a PDF and not one already listed.
     *
     * @param list<array{url: string, type: string, language?: string}> $listed
     * @return array{url: string, type: string, language?: string}|null
     */
    private static function alternate(DOMElement $link, string $url, ?Url $pageUrl, ?string $identity, array $listed): ?array
    {
        if (!in_array('alternate', preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [], true)) {
            return null;
        }

        $type = Text::trim(strtolower(explode(';', $link->getAttribute('type'))[0]));

        if ($type !== 'text/markdown' && $type !== 'application/json') {
            return null;
        }

        // "Ignore invalid URLs, including malformed base-relative references."
        $alternate = Url::parse($link->getAttribute('href'), $url);

        if ($alternate === null || $pageUrl === null || $alternate->origin() !== $pageUrl->origin() || $alternate->username !== '' || $alternate->password !== '' || preg_match('/\.pdf$/Di', $alternate->path) === 1) {
            return null;
        }

        $href = $alternate->withoutFragment()->href();

        if ($href === $identity || in_array($href, array_column($listed, 'url'), true)) {
            return null;
        }

        $language = strtolower(Text::clean($link->hasAttribute('hreflang') ? $link->getAttribute('hreflang') : null, 35) ?? '');

        return ['url' => $href, 'type' => $type, ...($language !== '' && $language !== 'x-default' ? ['language' => $language] : [])];
    }

    /**
     * "Inspection of a source the converter will not take on. Raw mode returns the crawled source
     * as it is, so the page's size limits the answer, not the read."
     *
     * @return array{rendered: string, completeness: string, assessment: string, notes: list<string>, metadata: array<string, ?string>, alternates: list<array{url: string, type: string, language?: string}>}
     */
    public static function unconverted(string $content, string $reason): array
    {
        return [
            'rendered' => '',
            'completeness' => Markdown::classifyCopyCompleteness($content),
            'assessment' => Text::blank($content) ? 'empty' : 'readable',
            'notes' => ["Not converted: {$reason}"],
            'metadata' => [],
            'alternates' => [],
        ];
    }

    /** "Decode only explicit article-body JSON shapes from an advertised representation." */
    public static function articleRepresentation(string $content, string $articleUrl): ?string
    {
        $value = json_validate($content) ? json_decode($content, true) : null;

        if (!self::isRecord($value)) {
            return null;
        }

        $identity = self::pageIdentity($articleUrl, $articleUrl);
        $meta = $value['meta'] ?? null;

        // GitHub Docs article API.
        if (self::isRecord($meta) && ($meta['documentType'] ?? null) === 'article' && is_string($value['body'] ?? null) && is_array($meta['breadcrumbs'] ?? null) && array_is_list($meta['breadcrumbs'])) {
            $current = $meta['breadcrumbs'] === [] ? null : $meta['breadcrumbs'][count($meta['breadcrumbs']) - 1];

            if (self::isRecord($current) && $identity !== null && self::pageIdentity($current['href'] ?? null, $articleUrl) === $identity) {
                return $value['body'];
            }
        }

        $post = $value['content'] ?? null;

        // "WordPress single-post REST representation (not an index or oEmbed summary)."
        if ((is_int($value['id'] ?? null) || is_float($value['id'] ?? null)) && is_string($value['link'] ?? null) && $identity !== null
            && self::pageIdentity($value['link'], $articleUrl) === $identity && self::isRecord($post) && ($post['protected'] ?? null) !== true && is_string($post['rendered'] ?? null)) {
            return $post['rendered'];
        }

        return null;
    }
}
