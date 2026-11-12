<?php

declare(strict_types=1);

namespace PigAntigravity\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PigAntigravity\Redaction;
use PigAntigravity\WebFetch\Document;
use PigAntigravity\WebFetch\DocumentLimitError;
use PigAntigravity\WebFetch\Inspect;
use PigAntigravity\WebFetch\Markdown;
use PigAntigravity\WebFetch\PublicUrl;
use PigAntigravity\WebFetch\Representation;
use PigAntigravity\WebFetch\Text;
use PigAntigravity\WebFetch\Turndown;
use PigAntigravity\WebFetch\Url;
use PigAntigravity\WebFetch\WebFetch;

/**
 * What the reader stands on: text, credentials, the URL policy, inspection, work bounds and the
 * conversion itself — pi-antigravity 0.13's `scripts/test-reader-foundations.ts`, and the cases
 * that hold this package's own URL parser and Markdown converter to what upstream's libraries do.
 */
final class AntigravityReaderFoundationsTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!Document::available()) {
            $this->markTestSkipped("PHP's dom extension is not loaded.");
        }
    }

    private static function page(string $body, string $title = 'Test'): string
    {
        return "<!doctype html><html><head><title>{$title}</title></head><body>{$body}</body></html>";
    }

    // ---- text --------------------------------------------------------------------------------

    public function testTextThatIsNotTextIsRepairedAndMetadataIsOneBoundedLine(): void
    {
        // PHP strings are bytes: what JavaScript calls a lone surrogate is here three bytes that
        // are not UTF-8, and each is replaced.
        $this->assertSame("x\u{FFFD}\u{FFFD}\u{FFFD}\ny", Text::repair("x\xED\xA0\x80\ny"));
        $this->assertSame("a\u{FFFD}b", Text::repair("a\xFFb"));
        $this->assertSame('a b c', Text::clean("a\n\x00\tb\u{2028}c", 50));
        $this->assertNull(Text::clean(" \n ", 50));

        foreach ([1, 2, 3, 4] as $limit) {
            $value = (string) Text::clean('😀😀😀😀😀', $limit);
            $this->assertLessThanOrEqual($limit, mb_strlen($value));
            $this->assertTrue(mb_check_encoding($value, 'UTF-8'), 'a character is never cut through');
        }

        $this->assertSame('abc…xyz', Text::elideMiddle('abcdefghijklmnopqrstuvwxyz', 7));
        $this->assertSame("\u{00A0}", Text::trim("\u{00A0}x\u{3000}") === 'x' ? "\u{00A0}" : '', 'JavaScript whitespace is trimmed, not only ASCII');
    }

    // ---- credentials -------------------------------------------------------------------------

    public function testCredentialsAreRedactedByShapeByParameterAndByAssignmentAndOrdinaryValuesAreNot(): void
    {
        $this->assertSame(['secret' => '[redacted]'], json_decode(Redaction::redactSecrets((string) json_encode(['secret' => str_repeat('x', 10_000)])), true));
        $this->assertStringNotContainsString('password', Redaction::redactSecrets('https://user:password@part@host.example/'));
        $this->assertStringNotContainsString('secret-value', Redaction::redactSecrets('https://example.com/?redirect=https://other.example/?access_token=secret-value'));

        $ordinary = 'state=UNAVAILABLE code=429 session=expired key=one';
        $this->assertSame($ordinary, Redaction::redactSecrets($ordinary));
        $this->assertSame(
            ['token' => '[redacted]', 'state' => 'UNAVAILABLE', 'password' => '[redacted]'],
            json_decode(Redaction::redactSecrets('{"token":"secret-value","state":"UNAVAILABLE","password":"a b"}'), true),
        );

        foreach (['ya29.fixture', '1//' . str_repeat('a', 30), '4/0A' . str_repeat('b', 30), 'GOCSPX-' . str_repeat('c', 30)] as $secret) {
            $this->assertStringNotContainsString($secret, Redaction::redactSecrets("value: {$secret}"));
        }

        foreach (['access_token', 'subscription_key', 'client-secret', 'apiKey', '%61ccess_token'] as $name) {
            $this->assertStringNotContainsString('secret-value', Redaction::redactSecrets("https://example.com/?{$name}=secret-value"), $name);
        }

        $this->assertStringNotContainsString('secret-value', Redaction::redactSecrets('https://example.com/?next=' . rawurlencode('?access_token=secret-value')));

        foreach (['private_key', 'oauth_consumer_key', 'x-amz-credential', 'api_key'] as $field) {
            $this->assertStringNotContainsString('secret-value', Redaction::redactSecrets("{$field}=secret-value"), $field);
        }

        $this->assertNotNull(json_decode(Redaction::redactSecrets('{"url":"https://example.com/?token=secret"}')));
        $this->assertSame(str_repeat('?', 100_000), Redaction::redactSecrets(str_repeat('?', 100_000)), 'many separators cost linear time');
    }

    // ---- the URL policy ----------------------------------------------------------------------

    public function testOnlyAPublicWebAddressMayBeAskedFor(): void
    {
        foreach (['192.0.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '::1', '64:ff9b:1::1', '2001:2::1', '3fff::1', 'fe80::1%eth0'] as $address) {
            $this->assertTrue(PublicUrl::isPrivateAddress($address), $address);
        }

        foreach (['8.8.8.8', '2606:4700::1111', '::ffff:808:808', '::ffff:0:808:808', '2606:4700::1111%eth0', 'example.com'] as $address) {
            $this->assertFalse(PublicUrl::isPrivateAddress($address), $address);
        }

        foreach ([
            'http://host/' => 'local', 'http://foo.localdomain/' => 'local', 'http://foo.test/' => 'local', 'http://127.1/' => 'local',
            'http://0x7f000001/' => 'local', 'http://2130706433/' => 'local', 'http://[::ffff:127.0.0.1]/' => 'local', 'http://169.254.169.254/latest' => 'local',
            'https://u:p@example.com/' => 'credentials', 'javascript:alert(1)' => 'scheme', 'ftp://example.com/x' => 'scheme',
            'not a url' => 'invalid', '' => 'missing', 'https://example.com/' . 'x' => null,
        ] as $url => $reason) {
            $checked = PublicUrl::check((string) $url);
            $this->assertSame($reason, $checked['ok'] ? null : $checked['reason'], (string) $url);
        }

        $this->assertSame(['ok' => false, 'reason' => 'too-long'], PublicUrl::check('https://example.com/' . str_repeat('x', 2048)));
        $this->assertSame(['ok' => true, 'url' => 'https://example.com/a%20b?q=1'], PublicUrl::check('  HTTPS://Example.COM:443/x/../a b?q=1  '));
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function urls(): iterable
    {
        yield 'case, default port, dot segments and spaces' => ['https://Example.COM:443/a/../b/./c?x=1 2#f g', null, 'https://example.com/b/c?x=1%202#f%20g'];
        yield 'relative to a directory' => ['rel/x', 'https://other.example/dir/', 'https://other.example/dir/rel/x'];
        yield 'an absolute path drops the query' => ['/x', 'https://e.com/a/b?q#f', 'https://e.com/x'];
        yield 'a query alone keeps the path' => ['?z', 'https://e.com/a/b?q#f', 'https://e.com/a/b?z'];
        yield 'a fragment alone keeps the query' => ['#h', 'https://e.com/a/b?q', 'https://e.com/a/b?q#h'];
        yield 'scheme-relative' => ['//cdn.e.com/p', 'https://e.com/a', 'https://cdn.e.com/p'];
        yield 'above the root stays at the root' => ['../../../up', 'https://e.com/a/b/c', 'https://e.com/up'];
        yield 'no path is the root' => ['https://e.com', null, 'https://e.com/'];
        yield 'a short IPv4 host is written out' => ['http://127.1/', null, 'http://127.0.0.1/'];
        yield 'IPv6 is compressed and lowercased' => ['http://[2001:DB8:0:0:0:0:0:1]:8080/', null, 'http://[2001:db8::1]:8080/'];
        yield 'another scheme is kept whole' => ['mailto:a@b', 'https://e.com/', 'mailto:a@b'];
        yield 'relative with no base is nothing' => ['nope', null, null];
        yield 'a host that is not one is nothing' => ['https://exa mple.com/page', null, null];
        yield 'an IPv4 number out of range is nothing' => ['http://256.1.1.1/', null, null];
        yield 'a port out of range is nothing' => ['https://e.com:99999/', null, null];
    }

    #[DataProvider('urls')]
    public function testAUrlIsWrittenTheWayABrowserWritesIt(string $input, ?string $base, ?string $href): void
    {
        $this->assertSame($href, Url::parse($input, $base)?->href());
    }

    // ---- inspection --------------------------------------------------------------------------

    public function testAHeadingAloneDoesNotMakeAPageAWall(): void
    {
        foreach (['403 Forbidden', '401 Unauthorized', 'Sign in with Google', 'Just a moment in Rust async', 'Security check in CI pipelines'] as $title) {
            $article = self::page("<main><h1>{$title}</h1><p>" . str_repeat('An article explains this behavior. ', 30) . '</p></main>', $title);
            $this->assertSame('readable', Inspect::page($article, 'https://example.com/')['assessment'], $title);
        }

        foreach (['403 Forbidden', '401 Unauthorized', 'Sign in with Google', 'Security check in CI pipelines'] as $title) {
            $this->assertSame('readable', Inspect::page(self::page("<main><h1>{$title}</h1><p>Body.</p></main>", $title), 'https://example.com/')['assessment'], $title);
        }

        $this->assertSame('readable', Inspect::page(self::page('<main><h1>Only a heading</h1></main>'), 'https://example.com/')['assessment']);
        $this->assertSame('suspected-access-wall', Inspect::page(self::page('<main><h1>Robot or human?</h1><p>Verify to proceed.</p></main>'), 'https://example.com/')['assessment']);

        // "A cut-off copy that holds only the title has lost its body."
        $cutOff = Inspect::page('<!doctype html><html><head><title>T</title></head><body><main><h1>Unix philosophy</h1>', 'https://example.com/');
        $this->assertSame(['incomplete', 'empty'], [$cutOff['completeness'], $cutOff['assessment']]);
        $this->assertSame("# Header\n\n\u{FFFD}", Inspect::page("# Header\n\n\xFF", 'https://example.com/')['rendered']);
    }

    public function testMetadataIsThePagesOwnAndAnArticlesStructuredDataFillsWhatItLeavesOut(): void
    {
        $ld = (string) json_encode(['@graph' => [
            ['@type' => 'Person', '@id' => '#me', 'name' => 'A. Writer'],
            ['@type' => ['Thing', 'BlogPosting'], 'url' => 'https://example.com/post#top', 'headline' => 'From data', 'datePublished' => '2026-01-02', 'dateModified' => '2026-02-03', 'author' => ['@id' => '#me']],
            ['@type' => 'Article', 'url' => 'https://example.com/other', 'headline' => 'Another page'],
        ]]);
        $html = "<!doctype html><html lang=\"en-GB\"><head><title> The  title\n</title><meta name=\"description\" content=\" About it \">"
            . "<script type=\"application/ld+json\">{$ld}</script>"
            . '<link rel="alternate" type="application/json" href="/post.json"><link rel="ALTERNATE stylesheet" type="text/markdown; charset=utf-8" href="post.md" hreflang="en">'
            . '<link rel="alternate" type="text/markdown" href="https://elsewhere.example/post.md"><link rel="alternate" type="text/markdown" href="/de.md" hreflang="de">'
            . '</head><body><main><h1>Post</h1><p>Words.</p></main></body></html>';

        $page = Inspect::page($html, 'https://example.com/post');

        $this->assertSame(
            ['title' => 'The title', 'description' => 'About it', 'updated' => '2026-02-03', 'language' => 'en-GB', 'published' => '2026-01-02', 'author' => 'A. Writer'],
            $page['metadata'],
        );
        // Markdown before JSON, the page's own language only, and only its own origin.
        $this->assertSame([
            ['url' => 'https://example.com/post.md', 'type' => 'text/markdown', 'language' => 'en'],
            ['url' => 'https://example.com/post.json', 'type' => 'application/json'],
        ], $page['alternates']);
    }

    public function testACopyIsWholeOnlyWhenItsSourceSaysSo(): void
    {
        $this->assertSame('complete', Markdown::classifyCopyCompleteness(self::page('<main><p>x</p></main>')));
        $this->assertSame('incomplete', Markdown::classifyCopyCompleteness('<!doctype html><html><body><main><p>cut'));
        $this->assertSame('incomplete', Markdown::classifyCopyCompleteness(self::page('<p>x</p><a href="/y')));
        $this->assertSame('incomplete', Markdown::classifyCopyCompleteness(self::page('<script>var a = 1;')));
        $this->assertSame('unknown', Markdown::classifyCopyCompleteness('<p>A fragment.</p>'));
        $this->assertSame('unknown', Markdown::classifyCopyCompleteness('<!doctype html><html><body><p>No end tags.'));
        $this->assertSame('complete', Markdown::classifyCopyCompleteness("<html><body><a title=\"a > b\" href='x'>l</a></body></html>\n"));
    }

    public function testWhatIsAPageAndWhatOnlyMentionsATag(): void
    {
        $this->assertFalse(Markdown::looksLikeHtml('<svg xmlns="http://www.w3.org/2000/svg"><text>hello</text></svg>'));
        $this->assertFalse(Markdown::looksLikeHtml('<urlset><url><loc>https://example.com</loc></url></urlset>', 'application/xml'));
        $this->assertTrue(Markdown::looksLikeHtml('<p>Body.</p> Advertisement.'));
        $this->assertTrue(Markdown::looksLikeHtml('<div>Body</div> More text'));
        $this->assertFalse(Markdown::looksLikeHtml('<root><entry>XML</entry></root>'));
        $this->assertFalse(Markdown::looksLikeHtml('<p> is the paragraph element, closed by </p>.'));
        $this->assertTrue(Markdown::looksLikeHtml(self::page('<p>Visible.</p>'), 'text/plain'));
        $this->assertFalse(Markdown::looksLikeHtml('{"a": "<p>x</p>"}'));
        $this->assertFalse(Markdown::looksLikeHtml("# Notes\n\nUse <br> between lines.\n\n```html\n<div>x</div>\n```\n"));
    }

    // ---- choosing the content ----------------------------------------------------------------

    public function testThePagesContentIsKeptAndItsChromeIsNot(): void
    {
        $this->assertStringContainsString('Useful text.', Markdown::htmlToMarkdown(self::page('<main hidden>Nothing</main><article><h1>Real article</h1><p>Useful text.</p></article>')));

        foreach (['<div class="site-footer">Site chrome</div>', '<section class="content-section article-footer">Site chrome</section>', '<div class="site-sidebar">Site chrome</div>', '<nav>Site chrome</nav>', '<div style="color: red; display: none">Site chrome</div>', '<div aria-hidden="TRUE">Site chrome</div>', '<form>Site chrome</form>'] as $chrome) {
            $rendered = Markdown::htmlToMarkdown(self::page("<main><p>Useful.</p>{$chrome}</main>"));
            $this->assertStringContainsString('Useful.', $rendered, $chrome);
            $this->assertStringNotContainsString('Site chrome', $rendered, $chrome);
        }

        // "Utility layout classes are not chrome, and a wrapper holding the content root stays."
        foreach (['lg:grid-cols-sidebar-content', 'page-sidebar'] as $class) {
            $this->assertStringContainsString('Layout-wrapped article body.', Markdown::htmlToMarkdown(self::page("<div class=\"{$class}\"><main><h1>Quick Start</h1><p>Layout-wrapped article body.</p></main></div>")), $class);
        }

        // "Layout and state classes on a wrapper that holds the article do not delete it."
        $article = '<h1>Heading</h1><p>' . str_repeat('Substantive article sentence. ', 30) . '</p>';

        foreach (['has-sidebar', 'menu-open', 'nav-open', 'with-toc', 'content-sidebar-wrap', 'footer-wrap'] as $class) {
            $this->assertStringContainsString('Substantive article sentence.', Markdown::htmlToMarkdown(self::page("<div class=\"{$class}\"><div class=\"post\">{$article}</div></div>")), $class);
        }

        $this->assertStringContainsString('Short article.', Markdown::htmlToMarkdown(self::page('<div class="lg:grid-cols-sidebar-content"><p>Short article.</p></div>')));
        $this->assertStringContainsString('Plain paragraph text only.', Markdown::htmlToMarkdown(self::page('<div class="has-sidebar"><p>' . str_repeat('Plain paragraph text only. ', 40) . '</p></div>')));
        $this->assertStringNotContainsString('Related links', Markdown::htmlToMarkdown(self::page("<div class=\"post\">{$article}</div><div class=\"sidebar\">Related links</div>")));
    }

    public function testLinksAndImagesPointWhereThePageSaysAndWhatIsNotALinkIsNotWrittenAsOne(): void
    {
        $based = str_replace('<head>', '<head><base href="https://other.example/dir/">', self::page('<p><a href="rel/x">a link</a></p>'));
        $this->assertStringContainsString('(https://other.example/dir/rel/x)', Markdown::htmlToMarkdown($based, 'https://example.com/post/'));

        $rendered = Markdown::htmlToMarkdown(self::page(
            '<p><a href="/a" title="t">A</a> <a href="#top">Top</a> <a href="#main">Skip to content</a> <a href="javascript:void(0)">Run</a> <a href="/empty"></a>'
            . '<a href="https://example.com/b">https://example.com/b</a> <a href="/img"><img src="p.png" alt="P" title="x"></a> <img src="data:image/png;base64,AAAA" alt="inline"> <a href="/aria" aria-label="Labelled"></a></p>',
        ), 'https://example.com/dir/page');

        $this->assertSame('[A](https://example.com/a) Top Run https://example.com/b ![P](https://example.com/dir/p.png)  [Labelled](https://example.com/aria)', $rendered);
    }

    public function testCodeTablesAndListsAreWrittenAsMarkdownHasThem(): void
    {
        $this->assertSame(
            "```\nline one\nline <two>\n```\n\n```python\nprint(1)\n```",
            Markdown::htmlToMarkdown(self::page('<main><pre>line one<br>line &lt;two&gt;</pre><pre><code class="language-python">print(1)' . "\n" . '</code></pre></main>')),
        );

        // A grid with spans becomes a rectangle, the spanned places left empty; a table with no heading row gets an empty one.
        $this->assertSame(
            "Caption\n\n| A | B |  |\n| --- | --- | --- |\n| 1 | 2 \\| 3 |  |\n|  | x | y |",
            Markdown::htmlToMarkdown(self::page('<table><caption>Caption</caption><tr><th>A</th><th colspan="2">B</th></tr><tr><td rowspan="2">1</td><td>2 | 3</td></tr><tr><td>x</td><td>y</td></tr></table>')),
        );
        // A cell's escapes are written once (upstream writes them twice), and its own backslash stays one.
        $this->assertSame(
            "out \\[1\\]\n\n| A | B |\n| --- | --- |\n| in \\[1\\] 1\\*2 a\\\\b | [C](https://e.com/w/C_\\(x\\)) |",
            Markdown::htmlToMarkdown(self::page('<main><p>out [1]</p><table><tr><th>A</th><th>B</th></tr><tr><td>in [1] 1*2 a\\b</td><td><a href="/w/C_(x)">C</a></td></tr></table></main>'), 'https://e.com/'),
        );
        $this->assertSame(
            "|  |  |\n| --- | --- |\n| a | b |",
            Markdown::htmlToMarkdown(self::page('<table><tr><td>a</td><td>b</td></tr><tr><td> </td><td></td></tr></table>')),
        );
        // A table inside a table is flattened into the cell that holds it.
        $this->assertSame(
            "| Outer |\n| --- |\n| in1 — in2; in3 |",
            Markdown::htmlToMarkdown(self::page('<table><tr><th>Outer</th></tr><tr><td><table><tr><td>in1</td><td>in2</td></tr><tr><td>in3</td></tr></table></td></tr></table>')),
        );
        $this->assertSame(
            "1.  one\n2.  two\n    -   nested `x*y`\n\n> quoted **bold** and ~~gone~~",
            Markdown::htmlToMarkdown(self::page('<ol><li>one</li><li>two<ul><li>nested <code>x*y</code></li><li> </li></ul></li></ol><blockquote><p>quoted <b>bold</b> and <s>gone</s></p></blockquote>')),
        );
    }

    public function testTextThatWouldBeMarkdownIsEscapedAndWhitespaceIsWhatABrowserShows(): void
    {
        $document = Document::parse(self::page("<div><p>1. not a list, *not emphasis*, [not a link] and snake_case</p><p>  spread\n   over <em> lines </em>here </p><p># not a heading</p></div>"));
        $body = Document::body($document);
        \assert($body !== null);

        $this->assertSame("1\\. not a list, \\*not emphasis\\*, \\[not a link\\] and snake\\_case\n\nspread over *lines* here\n\n\\# not a heading", Turndown::convert($body));
    }

    // ---- work bounds -------------------------------------------------------------------------

    public function testAPageBeyondTheWorkLimitsIsRefusedBeforeItIsParsed(): void
    {
        foreach ([
            [str_repeat('<div>', 2000) . 'Text' . str_repeat('</div>', 2000), '/nested too deeply/'],
            [str_repeat('x', Document::MAX_CHARS + 1), '/too large/'],
            [str_repeat('<br>', Document::MAX_NODES + 1), '/too many elements/'],
        ] as [$source, $pattern]) {
            try {
                Document::parse($source);
                $this->fail("parsed, wanted {$pattern}");
            } catch (DocumentLimitError $error) {
                $this->assertMatchesRegularExpression($pattern, $error->getMessage());
            }
        }

        // Optional end tags, script strings and comments are not depth.
        Document::parse(self::page('<ul>' . str_repeat('<li><p>Item', 1000) . '</ul>'));
        Document::parse(self::page('<script>' . str_repeat('<div>', 5000) . '</script><!--' . str_repeat('<div>', 5000) . '--><p>Visible.</p>'));

        // "Large reference pages convert: RFC-sized markup fits the work limits."
        $reference = self::page('<main>' . str_repeat('<section><h2>Section</h2><p>' . str_repeat('Reference text. ', 12) . '<a href=/x>a link</a> and <code>code</code>.</p></section>', 3_000) . '</main>');
        $this->assertGreaterThan(512_000, strlen($reference));
        $this->assertStringContainsString('## Section', Markdown::htmlToMarkdown($reference));

        $blocks = '';

        for ($index = 0; $index < 2000; $index++) {
            $blocks .= "<h2>Section {$index}</h2><p>Body {$index} with <a href=\"/link\">a link</a>.</p>";
        }

        $converted = Markdown::htmlToMarkdown(self::page("<main>{$blocks}</main>"));
        $this->assertStringContainsString('## Section 1999', $converted);
        $this->assertStringContainsString('[a link](/link)', $converted);

        // "Tag names the parser accepts but the DOM refuses to clone no longer fail the read."
        foreach (['<a<a>x</a>', '<p<p>x', '<z:a<b>x</z:a<b>', '<a<a href="/x">x</a>'] as $markup) {
            $rendered = Markdown::htmlToMarkdown(self::page("<main><p>Useful text.</p>{$markup}</main>"));
            $this->assertTrue(str_contains($rendered, 'Useful text.') && str_contains($rendered, 'x'), $markup);
        }
    }

    // ---- MDN ---------------------------------------------------------------------------------

    public function testMdnsArticleDataIsReadOnlyWhenItNamesTheArticleAndNeverRebuilt(): void
    {
        $url = 'https://developer.mozilla.org/en-US/docs/Test';
        $this->assertSame('https://developer.mozilla.org/en-US/docs/Test/index.json', Representation::mdnUrl("{$url}/#section"));
        $this->assertNull(Representation::mdnUrl('https://developer.mozilla.org/en-US/docs/Test/index.json'));
        $this->assertNull(Representation::mdnUrl('https://example.com/en-US/docs/Test'));

        $mdn = Representation::inspectMdn((string) json_encode(['doc' => [
            'title' => "Title\n\xED\xA0\x80",
            'locale' => "en-US\nforged",
            'mdn_url' => '/en-US/docs/Test',
            'body' => [
                ['type' => 'prose', 'value' => ['title' => 'Syntax', 'content' => '<p>Real prose.</p>']],
                ['type' => 'specifications', 'value' => ['title' => 'Specifications', 'specifications' => [['title' => 'The Spec', 'bcdSpecificationURL' => 'https://spec.example/#x']]]],
                ['type' => 'interactive-example', 'value' => []],
            ],
        ]], JSON_INVALID_UTF8_SUBSTITUTE), $url);

        $this->assertNotNull($mdn);
        $this->assertStringContainsString('<p>Real prose.</p>', $mdn['html']);
        $this->assertStringContainsString('<h2>Syntax</h2>', $mdn['html']);
        $this->assertStringContainsString('<li><a href="https://spec.example/#x">The Spec</a></li>', $mdn['html']);
        $this->assertSame('incomplete', $mdn['completeness']);
        $this->assertSame(['Publisher sections omitted: interactive-example.'], $mdn['notes']);
        $this->assertSame('en-US forged', $mdn['metadata']['language']);

        // Cut off inside the sidebar: the article before it is whole, and said to be a cut copy.
        $cut = '{"doc":{"title":"T","mdn_url":"/en-US/docs/Test","body":[{"type":"prose","value":{"content":"<p>Body.</p>"}}],"sidebarHTML":"<ol><li>unfinis';
        $this->assertSame('incomplete', Representation::inspectMdn($cut, $url)['completeness'] ?? null);
        // Another article's data, or one cut inside its body, is not this article.
        $this->assertNull(Representation::inspectMdn('{"doc":{"title":"T","mdn_url":"https://evil.example/en-US/docs/Test","body":[{"type":"prose","value":{"content":"x"}}]}}', $url));
        $this->assertNull(Representation::inspectMdn('{"doc":{"title":"T","mdn_url":"/en-US/docs/Test","body":[{"type":"prose","value":{"content":"<p>cut', $url));
    }

    // ---- the header --------------------------------------------------------------------------

    public function testTheHeaderKeepsProvenanceInsideASmallBudget(): void
    {
        $result = [
            'requestedUrl' => 'https://example.com/' . str_repeat('a', 500) . 'b', 'resolvedUrl' => 'https://example.com/' . str_repeat('a', 500) . 'c',
            'content' => '', 'bytes' => 0, 'partial' => null, 'source' => 'cache', 'truncated' => false, 'maxChars' => 1000,
            'title' => str_repeat('Long title ', 60), 'description' => str_repeat('Long description. ', 80), 'author' => "A\nSource: https://forged.example/ (live)",
        ];

        $header = WebFetch::formatHeader($result);

        $this->assertLessThanOrEqual(1000, mb_strlen($header));
        $this->assertStringContainsString('Requested: https://example.com/aaa', $header);
        $this->assertStringContainsString('Source: https://example.com/aaa', $header);
        $this->assertStringContainsString("Note: the crawler's copy has no clear end marker and holds no readable content.", $header);
        $this->assertCount(1, preg_grep('/^Source: /', explode("\n", $header)) ?: [], 'metadata cannot forge a second source line');
        $this->assertStringEndsWith("\n\n---\n\n", $header);
    }

    // ---- real pages --------------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string, string}> */
    public static function fixtures(): iterable
    {
        foreach ([
            ['first-website', 'World Wide Web', 'complete'],
            ['iana-http-status-codes.xhtml', 'Status Code Registry', 'complete'],
            ['mdn-status-page', 'HTTP response status codes', 'complete'],
            ['nasa-artemis-heat-shield', 'Heat Shield', 'complete'],
            ['python-urllib-request', 'urllib.request', 'incomplete'],
            ['rfc9110-excerpt', 'HTTP Semantics', 'incomplete'],
            ['wikipedia-artemis-ii', 'Artemis II', 'incomplete'],
            ['javadoc-string', 'String', 'incomplete'],
            ['nist-sp800-53r5', '53', 'complete'],
            ['tldp-howto', 'Unix and Internet', 'unknown'],
            ['github-docs-hello-world', '', 'incomplete', 'empty'],
            ['mdn-status-shell', '', 'unknown', 'suspected-shell'],
            ['spacejam', '', 'incomplete', 'empty'],
        ] as $fixture) {
            yield $fixture[0] => [$fixture[0], $fixture[1], $fixture[2], $fixture[3] ?? 'readable'];
        }
    }

    #[DataProvider('fixtures')]
    public function testASavedCopyOfARealPageIsReadAsUpstreamReadsIt(string $name, string $marker, string $completeness, string $assessment): void
    {
        $file = dirname(__DIR__) . '/fixtures/web-fetch/' . (str_ends_with($name, '.xhtml') ? "{$name}.gz" : "{$name}.html.gz");
        $source = (string) gzdecode((string) file_get_contents($file));

        $result = Inspect::page($source, 'https://example.com/');

        $this->assertSame([$assessment, $completeness], [$result['assessment'], $result['completeness']]);
        $this->assertStringContainsString($marker, $result['rendered']);
        $this->assertTrue(mb_check_encoding($result['rendered'], 'UTF-8'));
    }

    public function testTheSavedPagesKeepTheirTablesFencesAndLinks(): void
    {
        $read = static fn (string $file): string => Inspect::page((string) gzdecode((string) file_get_contents(dirname(__DIR__) . "/fixtures/web-fetch/{$file}")), 'https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml')['rendered'];
        $iana = $read('iana-http-status-codes.xhtml.gz');

        $this->assertStringStartsWith("# Hypertext Transfer Protocol (HTTP) Status Code Registry\n", $iana);
        $this->assertStringContainsString("| Value | Description | Reference |\n| --- | --- | --- |\n| 100 | Continue | [RFC 9110, Section 15.2.1](https://www.iana.org/go/rfc9110#section-15.2.1) |", $iana);
        $this->assertStringContainsString("```\n1xx: Informational - Request received, continuing process\n", $iana);
        $this->assertStringContainsString('-   [CSV](https://www.iana.org/assignments/http-status-codes/http-status-codes-1.csv)', $iana);
    }
}
