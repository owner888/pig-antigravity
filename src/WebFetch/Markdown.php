<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * A page's content chosen, its links resolved and its metadata read — pi-antigravity's
 * `web-fetch/markdown.ts`. Parsing is `Document`'s and Markdown rendering `Turndown`'s; "this
 * module only chooses page content, resolves links, and reads the crawler's metadata".
 */
final class Markdown
{
    /** "Page-controlled field limits, shared by extraction and display" (`metadata.ts`). */
    public const array METADATA_LIMITS = ['title' => 300, 'description' => 1_000, 'updated' => 100, 'author' => 300, 'published' => 100, 'language' => 50];

    private const array DROPPED_ELEMENTS = ['script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'form', 'select', 'option', 'button'];

    /** "Class and id names that mark site chrome rather than page content." */
    private const string CHROME_NAME = '/^(?:footer|sidebar|toc|nav|menu|breadcrumb|masthead|social)$/D';

    /** "An element this large a share of the page is the page, whatever its class says." */
    private const float CONTENT_SHARE = 0.4;

    private const int CONTENT_MIN_CHARS = 500;

    private const int MAX_TABLE_GRID_CELLS = 10_000;

    private const array HTML_VOID_TAGS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const string HTML_ELEMENTS = ' html head body title meta link script style noscript template main article section header footer nav aside div p span a strong em b i u s del strike code pre blockquote h1 h2 h3 h4 h5 h6 ul ol li dl dt dd table caption thead tbody tfoot tr th td figure figcaption picture img source br hr form label input button select option textarea details summary time address small sub sup abbr cite q mark kbd samp var wbr ';

    /** XPath for the elements that are the page's content region: `main, [role="main"], article`. */
    private const string REGIONS = './/main | .//*[@role="main"] | .//article';

    /**
     * "Read metadata through the HTML parser, not a search through attribute text."
     *
     * @return array{title: ?string, description: ?string, updated: ?string}
     */
    public static function extractPageMetadata(DOMDocument $document): array
    {
        $meta = static function (string $attribute, string $value) use ($document): ?string {
            $element = Document::first($document, "//meta[@{$attribute}='{$value}']");
            $content = $element === null ? null : Document::attribute($element, 'content');

            return $content === null || $content === '' ? null : $content;
        };
        $title = Document::first($document, '//title');
        $titleText = $title === null ? '' : Text::trim($title->textContent);

        return [
            'title' => Text::clean($meta('property', 'og:title') ?? $meta('name', 'twitter:title') ?? ($titleText !== '' ? $titleText : null), self::METADATA_LIMITS['title']),
            'description' => Text::clean($meta('property', 'og:description') ?? $meta('name', 'description') ?? $meta('name', 'twitter:description'), self::METADATA_LIMITS['description']),
            'updated' => Text::clean($meta('property', 'article:modified_time') ?? $meta('property', 'og:updated_time') ?? $meta('name', 'last-modified') ?? $meta('name', 'lastmod'), self::METADATA_LIMITS['updated']),
        ];
    }

    /**
     * "Whether an element's closing tag ends a markup run. Text after the closing tag belongs to
     * the sentence around it ("closed by </p>."), not to the markup."
     */
    private static function closesLikeMarkup(string $source, string $name): bool
    {
        if (preg_match_all('/<\/' . preg_quote($name, '/') . '\s*>/i', $source, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($matches[0] as [$text, $index]) {
            $rest = substr($source, $index + strlen($text));
            $nextMarkup = strpos($rest, '<');
            $tail = $nextMarkup === false ? $rest : substr($rest, 0, $nextMarkup);

            if (Text::blank($tail)) {
                return true;
            }

            // "Prose can name an opening tag and explain how it is closed. Trailing text alone does
            // not disqualify an otherwise ordinary HTML fragment."
            $explanation = preg_match('/\b(?:closed|closing|ended)\s+(?:by|with)\s*$/Di', substr($source, 0, $index)) === 1;

            if (!($explanation && preg_match('/^[.!?\s]*$/D', $tail) === 1)) {
                return true;
            }
        }

        return false;
    }

    /** "Require document markers or element structure, not a prose mention of a tag." */
    private static function hasHtmlStructure(string $source): bool
    {
        if (!str_starts_with(ltrim($source), '<')) {
            return false;
        }

        if (preg_match('/<!doctype\b[^<>]*>|<\/?(?:html|head|body)\b/i', $source) === 1) {
            return true;
        }

        if (preg_match('/^\s*<([a-z][\w:-]*)\b/i', $source, $first) === 1 && !str_contains(self::HTML_ELEMENTS, ' ' . strtolower($first[1]) . ' ')) {
            return false;
        }

        preg_match_all('/<\/?([a-z][a-z0-9:-]*)\b[^>]*>/i', $source, $tags);

        foreach (array_unique(array_map(strtolower(...), $tags[1])) as $name) {
            if (in_array($name, self::HTML_VOID_TAGS, true)) {
                return true;
            }

            if (preg_match('/<' . preg_quote($name, '/') . '\b[^>]*>/i', $source) === 1 && self::closesLikeMarkup($source, $name)) {
                return true;
            }
        }

        return false;
    }

    /** "Leave plain text, JSON, and already-Markdown responses alone." */
    public static function looksLikeHtml(string $text, ?string $contentType = null): bool
    {
        $kind = Media::kind($contentType);

        if ($kind === 'xml' || preg_match('/^\s*(?:<\?xml\b|<svg\b|<urlset\b|<sitemapindex\b|<feed\b|<rss\b)/i', $text) === 1) {
            return false;
        }

        if ($kind === 'html') {
            return true;
        }

        // "An explicit document wrapper outweighs an incorrect text/plain service label."
        if (preg_match('/^\s*(?:<!doctype\s+html\b|<html\b)/i', $text) === 1) {
            return true;
        }

        if ($kind === 'text' || $kind === 'json' || $kind === 'pdf') {
            return false;
        }

        // "A leading bracket alone does not establish JSON."
        if (preg_match('/^\s*[{"\[]/', $text) === 1 && json_validate($text)) {
            return false;
        }

        $fence = null;
        $prose = [];

        foreach (explode("\n", Text::cap($text, 4000)) as $line) {
            $previous = $fence;
            $fence = Fences::advance($fence, $line);

            if ($previous === null && $fence === null && !Fences::isLine($line)) {
                $prose[] = $line;
            }
        }

        $source = (string) preg_replace('/(?<!`)(`+)(?!`)[\s\S]*?(?<!`)\1(?!`)/', '', implode("\n", $prose));

        // "Markdown can include HTML decorations without becoming an HTML document."
        if (preg_match('/^ {0,3}#{1,6}\s/m', $source) === 1 && preg_match('/<!doctype\b|<(?:html|head|body)\b/i', $source) !== 1) {
            $body = Document::body(Document::parse($source));

            // libxml puts loose text into a paragraph where a browser leaves it in the body, so the
            // text looked at is the body's own and that of the paragraphs directly inside it.
            foreach ($body === null ? [] : iterator_to_array($body->childNodes) as $node) {
                foreach ($node instanceof DOMText ? [$node] : (Turndown::name($node) === 'p' ? iterator_to_array($node->childNodes) : []) as $piece) {
                    if ($piece instanceof DOMText && preg_match('/^ {0,3}#{1,6}\s/m', $piece->data) === 1) {
                        return false;
                    }
                }
            }
        }

        return self::hasHtmlStructure($source);
    }

    /** "Find a tag's closing angle bracket without mistaking a quoted attribute for markup." */
    private static function findMarkupTagEnd(string $source, int $start): int
    {
        $quote = '';
        $afterEquals = false;

        for ($index = $start + 1, $length = strlen($source); $index < $length; $index++) {
            $character = $source[$index];

            if ($quote !== '') {
                if ($character === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($character === '>') {
                return $index;
            }

            if (($character === "'" || $character === '"') && $afterEquals) {
                $quote = $character;
                $afterEquals = false;
            } elseif ($character === '=') {
                $afterEquals = true;
            } elseif (!ctype_space($character)) {
                $afterEquals = false;
            }
        }

        return -1;
    }

    /**
     * "A parsed DOM repairs truncated markup, so completeness must be checked against the original
     * source. Missing document-end tags alone are ambiguous because HTML permits omitting them; a
     * clean end at an element boundary is likewise ambiguous. Unfinished tokens and unclosed
     * article/main regions in full documents are positive signs of truncation; sentence
     * punctuation alone is not."
     *
     * @return 'complete'|'incomplete'|'unknown'
     */
    public static function classifyCopyCompleteness(string $html): string
    {
        if (Text::blank($html)) {
            return 'complete';
        }

        $index = 0;
        $length = strlen($html);
        $documentRootSeen = false;
        $rootClosedAtEnd = false;
        $templateDepth = 0;
        $contentDepth = 0;

        while ($index < $length) {
            if (substr_compare($html, '<!--', $index, 4) === 0) {
                $commentEnd = strpos($html, '-->', $index + 4);

                if ($commentEnd === false) {
                    return 'incomplete';
                }

                $index = $commentEnd + 3;

                continue;
            }

            if ($html[$index] !== '<') {
                // Jump to the next tag; anything but whitespace on the way un-closes the root.
                $next = strpos($html, '<', $index);
                $next = $next === false ? $length : $next;

                if ($rootClosedAtEnd && !Text::blank(substr($html, $index, $next - $index))) {
                    $rootClosedAtEnd = false;
                }

                $index = $next;

                continue;
            }

            if ($index + 1 >= $length) {
                return 'incomplete';
            }

            $next = $html[$index + 1];

            if (!ctype_alpha($next) && $next !== '!' && $next !== '?' && $next !== '/') {
                $rootClosedAtEnd = false;
                $index++;

                continue;
            }

            $tagEnd = self::findMarkupTagEnd($html, $index);

            if ($tagEnd < 0) {
                return 'incomplete';
            }

            $tag = substr($html, $index, $tagEnd - $index + 1);
            $name = preg_match('/^<\s*\/?\s*([a-z][a-z0-9:-]*)\b/i', $tag, $found) === 1 ? strtolower($found[1]) : null;
            $closing = preg_match('/^<\s*\//', $tag) === 1;

            if ($name === 'html' || $name === 'body' || preg_match('/^<!doctype\b/i', $tag) === 1) {
                $documentRootSeen = true;
            }

            if ($closing && ($name === 'html' || $name === 'body') && $documentRootSeen && $templateDepth === 0) {
                $rootClosedAtEnd = true;
                $index = $tagEnd + 1;

                continue;
            }

            $rootClosedAtEnd = false;

            if ($templateDepth === 0 && ($name === 'article' || $name === 'main')) {
                $contentDepth = max(0, $contentDepth + ($closing ? -1 : 1));
            }

            if (!$closing && $name === 'template' && preg_match('/\/\s*>$/D', $tag) !== 1) {
                $templateDepth++;
            }

            if ($closing && $name === 'template' && $templateDepth > 0) {
                $templateDepth--;
            }

            if (!$closing && $name !== null && preg_match('/^(?:script|style|textarea|title|noscript|xmp|iframe|noembed|noframes|plaintext)$/D', $name) === 1) {
                if ($name === 'plaintext') {
                    return 'unknown';
                }

                if (preg_match('/<\/\s*' . $name . '\s*>/i', $html, $close, PREG_OFFSET_CAPTURE, $tagEnd + 1) !== 1) {
                    return 'incomplete';
                }

                $index = $close[0][1] + strlen($close[0][0]);

                continue;
            }

            $index = $tagEnd + 1;
        }

        if ($rootClosedAtEnd && $contentDepth === 0) {
            return 'complete';
        }

        return $documentRootSeen && $contentDepth > 0 ? 'incomplete' : 'unknown';
    }

    /** "Where relative links point: the page's `<base href>` when it has a usable one." */
    private static function documentBase(DOMDocument $document, ?string $baseUrl): ?string
    {
        $element = Document::first($document, '//base[@href]');
        $href = $element === null ? '' : (string) Document::attribute($element, 'href');

        if ($href === '') {
            return $baseUrl;
        }

        $base = Url::parse($href, $baseUrl);

        return $base !== null && $base->isWeb() ? $base->href() : $baseUrl;
    }

    /**
     * "Whether a class-marked element is the page itself. Names such as `has-sidebar`,
     * `menu-open`, or `content-sidebar-wrap` describe state or layout on wrappers that hold the
     * article; only a small, named block is chrome."
     */
    private static function holdsPageText(DOMElement $node, int $bodyChars): bool
    {
        if (Document::first($node, './/h1 | ' . self::REGIONS) !== null) {
            return true;
        }

        $chars = Text::length($node->textContent);

        return $chars >= self::CONTENT_MIN_CHARS && $chars >= $bodyChars * self::CONTENT_SHARE;
    }

    private static function resolveTarget(string $target, ?string $baseUrl): string
    {
        return Url::parse($target, $baseUrl)?->href() ?? $target;
    }

    /** "Whether the effective inline style hides its element." */
    private static function isHiddenStyle(string $style): bool
    {
        if ($style === '') {
            return false;
        }

        $winners = [];

        // "A comment inside the attribute must not hide the declaration behind it."
        foreach (explode(';', (string) preg_replace('/\/\*[\s\S]*?\*\//', '', $style)) as $declaration) {
            $separator = strpos($declaration, ':');

            if ($separator === false) {
                continue;
            }

            $property = strtolower(Text::trim(substr($declaration, 0, $separator)));

            if ($property !== 'display' && $property !== 'visibility') {
                continue;
            }

            $raw = substr($declaration, $separator + 1);
            $important = preg_match('/!\s*important\s*$/Di', $raw) === 1;
            $value = strtolower(Text::trim((string) preg_replace('/\s*!\s*important\s*$/Di', '', $raw)));
            $current = $winners[$property] ?? null;

            if ($current === null || $important || !$current['important']) {
                $winners[$property] = ['value' => $value, 'important' => $important];
            }
        }

        $visibility = $winners['visibility']['value'] ?? null;

        return ($winners['display']['value'] ?? null) === 'none' || $visibility === 'hidden' || $visibility === 'collapse';
    }

    /** "Remove only a dangling tag fragment at the end of a partial page." */
    private static function removeDanglingTailText(DOMNode $node): bool
    {
        foreach (array_reverse(iterator_to_array($node->childNodes)) as $child) {
            if ($child instanceof DOMText) {
                if (Text::blank($child->data)) {
                    Document::remove($child);

                    continue;
                }

                if (preg_match('/^\s*(?:<\s*|<\/?[a-z][a-z0-9:-]*(?:\s[^<>]*)?\s*)$/Di', $child->data) === 1) {
                    Document::remove($child);

                    return true;
                }

                return false;
            }

            if ($child->nodeType === XML_COMMENT_NODE) {
                continue;
            }

            if ($child instanceof DOMElement) {
                if (self::removeDanglingTailText($child)) {
                    return true;
                }

                if (Text::blank($child->textContent)) {
                    continue;
                }
            }

            return false;
        }

        return false;
    }

    /** @return list<DOMElement> `row.cells` */
    private static function cells(DOMElement $row): array
    {
        return array_values(array_filter(Document::children($row), static fn (DOMElement $cell): bool => in_array(Turndown::name($cell), ['td', 'th'], true)));
    }

    private static function hasContent(DOMElement $element): bool
    {
        return !Text::blank($element->textContent) || $element->getElementsByTagName('img')->length > 0;
    }

    private static function rowHasContent(DOMElement $row): bool
    {
        foreach (self::cells($row) as $cell) {
            if (self::hasContent($cell)) {
                return true;
            }
        }

        return false;
    }

    private static function hasTableAncestor(DOMElement $table): bool
    {
        for ($ancestor = $table->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            if (Turndown::name($ancestor) === 'table') {
                return true;
            }
        }

        return false;
    }

    /** "Flatten unsupported grids without discarding links, images, or inline code." */
    private static function flattenTable(DOMElement $table, DOMDocument $document): void
    {
        $flattened = $document->createElement('span');
        $caption = Document::first($table, './/caption');

        if ($caption !== null) {
            foreach (iterator_to_array($caption->childNodes) as $child) {
                $flattened->appendChild($child->cloneNode(true));
            }

            $flattened->appendChild($document->createTextNode(' — '));
        }

        foreach (array_values(array_filter(Turndown::rows($table), self::rowHasContent(...))) as $rowIndex => $row) {
            $flattened->appendChild($document->createTextNode($rowIndex > 0 ? '; ' : ' '));

            foreach (array_values(array_filter(self::cells($row), self::hasContent(...))) as $cellIndex => $cell) {
                if ($cellIndex > 0) {
                    $flattened->appendChild($document->createTextNode(' — '));
                }

                foreach (iterator_to_array($cell->childNodes) as $child) {
                    $flattened->appendChild($child->cloneNode(true));
                }
            }
        }

        $flattened->appendChild($document->createTextNode(' '));
        $table->parentNode?->replaceChild($flattened, $table);
    }

    /** The `colspan` or `rowspan` a cell states, as the DOM reads it. */
    private static function span(DOMElement $cell, string $attribute, int $default, int $min, int $max): int
    {
        $value = Text::trim($cell->getAttribute($attribute));

        if (preg_match('/^\+?(\d+)/', $value, $digits) !== 1) {
            return $default;
        }

        $number = strlen($digits[1]) > 9 ? $max : (int) $digits[1];

        return $number < $min ? $default : min($number, $max);
    }

    /**
     * A rectangular grid of the table's rows: every row as wide as the widest, spans written out
     * as empty cells. Null when the grid would be larger than $maxGridCells.
     *
     * @return list<DOMElement>|null
     */
    private static function normalizeTableRows(DOMElement $table, DOMDocument $document, int $maxGridCells): ?array
    {
        $rows = Turndown::rows($table);
        $groupEnds = [];

        foreach ($rows as $index => $row) {
            $groupEnds[(string) ($row->parentNode ?? $row)->getNodePath()] = $index;
        }

        $occupiedUntil = [];
        $placements = [];
        $width = 0;

        foreach ($rows as $rowIndex => $row) {
            $column = 0;
            $placements[$rowIndex] = [];

            foreach (self::cells($row) as $cell) {
                $span = self::span($cell, 'colspan', 1, 1, 1000);

                while (true) {
                    if (($column + $span) * count($rows) > $maxGridCells) {
                        return null;
                    }

                    $blocked = null;

                    for ($offset = 0; $offset < $span; $offset++) {
                        if (($occupiedUntil[$column + $offset] ?? -1) >= $rowIndex) {
                            $blocked = $column + $offset;

                            break;
                        }
                    }

                    if ($blocked === null) {
                        break;
                    }

                    $column = $blocked + 1;
                }

                $placements[$rowIndex][$column] = [$cell, $span];
                $remainingRows = $groupEnds[(string) ($row->parentNode ?? $row)->getNodePath()] - $rowIndex + 1;
                $stated = self::span($cell, 'rowspan', 1, 0, 65534);
                $rowSpan = min($remainingRows, $stated === 0 ? $remainingRows : max(1, $stated));

                for ($offset = 0; $offset < $span; $offset++) {
                    $occupiedUntil[$column + $offset] = $rowIndex + $rowSpan - 1;
                }

                $column += $span;
            }

            $width = max($width, $column);
        }

        foreach ($rows as $rowIndex => $row) {
            $cells = [];

            for ($column = 0; $column < $width;) {
                $item = $placements[$rowIndex][$column] ?? null;

                if ($item === null) {
                    $cells[] = $document->createElement('td');
                    $column++;

                    continue;
                }

                [$cell, $span] = $item;
                $cell->removeAttribute('colspan');
                $cell->removeAttribute('rowspan');
                $cells[] = $cell;

                for ($offset = 1; $offset < $span; $offset++) {
                    $cells[] = $document->createElement(Turndown::name($cell));
                }

                $column += $span;
            }

            foreach (iterator_to_array($row->childNodes) as $child) {
                $row->removeChild($child);
            }

            foreach ($cells as $cell) {
                $row->appendChild($cell);
            }
        }

        return $rows;
    }

    /** "Read preformatted text while retaining the line breaks represented by <br>." */
    private static function preformattedText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->data;
        }

        if (Turndown::name($node) === 'br') {
            return "\n";
        }

        return implode('', array_map(self::preformattedText(...), iterator_to_array($node->childNodes)));
    }

    /** "HN's front page uses layout tables, not tabular article data." */
    private static function hackerNewsListing(DOMDocument $document, ?string $baseUrl): ?DOMElement
    {
        $url = $baseUrl === null ? null : Url::parse($baseUrl);

        if ($url === null || $url->hostname() !== 'news.ycombinator.com' || !in_array($url->path, ['/', '/news'], true)) {
            return null;
        }

        $titleLine = './/*[' . Document::hasClass('titleline') . ']';
        $stories = array_values(array_filter(Document::select($document, '//tr[' . Document::hasClass('athing') . ']'), static fn (DOMElement $row): bool => Document::first($row, $titleLine) !== null));

        if ($stories === []) {
            return null;
        }

        $main = $document->createElement('main');
        $heading = $document->createElement('h1');
        $title = Document::first($document, '//title');
        Document::setText($heading, $title === null ? '' : Text::trim((string) preg_replace('/[ \t\n\f\r]+/', ' ', $title->textContent)));
        $main->appendChild($heading);
        $list = $document->createElement('ol');

        foreach ($stories as $row) {
            $item = $document->createElement('li');
            $line = Document::first($row, $titleLine);
            \assert($line !== null);
            $item->appendChild($line->cloneNode(true));
            $next = $row->nextSibling;

            while ($next !== null && !$next instanceof DOMElement) {
                $next = $next->nextSibling;
            }

            $metadata = $next === null ? null : Document::first($next, './/*[' . Document::hasClass('subtext') . ']');

            if ($metadata !== null) {
                $item->appendChild($document->createTextNode(' — '));

                foreach (iterator_to_array($metadata->childNodes) as $child) {
                    $item->appendChild($child->cloneNode(true));
                }
            }

            $list->appendChild($item);
        }

        $main->appendChild($list);
        $more = Document::first($document, '//a[' . Document::hasClass('morelink') . ']');

        if ($more !== null) {
            $main->appendChild($more->cloneNode(true));
        }

        return $main;
    }

    /**
     * "Convert the page's content; the DOM parser also repairs incomplete crawler copies."
     *
     * @param DOMDocument|null $document "an owned DOM supplied by inspection, before destructive
     *        cleanup"; parsed from $html when null
     */
    public static function htmlToMarkdown(string $html, ?string $baseUrl = null, ?DOMDocument $document = null): string
    {
        if ($html === '') {
            return '';
        }

        $document ??= Document::parse($html);
        $body = Document::body($document);

        if ($body === null) {
            return '';
        }

        $linkBase = self::documentBase($document, $baseUrl);

        foreach (Document::select($document, '//' . implode(' | //', self::DROPPED_ELEMENTS)) as $node) {
            Document::remove($node);
        }

        foreach (Document::select($document, '//*[@hidden or @aria-hidden or @style]') as $node) {
            if ($node->hasAttribute('hidden') || strtolower(Text::trim($node->getAttribute('aria-hidden'))) === 'true' || self::isHiddenStyle($node->getAttribute('style'))) {
                Document::remove($node);
            }
        }

        $chrome = './/nav | .//aside | .//footer';

        foreach (['social-icons', 'site-footer', 'page-footer', 'site-header', 'site-nav'] as $class) {
            $chrome .= ' | .//*[' . Document::hasClass($class) . ']';
        }

        foreach (Document::select($body, $chrome . ' | .//*[@role="navigation" or @role="banner" or @role="contentinfo" or @role="search" or @aria-hidden="true" or @hidden]') as $node) {
            Document::remove($node);
        }

        $bodyChars = Text::length($body->textContent);

        foreach (Document::select($body, './/div | .//section | .//ul | .//ol | .//header | .//footer | .//aside | .//nav') as $node) {
            $isChrome = false;

            foreach (preg_split('/\s+/', strtolower($node->getAttribute('id') . ' ' . $node->getAttribute('class'))) ?: [] as $name) {
                // A name with `:`, `[`, `]` or `/` in it is a utility class, which describes layout.
                if ($name === '' || preg_match('/[:\[\]\/]/', $name) === 1) {
                    continue;
                }

                foreach (preg_split('/[_-]+/', $name) ?: [] as $piece) {
                    if (preg_match(self::CHROME_NAME, $piece) === 1) {
                        $isChrome = true;

                        break 2;
                    }
                }
            }

            // A node inside one already taken out has no page to be part of any more.
            if ($isChrome && self::attached($node, $document) && !self::holdsPageText($node, $bodyChars)) {
                Document::remove($node);
            }
        }

        // "Empty/hidden placeholders are not content roots. Prefer the substantive region, not
        // whichever <main> happens to occur first."
        $regions = Document::select($document, self::REGIONS);
        usort($regions, static fn (DOMElement $a, DOMElement $b): int => Text::length(Text::trim($b->textContent)) <=> Text::length(Text::trim($a->textContent)));
        $root = self::hackerNewsListing($document, $baseUrl);

        foreach ($root === null ? $regions : [] as $region) {
            if (!Text::blank($region->textContent)) {
                $root = $region;

                break;
            }
        }

        $root ??= $body;

        if (self::classifyCopyCompleteness($html) === 'incomplete') {
            self::removeDanglingTailText($root);
        }

        foreach (Document::select($root, './/a') as $link) {
            $link->removeAttribute('title');
            $href = Document::attribute($link, 'href');
            $textLabel = Text::trim($link->textContent);
            $ariaLabel = Text::trim($link->getAttribute('aria-label'));

            if ($textLabel === '' && $ariaLabel !== '') {
                Document::setText($link, $ariaLabel);
            }

            $label = $textLabel !== '' ? $textLabel : $ariaLabel;

            if ($label === '' && $link->getElementsByTagName('img')->length === 0) {
                Document::remove($link);

                continue;
            }

            $fragment = $href !== null && str_starts_with($href, '#');

            if ($fragment && (preg_match('/[\p{L}\p{N}]/u', $label) !== 1 || preg_match('/^skip\b/i', $label) === 1)) {
                Document::remove($link);
            } elseif ($fragment || preg_match('/^(?:javascript|vbscript|data):/i', (string) preg_replace('/\s/', '', $href ?? '')) === 1) {
                $link->removeAttribute('href');
            } elseif ($href !== null && $href !== '') {
                $resolved = self::resolveTarget($href, $linkBase);
                $link->setAttribute('href', $resolved);
                $only = $link->childNodes->length === 1 ? $link->firstChild : null;

                if ($only instanceof DOMElement && Turndown::name($only) === 'img') {
                    $link->removeAttribute('href');
                } elseif ($label === $href || $label === $resolved) {
                    $link->parentNode?->replaceChild($document->createTextNode($resolved), $link);
                }
            }
        }

        foreach (Document::select($root, './/img') as $image) {
            $image->removeAttribute('title');
            $src = Document::attribute($image, 'src');

            if ($src === null || $src === '' || preg_match('/^(?:data|javascript|vbscript):/i', (string) preg_replace('/\s/', '', $src)) === 1) {
                Document::remove($image);
            } else {
                $image->setAttribute('src', self::resolveTarget($src, $linkBase));
            }
        }

        foreach (Document::select($root, './/a | .//li') as $node) {
            if (!self::hasContent($node)) {
                Document::remove($node);
            }
        }

        // "Turndown's fenced-code rule expects <pre><code>...</code></pre>."
        foreach (Document::select($root, './/pre') as $pre) {
            $text = self::preformattedText($pre);

            if ($pre->childNodes->length === 1 && $pre->firstChild !== null && Turndown::name($pre->firstChild) === 'code') {
                Document::setText($pre->firstChild, $text);

                continue;
            }

            $code = $document->createElement('code');
            Document::setText($code, $text);
            Document::setText($pre, '');
            $pre->appendChild($code);
        }

        foreach (array_reverse(Document::select($root, './/table')) as $table) {
            if (self::hasTableAncestor($table)) {
                self::flattenTable($table, $document);
            }
        }

        // "Give the GFM plugin a rectangular table with an explicit header section."
        $remainingTableCells = self::MAX_TABLE_GRID_CELLS;

        foreach (Document::select($root, './/table') as $table) {
            $normalized = self::normalizeTableRows($table, $document, $remainingTableCells);

            if ($normalized === null) {
                self::flattenTable($table, $document);

                continue;
            }

            $remainingTableCells -= count($normalized) * count($normalized === [] ? [] : self::cells($normalized[0]));
            $rows = array_values(array_filter($normalized, self::rowHasContent(...)));

            if ($rows === [] || !self::hasContent($table)) {
                Document::remove($table);

                continue;
            }

            $width = count(self::cells($rows[0]));
            $allHeadings = array_filter(self::cells($rows[0]), static fn (DOMElement $cell): bool => Turndown::name($cell) !== 'th') === [];
            $header = $allHeadings ? array_shift($rows) : $document->createElement('tr');

            for ($column = count(self::cells($header)); $column < $width; $column++) {
                $header->appendChild($document->createElement('th'));
            }

            $head = $document->createElement('thead');
            $tableBody = $document->createElement('tbody');
            $head->appendChild($header);

            foreach ($rows as $row) {
                $tableBody->appendChild($row);
            }

            $sourceCaption = Document::first($table, './/caption');

            if ($sourceCaption !== null) {
                $caption = $document->createElement('p');

                foreach (iterator_to_array($sourceCaption->childNodes) as $child) {
                    $caption->appendChild($child->cloneNode(true));
                }

                $table->parentNode?->insertBefore($caption, $table);
            }

            Document::setText($table, '');
            $table->appendChild($head);
            $table->appendChild($tableBody);
        }

        return self::convertBlocks($root, $document);
    }

    /** Whether $node is still in the document's tree. */
    private static function attached(DOMNode $node, DOMDocument $document): bool
    {
        for ($ancestor = $node->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            if ($ancestor->isSameNode($document)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Turndown joins a growing string at every sibling, repeatedly scanning the entire prefix for
     * trailing newlines. Convert independent blocks separately; keep inline, list and table
     * contexts together so their Markdown semantics remain intact."
     */
    private static function convertBlocks(DOMElement $root, DOMDocument $document): string
    {
        $children = iterator_to_array($root->childNodes);
        $independent = preg_match('/^(body|div|main|article|section|header|footer|aside|nav)$/D', Turndown::name($root)) === 1;

        foreach ($independent ? $children : [] as $node) {
            $independent = $node->nodeType === XML_COMMENT_NODE
                || ($node instanceof DOMText && Text::blank($node->data))
                || ($node instanceof DOMElement && preg_match('/^(p|h[1-6]|ul|ol|pre|table|blockquote|div|section|main|article|hr|dl|figure|header|footer|aside|nav)$/D', Turndown::name($node)) === 1);

            if (!$independent) {
                break;
            }
        }

        if ($independent) {
            $blocks = [];

            foreach ($children as $node) {
                $block = $node instanceof DOMElement ? self::convertBlocks($node, $document) : '';

                if ($block !== '') {
                    $blocks[] = $block;
                }
            }

            return implode("\n\n", $blocks);
        }

        // "turndown(element) converts its children, not the element itself."
        $wrapper = $document->createElement('div');
        $wrapper->appendChild($root->cloneNode(true));

        return Text::trim(Turndown::convert($wrapper));
    }
}
