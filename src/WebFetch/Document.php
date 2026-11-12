<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * A page parsed, within limits — pi-antigravity's `web-fetch/document.ts` — and the few DOM
 * conveniences its callers lean on.
 *
 * "Work limits apply before the recursive DOM parser or converter sees a document": the source is
 * measured as text first (its length, its markup tokens, how deep its elements nest), and only a
 * page inside all three is handed to the parser.
 *
 * Upstream parses with Domino, an HTML5 parser; this is PHP's `dom` extension, whose `loadHTML()`
 * is libxml2's HTML 4 parser. It repairs broken and cut-off markup as a browser would in the cases
 * the reader meets, but it is not the same tree for every malformed page — the fixtures under
 * `tests/fixtures/web-fetch` are what holds the two to the same answers.
 */
final class Document
{
    public const int MAX_CHARS = 2_000_000;

    public const int MAX_NODES = 50_000;

    private const int MAX_DEPTH = 128;

    private const array VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const string RAW_TEXT = 'script|style|textarea|title|noscript|xmp|iframe|noembed|noframes|plaintext';

    /** Whether the `dom` extension, which all of this stands on, is loaded. */
    public static function available(): bool
    {
        return class_exists(DOMDocument::class);
    }

    /** What to tell somebody whose PHP has no `dom` extension. */
    public const string MISSING_EXTENSION = "Reading web pages needs PHP's dom extension, which this PHP does not have. Install it — `apt install php-xml` on Debian and Ubuntu, `dnf install php-xml` on Fedora, `apk add php-dom` on Alpine; Homebrew's and Termux's php include it — then restart pig.";

    /**
     * "Reject bounded-but-pathological input with an actionable page-level error", then parse.
     *
     * @throws DocumentLimitError
     */
    public static function parse(string $source): DOMDocument
    {
        if (!self::available()) {
            throw new RuntimeException(self::MISSING_EXTENSION);
        }

        if (Text::length($source) > self::MAX_CHARS) {
            throw new DocumentLimitError('The page is too large to convert safely. Open it in a browser or request a smaller page.');
        }

        self::measure($source);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            // The declaration is how libxml is told the bytes are UTF-8; it is taken out again below.
            $document->loadHTML('<?xml encoding="UTF-8">' . Text::repair($source), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);
            }
        }

        // Every caller reads `document.body`; a source with no markup at all has none yet.
        if ($document->documentElement === null) {
            $document->appendChild($document->createElement('html'));
        }

        if (self::body($document) === null) {
            $document->documentElement?->appendChild($document->createElement('body'));
        }

        return $document;
    }

    /**
     * The token walk of upstream's `parseDocument()`: comments, raw-text elements whole, and tags,
     * counted; open elements tracked with the optional end tags HTML allows, so a list of a
     * thousand unclosed `<li><p>` is not a thousand levels deep.
     */
    private static function measure(string $source): void
    {
        $stack = [];
        $nodes = 0;
        $length = strlen($source);
        $offset = 0;

        while (($open = strpos($source, '<', $offset)) !== false) {
            if (substr_compare($source, '<!--', $open, 4) === 0) {
                $end = strpos($source, '-->', $open + 4);
                $offset = $end === false ? $length : $end + 3;
                $tag = null;
            } elseif (preg_match('/\G<(' . self::RAW_TEXT . ')\b[^>]*>/i', $source, $match, 0, $open) === 1) {
                // Up to its own end tag, or the end of the source: nothing inside is markup.
                $after = $open + strlen($match[0]);
                $offset = preg_match('/<\/' . $match[1] . '\s*>/i', $source, $close, PREG_OFFSET_CAPTURE, $after) === 1
                    ? $close[0][1] + strlen($close[0][0])
                    : $length;
                $tag = null;
            } elseif (preg_match('/\G<(\/?)([a-z][\w:-]*)\b(?:[^"\'<>]++|"[^"]*+"|\'[^\']*+\')*+>/i', $source, $match, 0, $open) === 1) {
                $offset = $open + strlen($match[0]);
                $tag = strtolower($match[2]);
            } else {
                $offset = $open + 1;

                continue;
            }

            if (++$nodes > self::MAX_NODES) {
                throw new DocumentLimitError('The page has too many elements to convert safely. Open it in a browser.');
            }

            if ($tag === null || in_array($tag, self::VOID, true)) {
                continue;
            }

            if ($match[1] === '/') {
                $index = array_search($tag, array_reverse($stack, true), true);

                if ($index !== false) {
                    array_splice($stack, (int) $index);
                }

                continue;
            }

            // "Optional end tags close a sibling and its descendants, not merely the stack's top.
            // Respect list/table boundaries so nested containers remain nested."
            $closeSibling = static function (array $names, array $boundaries) use (&$stack): void {
                $last = static function (array $wanted) use (&$stack): int {
                    for ($index = count($stack) - 1; $index >= 0; $index--) {
                        if (in_array($stack[$index], $wanted, true)) {
                            return $index;
                        }
                    }

                    return -1;
                };
                $sibling = $last($names);

                if ($sibling > $last($boundaries)) {
                    array_splice($stack, $sibling);
                }
            };

            if (preg_match('/^(?:p|div|section|article|main|h[1-6]|ul|ol|table|blockquote)$/', $tag) === 1) {
                $closeSibling(['p'], []);
            }

            match ($tag) {
                'li' => $closeSibling(['li'], ['ul', 'ol']),
                'dt', 'dd' => $closeSibling(['dt', 'dd'], ['dl']),
                'tr' => $closeSibling(['tr'], ['table']),
                'td', 'th' => $closeSibling(['td', 'th'], ['tr']),
                'option' => $closeSibling(['option'], ['select', 'datalist']),
                default => null,
            };

            $stack[] = $tag;

            if (count($stack) > self::MAX_DEPTH) {
                throw new DocumentLimitError('The page is nested too deeply to convert safely. Open it in a browser.');
            }
        }
    }

    // ---- what a browser's DOM has and PHP's does not ---------------------------------------

    public static function body(DOMDocument $document): ?DOMElement
    {
        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : null;
    }

    /**
     * The elements an XPath expression selects, as a list that stays whole while they are removed.
     *
     * @return list<DOMElement>
     */
    public static function select(DOMNode $context, string $expression): array
    {
        $document = $context instanceof DOMDocument ? $context : $context->ownerDocument;

        if ($document === null) {
            return [];
        }

        $nodes = (new DOMXPath($document))->query($expression, $context);

        if ($nodes === false) {
            throw new RuntimeException("Not an XPath expression: {$expression}");
        }

        return array_values(array_filter(iterator_to_array($nodes), static fn (DOMNode $node): bool => $node instanceof DOMElement));
    }

    public static function first(DOMNode $context, string $expression): ?DOMElement
    {
        return self::select($context, $expression)[0] ?? null;
    }

    /** XPath for "has this class", which CSS writes `.name`. */
    public static function hasClass(string $name): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$name} ')";
    }

    public static function remove(DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }

    /** @return list<DOMElement> */
    public static function children(DOMNode $node): array
    {
        return array_values(array_filter(iterator_to_array($node->childNodes), static fn (DOMNode $child): bool => $child instanceof DOMElement));
    }

    /** `element.getAttribute(name)?.trim()`, or null when it is absent. */
    public static function attribute(DOMElement $element, string $name): ?string
    {
        return $element->hasAttribute($name) ? Text::trim($element->getAttribute($name)) : null;
    }

    /** Replace everything inside $node with one piece of text — `node.textContent = text`. */
    public static function setText(DOMNode $node, string $text): void
    {
        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }

        if ($text !== '' && $node->ownerDocument !== null) {
            $node->appendChild($node->ownerDocument->createTextNode($text));
        }
    }
}
