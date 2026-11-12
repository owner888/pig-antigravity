<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use DOMElement;
use DOMNode;
use DOMText;

/**
 * HTML to Markdown: a port of Turndown 7.2 (MIT, Dom Christie) with the table rules of
 * turndown-plugin-gfm 1.0 (MIT) and the three rules pi-antigravity's `web-fetch/markdown.ts` adds —
 * with that file's options built in (ATX headings, `-` bullets, fenced code, `*` emphasis, `---`
 * rules, inline links), since nothing here asks for any other.
 *
 * Upstream uses the two packages; this package takes no dependencies, so the algorithm is here:
 * whitespace is collapsed the way a browser renders it (Turndown's `collapseWhitespace`, adapted
 * from collapse-whitespace, MIT, Luc Thevenard), every node is replaced by the first rule that
 * claims it, and replacements are joined with at most one blank line between them.
 *
 * The rules are asked in Turndown's order — an added rule before the ones added earlier, those
 * before the built-in ones — which is `rule()`'s order, top to bottom.
 */
final class Turndown
{
    private const array BLOCK = [
        'address', 'article', 'aside', 'audio', 'blockquote', 'body', 'canvas', 'center', 'dd', 'dir', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hgroup', 'hr', 'html', 'isindex', 'li', 'main', 'menu', 'nav', 'noframes', 'noscript', 'ol', 'output', 'p', 'pre',
        'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    private const array VOID = ['area', 'base', 'br', 'col', 'command', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const array MEANINGFUL_WHEN_BLANK = ['a', 'table', 'thead', 'tbody', 'tfoot', 'th', 'td', 'iframe', 'script', 'audio', 'video'];

    /** `turndown(element)`: the Markdown of the element's children. */
    public static function convert(DOMElement $element): string
    {
        $root = $element->cloneNode(true);
        self::collapseWhitespace($root);
        $output = self::process($root, false);

        return (string) preg_replace(['/^[\t\r\n]+/', '/[' . Text::SPACE . ']+$/u'], '', $output);
    }

    // ---- collapse-whitespace -----------------------------------------------------------------

    private static function collapseWhitespace(DOMNode $element): void
    {
        if ($element->firstChild === null || self::name($element) === 'pre') {
            return;
        }

        $prevText = null;
        $keepLeadingWs = false;
        $prev = null;
        $node = self::next($prev, $element);

        while ($node !== null && !$node->isSameNode($element)) {
            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                \assert($node instanceof \DOMCharacterData);
                $text = (string) preg_replace('/[ \r\n\t]+/', ' ', $node->data);

                if (($prevText === null || str_ends_with($prevText->data, ' ')) && !$keepLeadingWs && ($text[0] ?? '') === ' ') {
                    $text = substr($text, 1);
                }

                if ($text === '') {
                    $node = self::removeAndNext($node);

                    continue;
                }

                $node->data = $text;
                $prevText = $node;
            } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                $name = self::name($node);

                if (in_array($name, self::BLOCK, true) || $name === 'br') {
                    if ($prevText !== null) {
                        $prevText->data = (string) preg_replace('/ $/D', '', $prevText->data);
                    }

                    $prevText = null;
                    $keepLeadingWs = false;
                } elseif (in_array($name, self::VOID, true) || $name === 'pre') {
                    // "Avoid trimming space around non-block, non-BR void elements and inline PRE."
                    $prevText = null;
                    $keepLeadingWs = true;
                } elseif ($prevText !== null) {
                    $keepLeadingWs = false;
                }
            } else {
                $node = self::removeAndNext($node);

                continue;
            }

            $nextNode = self::next($prev, $node);
            $prev = $node;
            $node = $nextNode;
        }

        if ($prevText !== null) {
            $prevText->data = (string) preg_replace('/ $/D', '', $prevText->data);

            if ($prevText->data === '') {
                Document::remove($prevText);
            }
        }
    }

    private static function removeAndNext(DOMNode $node): ?DOMNode
    {
        $next = $node->nextSibling ?? $node->parentNode;
        Document::remove($node);

        return $next;
    }

    private static function next(?DOMNode $prev, DOMNode $current): ?DOMNode
    {
        if (($prev !== null && $prev->parentNode !== null && $prev->parentNode->isSameNode($current)) || self::name($current) === 'pre') {
            return $current->nextSibling ?? $current->parentNode;
        }

        return $current->firstChild ?? $current->nextSibling ?? $current->parentNode;
    }

    // ---- the reduction -----------------------------------------------------------------------

    private static function process(DOMNode $parent, bool $inCode): string
    {
        $output = '';

        foreach (iterator_to_array($parent->childNodes) as $node) {
            $replacement = '';

            if ($node instanceof DOMText) {
                $replacement = $inCode ? $node->data : self::escape($node->data);
            } elseif ($node instanceof DOMElement) {
                $replacement = self::replacementFor($node, $inCode || self::name($node) === 'code');
            }

            $output = self::join($output, $replacement);
        }

        return $output;
    }

    private static function replacementFor(DOMElement $node, bool $isCode): string
    {
        $content = self::process($node, $isCode);
        $isBlock = in_array(self::name($node), self::BLOCK, true);
        [$leading, $trailing] = $isBlock ? ['', ''] : self::flankingWhitespace($node);

        if ($leading !== '' || $trailing !== '') {
            $content = Text::trim($content);
        }

        return $leading . self::rule($node, $content, $isBlock) . $trailing;
    }

    /** "Joins replacement to the current output with appropriate number of new lines." */
    private static function join(string $output, string $replacement): string
    {
        $s1 = rtrim($output, "\n");
        $s2 = ltrim($replacement, "\n");
        $newlines = max(strlen($output) - strlen($s1), strlen($replacement) - strlen($s2));

        return $s1 . substr("\n\n", 0, $newlines) . $s2;
    }

    /** @return array{0: string, 1: string} leading and trailing */
    private static function flankingWhitespace(DOMElement $node): array
    {
        $ws = Text::SPACE;

        if (preg_match('/^(([ \t\r\n]*)([' . $ws . ']*))(?:(?=[^' . $ws . '])[\s\S]*[^' . $ws . '])?(([' . $ws . ']*?)([ \t\r\n]*))$/Du', $node->textContent, $m) !== 1) {
            return ['', ''];
        }

        [, $leading, $leadingAscii, $leadingNonAscii, $trailing, $trailingNonAscii, $trailingAscii] = $m;

        if ($leadingAscii !== '' && self::isFlankedByWhitespace($node->previousSibling, '/ $/D')) {
            $leading = $leadingNonAscii;
        }

        if ($trailingAscii !== '' && self::isFlankedByWhitespace($node->nextSibling, '/^ /')) {
            $trailing = $trailingNonAscii;
        }

        return [$leading, $trailing];
    }

    private static function isFlankedByWhitespace(?DOMNode $sibling, string $pattern): bool
    {
        if ($sibling instanceof DOMText) {
            return preg_match($pattern, $sibling->data) === 1;
        }

        return $sibling instanceof DOMElement && !in_array(self::name($sibling), self::BLOCK, true) && preg_match($pattern, $sibling->textContent) === 1;
    }

    private static function isBlank(DOMElement $node): bool
    {
        $name = self::name($node);

        if (in_array($name, self::VOID, true) || in_array($name, self::MEANINGFUL_WHEN_BLANK, true) || !Text::blank($node->textContent)) {
            return false;
        }

        foreach ([...self::VOID, ...self::MEANINGFUL_WHEN_BLANK] as $tag) {
            if ($node->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return true;
    }

    // ---- the rules ---------------------------------------------------------------------------

    private static function rule(DOMElement $node, string $content, bool $isBlock): string
    {
        if (self::isBlank($node)) {
            return $isBlock ? "\n\n" : '';
        }

        $name = self::name($node);
        $parent = $node->parentNode;
        $parentName = $parent === null ? '' : self::name($parent);

        switch (true) {
            // pi-antigravity's `tableCell`: "GFM cells cannot contain block-level Markdown or unescaped pipes."
            case $name === 'td' || $name === 'th':
                $cell = str_replace(['\\', '|'], ['\\\\', '\\|'], (string) preg_replace('/[' . Text::SPACE . ']*\n[' . Text::SPACE . ']*/u', ' ', Text::trim($content)));

                return (self::previousElement($node) !== null ? ' ' : '| ') . $cell . ' |';

            // pi-antigravity's `strikethrough`.
            case $name === 'del' || $name === 's' || $name === 'strike':
                return "~~{$content}~~";

            // pi-antigravity's `inlineCodeText`: the code's own text, never an escaped rendering of it.
            case $name === 'code' && $parentName !== 'pre':
                return self::inlineCode((string) preg_replace('/\r?\n|\r/', ' ', $node->textContent));

            // turndown-plugin-gfm's `tableSection`, `table` and `tableRow`.
            case $name === 'thead' || $name === 'tbody' || $name === 'tfoot':
                return $content;

            case $name === 'table' && self::isHeadingRow(self::rows($node)[0] ?? null):
                // "Ensure there are no blank lines" — the first one, as `String.replace` with a string does.
                $at = strpos($content, "\n\n");

                return "\n\n" . ($at === false ? $content : substr_replace($content, "\n", $at, 2)) . "\n\n";

            case $name === 'tr':
                $border = '';

                if (self::isHeadingRow($node)) {
                    foreach (iterator_to_array($node->childNodes) as $index => $cell) {
                        $align = $cell instanceof DOMElement ? strtolower($cell->getAttribute('align')) : '';
                        $rule = ['left' => ':--', 'right' => '--:', 'center' => ':-:'][$align] ?? '---';
                        $border .= ($index === 0 ? '| ' : ' ') . $rule . ' |';
                    }
                }

                return "\n" . $content . ($border !== '' ? "\n" . $border : '');

            // Turndown's own, in its order.
            case $name === 'p':
                return "\n\n{$content}\n\n";

            case $name === 'br':
                return "  \n";

            case preg_match('/^h([1-6])$/', $name, $level) === 1:
                return "\n\n" . str_repeat('#', (int) $level[1]) . " {$content}\n\n";

            case $name === 'blockquote':
                return "\n\n" . preg_replace('/^/m', '> ', trim($content, "\n")) . "\n\n";

            case $name === 'ul' || $name === 'ol':
                $lastElement = $parent === null ? null : (Document::children($parent)[count(Document::children($parent)) - 1] ?? null);

                return $parentName === 'li' && $lastElement !== null && $lastElement->isSameNode($node) ? "\n{$content}" : "\n\n{$content}\n\n";

            case $name === 'li':
                $prefix = '-   ';

                if ($parentName === 'ol' && $parent instanceof DOMElement) {
                    $index = 0;

                    foreach (Document::children($parent) as $position => $sibling) {
                        if ($sibling->isSameNode($node)) {
                            $index = $position;
                        }
                    }

                    $start = $parent->getAttribute('start');
                    // `Number(start) + index`: a start that is not a number is NaN, and is written so.
                    $prefix = ($start !== '' ? (is_numeric(trim($start)) ? (string) ((int) trim($start) + $index) : 'NaN') : (string) ($index + 1)) . '.  ';
                }

                $isParagraph = str_ends_with($content, "\n");
                $content = trim($content, "\n") . ($isParagraph ? "\n" : '');
                $content = str_replace("\n", "\n" . str_repeat(' ', strlen($prefix)), $content);

                return $prefix . $content . ($node->nextSibling !== null ? "\n" : '');

            case $name === 'pre' && $node->firstChild instanceof DOMElement && self::name($node->firstChild) === 'code':
                $code = $node->firstChild->textContent;
                $language = preg_match('/language-(\S+)/', $node->firstChild->getAttribute('class'), $found) === 1 ? $found[1] : '';
                $size = 3;

                if (preg_match_all('/^`{3,}/m', $code, $fences) > 0) {
                    foreach ($fences[0] as $fence) {
                        $size = max($size, strlen($fence) + 1);
                    }
                }

                $fence = str_repeat('`', $size);

                return "\n\n{$fence}{$language}\n" . preg_replace('/\n$/D', '', $code) . "\n{$fence}\n\n";

            case $name === 'hr':
                return "\n\n---\n\n";

            case $name === 'a' && $node->getAttribute('href') !== '':
                $title = self::cleanAttribute($node->getAttribute('title'));

                return "[{$content}](" . self::escapeLinkDestination($node->getAttribute('href')) . ($title !== '' ? ' "' . str_replace('"', '\\"', $title) . '"' : '') . ')';

            case $name === 'em' || $name === 'i':
                return Text::blank($content) ? '' : "*{$content}*";

            case $name === 'strong' || $name === 'b':
                return Text::blank($content) ? '' : "**{$content}**";

            // Turndown's `code`, which only a `<code>` with siblings inside a `<pre>` reaches.
            case $name === 'code' && ($node->previousSibling !== null || $node->nextSibling !== null):
                return self::inlineCode((string) preg_replace('/\r?\n|\r/', ' ', $content));

            case $name === 'img':
                $src = self::escapeLinkDestination($node->getAttribute('src'));
                $title = self::cleanAttribute($node->getAttribute('title'));

                return $src === '' ? '' : '![' . self::escape(self::cleanAttribute($node->getAttribute('alt'))) . "]({$src}" . ($title !== '' ? ' "' . str_replace('"', '\\"', $title) . '"' : '') . ')';

            // turndown-plugin-gfm keeps a table with no heading row as the HTML it is.
            case $name === 'table' && self::rows($node) !== []:
                return "\n\n" . ($node->ownerDocument?->saveHTML($node) ?: '') . "\n\n";

            default:
                return $isBlock ? "\n\n{$content}\n\n" : $content;
        }
    }

    private static function inlineCode(string $content): string
    {
        if ($content === '') {
            return '';
        }

        preg_match_all('/`+/', $content, $runs);
        $delimiter = '`';

        while (in_array($delimiter, $runs[0], true)) {
            $delimiter .= '`';
        }

        $padding = preg_match('/^`|^ .*?[^ ].* $|`$/D', $content) === 1 ? ' ' : '';

        return $delimiter . $padding . $content . $padding . $delimiter;
    }

    /** turndown-plugin-gfm's `isHeadingRow()`. */
    private static function isHeadingRow(?DOMElement $row): bool
    {
        $parent = $row?->parentNode;

        if ($row === null || $parent === null) {
            return false;
        }

        $parentName = self::name($parent);

        if ($parentName === 'thead') {
            return true;
        }

        if ($parent->firstChild === null || !$parent->firstChild->isSameNode($row)) {
            return false;
        }

        $previous = $parent->previousSibling;
        $firstBody = $parentName === 'tbody' && ($previous === null || (self::name($previous) === 'thead' && Text::blank($previous->textContent)));

        if ($parentName !== 'table' && !$firstBody) {
            return false;
        }

        foreach ($row->childNodes as $cell) {
            if (self::name($cell) !== 'th') {
                return false;
            }
        }

        return true;
    }

    /**
     * `table.rows`: the head's rows, then the table's own and its bodies' in order, then the foot's.
     *
     * @return list<DOMElement>
     */
    public static function rows(DOMElement $table): array
    {
        $head = $body = $foot = [];

        foreach (Document::children($table) as $child) {
            $name = self::name($child);

            if ($name === 'tr') {
                $body[] = $child;

                continue;
            }

            foreach ($name === 'thead' || $name === 'tbody' || $name === 'tfoot' ? Document::children($child) : [] as $row) {
                if (self::name($row) === 'tr') {
                    match ($name) {
                        'thead' => $head[] = $row,
                        'tfoot' => $foot[] = $row,
                        default => $body[] = $row,
                    };
                }
            }
        }

        return [...$head, ...$body, ...$foot];
    }

    private static function previousElement(DOMNode $node): ?DOMElement
    {
        for ($sibling = $node->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
            if ($sibling instanceof DOMElement) {
                return $sibling;
            }
        }

        return null;
    }

    public static function name(DOMNode $node): string
    {
        return strtolower($node->nodeName);
    }

    private static function cleanAttribute(string $attribute): string
    {
        return (string) preg_replace('/(\n+\s*)+/', "\n", $attribute);
    }

    private static function escapeLinkDestination(string $destination): string
    {
        $escaped = (string) preg_replace('/([<>()])/', '\\\\$1', $destination);

        return str_contains($escaped, ' ') ? "<{$escaped}>" : $escaped;
    }

    /** Turndown's `escape()`: its list, in its order; `^` is the start of the text, not of a line. */
    public static function escape(string $text): string
    {
        return (string) preg_replace(
            ['/\\\\/', '/\*/', '/^-/', '/^\+ /', '/^(=+)/', '/^(#{1,6}) /', '/`/', '/^~~~/', '/\[/', '/\]/', '/^>/', '/_/', '/^(\d+)\. /'],
            ['\\\\\\\\', '\\\\*', '\\\\-', '\\\\+ ', '\\\\$1', '\\\\$1 ', '\\\\`', '\\\\~~~', '\\\\[', '\\\\]', '\\\\>', '\\\\_', '$1\\\\. '],
            $text,
        );
    }
}
