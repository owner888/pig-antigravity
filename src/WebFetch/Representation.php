<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use Closure;

/**
 * "A fuller copy of a page that its publisher serves at another URL" — pi-antigravity's
 * `web-fetch/representation.ts`, with its three kinds: one the page advertises, MDN's article
 * data (`mdn.ts`), and Wikipedia's and GitHub's (`sites.ts`).
 *
 * "Retrieval does not care which kind it has: it fetches `url`, passes the response to `read`, and
 * inspects the document that comes back. Each kind owns its identity checks, so a copy is used only
 * when it names the requested page, and never rebuilt from partial data."
 *
 * What `read` answers is a document — `{body, contentType?, articleUrl?, completeness?, notes?,
 * metadata?, language?}` — or null when the response is not the expected one.
 */
final class Representation
{
    /**
     * @param bool $resolve "Resolve Google's redirect for `url` first. Links a page advertises need
     *        it, so they cannot leave the page's origin; a URL built from the page's own address
     *        does not."
     * @param Closure(string $content, ?string $reportedType, string $articleUrl): ?array<string, mixed> $read
     */
    private function __construct(
        public readonly string $url,
        public readonly bool $resolve,
        private readonly Closure $read,
        public readonly ?string $language = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function read(string $content, ?string $reportedType, string $articleUrl): ?array
    {
        return ($this->read)($content, $reportedType, $articleUrl);
    }

    /**
     * "The copy to try when the page itself is cut off or unavailable."
     *
     * @param array{url: string, type: string, language?: string}|null $alternate the first copy the page advertises
     */
    public static function for(string $articleUrl, ?array $alternate = null): ?self
    {
        if ($alternate !== null) {
            return new self($alternate['url'], true, static function (string $content, ?string $reportedType, string $articleUrl) use ($alternate): ?array {
                $json = Media::kind($reportedType) === 'json' || ($alternate['type'] === 'application/json' && preg_match('/^\s*[{\[]/', $content) === 1);

                if (!$json) {
                    return ['body' => $content, 'contentType' => $reportedType];
                }

                $body = Inspect::articleRepresentation($content, $articleUrl);

                return $body === null ? null : ['body' => $body];
            }, $alternate['language'] ?? null);
        }

        $site = self::site($articleUrl);

        if ($site !== null) {
            return $site;
        }

        $mdn = self::mdnUrl($articleUrl);

        return $mdn === null ? null : new self($mdn, true, static function (string $content, ?string $reportedType, string $articleUrl): ?array {
            $article = self::inspectMdn($content, $articleUrl);

            return $article === null ? null : [
                'body' => $article['html'],
                'articleUrl' => $article['resolvedUrl'],
                'completeness' => $article['completeness'],
                'notes' => $article['notes'],
                'metadata' => $article['metadata'],
                'language' => $article['metadata']['language'] ?? null,
            ];
        });
    }

    // ---- sites.ts ----------------------------------------------------------------------------

    /**
     * "A fuller copy the publisher serves for this page, when one is known": "Google's copy of a
     * Wikipedia article can stop in its header, and raw GitHub files stop after a third or so of
     * the file. Each site publishes the same document in another form that Google returns whole."
     */
    public static function site(string $articleUrl): ?self
    {
        $url = Url::parse($articleUrl);

        return $url === null ? null : (self::wikipedia($url) ?? self::githubRaw($url));
    }

    /**
     * "Wikipedia's parse API for `https://<lang>.wikipedia.org/wiki/<title>`. Its JSON names the
     * article, and a JSON document that parses is not cut off."
     */
    private static function wikipedia(Url $url): ?self
    {
        if (preg_match('/^[a-z0-9-]+\.wikipedia\.org$/D', $url->hostname()) !== 1 || ($url->query ?? '') !== '' || preg_match('#^/wiki/(.+)$#Ds', $url->path, $segment) !== 1) {
            return null;
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $segment[1]) === 1 || !mb_check_encoding(rawurldecode($segment[1]), 'UTF-8')) {
            return null;
        }

        $title = str_replace(' ', '_', rawurldecode($segment[1]));

        if ($title === '' || preg_match('/^Special:/i', $title) === 1) {
            return null;
        }

        // `URLSearchParams` writes a space as `+`, as this does.
        $api = $url->at('/w/api.php', http_build_query([
            'action' => 'parse', 'page' => $title, 'prop' => 'text', 'format' => 'json', 'formatversion' => '2',
            'redirects' => '1', 'disableeditsection' => '1', 'disabletoc' => '1',
        ]));

        return new self($api->href(), false, static function (string $content) use ($title): ?array {
            $data = json_validate($content) ? json_decode($content, true) : null;
            $parse = Inspect::isRecord($data) && Inspect::isRecord($data['parse'] ?? null) ? $data['parse'] : null;

            if ($parse === null || !is_string($parse['title'] ?? null) || !is_string($parse['text'] ?? null)) {
                return null;
            }

            $names = [$parse['title']];

            foreach (is_array($parse['redirects'] ?? null) ? $parse['redirects'] : [] as $item) {
                $names[] = Inspect::isRecord($item) ? ($item['from'] ?? null) : null;
            }

            // "MediaWiki capitalizes the first letter of a title, so `unix_philosophy` is `Unix philosophy`."
            $normal = static function (string $name): string {
                $spaced = str_replace(' ', '_', $name);

                return mb_strtoupper(mb_substr($spaced, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($spaced, 1, null, 'UTF-8');
            };

            // "The article must be the requested one, directly or through its redirect."
            if (array_filter($names, static fn (mixed $name): bool => is_string($name) && $normal($name) === $normal($title)) === []) {
                return null;
            }

            $heading = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $parse['title']);

            return [
                'body' => "<!doctype html><html><head><title>{$heading}</title></head><body><main><h1>{$heading}</h1>{$parse['text']}</main></body></html>",
                'contentType' => 'text/html',
                'completeness' => 'complete',
            ];
        });
    }

    /** "GitHub's file page for `https://raw.githubusercontent.com/<owner>/<repo>/<ref>/<path>`." */
    private static function githubRaw(Url $url): ?self
    {
        $parts = array_slice(explode('/', $url->path), 1);

        if ($url->hostname() !== 'raw.githubusercontent.com' || ($url->query ?? '') !== '' || count($parts) < 4 || in_array('', $parts, true)) {
            return null;
        }

        [$owner, $repository] = $parts;
        $rest = implode('/', array_slice($parts, 2));

        return new self("https://github.com/{$owner}/{$repository}/blob/{$rest}", false, static function (string $content) use ($rest): ?array {
            if (preg_match('/<script type="application\/json" data-target="react-app\.embeddedData">([\s\S]*?)<\/script>/', $content, $embedded) !== 1 || $embedded[1] === '') {
                return null;
            }

            $data = json_validate($embedded[1]) ? json_decode($embedded[1], true) : null;
            $payload = Inspect::isRecord($data) && Inspect::isRecord($data['payload'] ?? null) ? $data['payload'] : [];
            $route = Inspect::isRecord($payload['codeViewBlobLayoutRoute'] ?? null) ? $payload['codeViewBlobLayoutRoute'] : null;
            $styled = $payload['codeViewBlobLayoutRoute.StyledBlob'] ?? null;
            $file = $route !== null && Inspect::isRecord($route['blob'] ?? null) ? $route['blob'] : null;
            $lines = Inspect::isRecord($styled) ? ($styled['rawLines'] ?? null) : null;

            // "The file page must name the requested file, and carry all of its lines."
            if ($route === null || $file === null || !is_string($route['path'] ?? null) || !str_ends_with("/{$rest}", "/{$route['path']}")
                || ($file['truncated'] ?? null) !== false || !is_array($lines) || !array_is_list($lines) || array_filter($lines, static fn (mixed $line): bool => !is_string($line)) !== []) {
                return null;
            }

            return ['body' => implode("\n", $lines), 'contentType' => 'text/plain', 'completeness' => 'complete'];
        });
    }

    // ---- mdn.ts ------------------------------------------------------------------------------

    /** "MDN's observed article-data representation; no cross-origin or guessed source-repo mapping." */
    public static function mdnUrl(string $articleUrl): ?string
    {
        $url = Url::parse($articleUrl);

        if ($url === null || $url->origin() !== 'https://developer.mozilla.org' || ($url->query ?? '') !== ''
            || preg_match('#^/[a-z]{2,3}(?:-[a-z0-9]{2,8})*/docs/.+#is', $url->path) !== 1 || preg_match('#\.(?:json|md|txt)/?$#Di', $url->path) === 1) {
            return null;
        }

        return $url->withPath(preg_replace('#/$#D', '', $url->path) . '/index.json')->withoutFragment()->href();
    }

    /**
     * "Read only verified MDN article data. Google's copy can end inside sidebarHTML: in that case
     * parse the strictly valid document prefix before that property. Never repair an unfinished
     * article body or synthesize missing section content."
     *
     * @return array{html: string, resolvedUrl: string, completeness: string, metadata: array<string, ?string>, notes: list<string>}|null
     */
    public static function inspectMdn(string $content, string $articleUrl): ?array
    {
        if (self::mdnUrl($articleUrl) === null || Text::length($content) > 2_000_000) {
            return null;
        }

        $completeness = 'unknown';

        if (json_validate($content)) {
            $value = json_decode($content, true);
        } else {
            // "This schema places body and mdn_url before the large, unrelated sidebar."
            if (preg_match('/,\s*"sidebarHTML"\s*:/', $content, $sidebar, PREG_OFFSET_CAPTURE) !== 1) {
                return null;
            }

            $prefix = substr($content, 0, $sidebar[0][1]) . '}}';

            if (!json_validate($prefix)) {
                return null;
            }

            $value = json_decode($prefix, true);
            $completeness = 'incomplete';
        }

        $doc = Inspect::isRecord($value) && Inspect::isRecord($value['doc'] ?? null) ? $value['doc'] : null;

        if ($doc === null) {
            return null;
        }

        $pageTitle = is_string($doc['title'] ?? null) ? $doc['title'] : (is_string($doc['short_title'] ?? null) ? $doc['short_title'] : null);
        $body = $doc['body'] ?? null;

        if (!is_string($doc['mdn_url'] ?? null) || !is_array($body) || !array_is_list($body) || $body === [] || $pageTitle === null) {
            return null;
        }

        $expected = Url::parse($articleUrl);
        $identity = Url::parse($doc['mdn_url'], $articleUrl);

        if ($expected === null || $identity === null || preg_match('#^/[^/]+/docs/#', $expected->path, $prefix) !== 1
            || $identity->username !== '' || $identity->password !== '' || $identity->origin() !== $expected->origin()
            || self::mdnUrl($identity->href()) === null || !str_starts_with($identity->path, $prefix[0])
            || ($identity->query ?? '') !== '' || ($identity->fragment ?? '') !== '') {
            return null;
        }

        $document = Document::parse('<html><body><main></main></body></html>');
        $main = Document::first($document, '//main');
        \assert($main !== null);
        $omitted = [];
        $title = $document->createElement('h1');
        Document::setText($title, $pageTitle);
        $main->appendChild($title);

        foreach ($body as $section) {
            $data = Inspect::isRecord($section) ? ($section['value'] ?? null) : null;

            if (!Inspect::isRecord($section) || !Inspect::isRecord($data)) {
                $omitted['unrecognized'] = true;

                continue;
            }

            $type = $section['type'] ?? null;

            if ($type !== 'prose' && $type !== 'specifications') {
                $omitted[Text::clean(is_string($type) ? $type : 'unrecognized', 60) ?? 'unrecognized'] = true;

                continue;
            }

            if (is_string($data['title'] ?? null) && $data['title'] !== '') {
                $heading = $document->createElement(($data['isH3'] ?? null) === true ? 'h3' : 'h2');
                Document::setText($heading, $data['title']);
                $main->appendChild($heading);
            }

            if ($type === 'prose') {
                if (!is_string($data['content'] ?? null)) {
                    return null;
                }

                $block = $document->createElement('div');
                $sectionBody = Document::body(Document::parse($data['content']));

                foreach ($sectionBody === null ? [] : iterator_to_array($sectionBody->childNodes) as $child) {
                    $block->appendChild($document->importNode($child, true));
                }

                $main->appendChild($block);

                continue;
            }

            if (!is_array($data['specifications'] ?? null)) {
                return null;
            }

            $list = $document->createElement('ul');

            foreach ($data['specifications'] as $spec) {
                $href = Inspect::isRecord($spec) && is_string($spec['title'] ?? null) && is_string($spec['bcdSpecificationURL'] ?? null)
                    ? Url::parse($spec['bcdSpecificationURL'], $articleUrl)
                    : null;

                if ($href === null || !$href->isWeb()) {
                    return null;
                }

                $item = $document->createElement('li');
                $link = $document->createElement('a');
                $link->setAttribute('href', $href->href());
                Document::setText($link, $spec['title']);
                $item->appendChild($link);
                $list->appendChild($item);
            }

            $main->appendChild($list);
        }

        $notes = [];

        if ($omitted !== []) {
            $completeness = 'incomplete';
            $notes[] = 'Publisher sections omitted: ' . implode(', ', array_keys($omitted)) . '.';
        }

        return [
            'html' => (string) $document->saveHTML($document->documentElement),
            'notes' => $notes,
            'resolvedUrl' => $identity->href(),
            'completeness' => $completeness,
            'metadata' => [
                'title' => Text::clean($pageTitle, Markdown::METADATA_LIMITS['title']),
                'updated' => Text::clean(is_string($doc['modified'] ?? null) ? $doc['modified'] : null, Markdown::METADATA_LIMITS['updated']),
                'language' => Text::clean(is_string($doc['locale'] ?? null) ? $doc['locale'] : null, Markdown::METADATA_LIMITS['language']),
            ],
        ];
    }
}
