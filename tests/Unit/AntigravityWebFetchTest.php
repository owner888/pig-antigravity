<?php

declare(strict_types=1);

namespace PigAntigravity\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use PigAntigravity\Test\RoutedServer;
use PigAntigravity\WebFetch\ReadUrlContent;
use PigAntigravity\WebFetch\WebFetch;
use PigAntigravity\WebFetch\WebFetchError;

/**
 * `read_url_content` from the request to the answer, against a stand-in for Google's fetch
 * service — the scenarios of pi-antigravity 0.13's `scripts/test-web-fetch.ts`.
 */
final class AntigravityWebFetchTest extends TestCase
{
    private RoutedServer $server;

    private string $endpoint;

    private string $scratch;

    private string|false $previousTmp;

    #[\Override]
    protected function setUp(): void
    {
        if (!\PigAntigravity\WebFetch\Document::available()) {
            $this->markTestSkipped("PHP's dom extension is not loaded.");
        }

        Loop::reset();
        $this->server = new RoutedServer(static fn (): array => [200, []]);
        $this->endpoint = $this->server->start();
        // Saved pages land in a directory this test removes.
        $this->scratch = sys_get_temp_dir() . '/pig-url-test-' . bin2hex(random_bytes(4));
        mkdir($this->scratch, 0o700, true);
        $this->previousTmp = getenv('TMPDIR');
        putenv("TMPDIR={$this->scratch}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
            putenv($this->previousTmp === false ? 'TMPDIR' : "TMPDIR={$this->previousTmp}");
            putenv('ANTIGRAVITY_WEB_FETCH_MAX_CHARS');
            exec('rm -rf ' . escapeshellarg($this->scratch));
        }
    }

    private const string KEY = '{"token":"ya29.fixture-token","projectId":"fixture-project"}';

    /** A complete, readable page. */
    private static function article(string $body, string $extra = '', string $lang = 'en'): string
    {
        return "<!doctype html><html lang=\"{$lang}\"><head><title>Test page</title>{$extra}</head><body><main><h1>Test page</h1><p>{$body}</p></main></body></html>";
    }

    /** The same page with its end cut off, so the tool looks for a better copy. */
    private static function cutArticle(string $body, string $extra = '', string $lang = 'en'): string
    {
        return "<!doctype html><html lang=\"{$lang}\"><head><title>Test page</title>{$extra}</head><body><main><h1>Test page</h1><p>{$body}</p>";
    }

    /**
     * The tool's answer for $url.
     *
     * @return array{0: string, 1: array<string, mixed>} text and details
     */
    private function read(string $url, ?int $maxChars = null): array
    {
        putenv($maxChars === null ? 'ANTIGRAVITY_WEB_FETCH_MAX_CHARS' : "ANTIGRAVITY_WEB_FETCH_MAX_CHARS={$maxChars}");

        return Async::run(fn (): array => ReadUrlContent::run(self::KEY, $url, endpoints: [$this->endpoint]));
    }

    private function failure(string $url, bool $raw = false): string
    {
        try {
            Async::run(fn (): array => WebFetch::execute(self::KEY, $url, $raw, endpoints: [$this->endpoint]));
        } catch (WebFetchError $error) {
            return $error->getMessage();
        }

        $this->fail("{$url} was read");
    }

    /** The page body, without the provenance header and the saved-page footer. */
    private static function bodyOf(string $text): string
    {
        $separator = strpos($text, "\n\n---\n\n");
        $body = $separator === false ? $text : substr($text, $separator + 7);
        $trailer = strrpos($body, "\n\n[Full page saved to: ");

        return $trailer === false ? $body : substr($body, 0, $trailer);
    }

    /** Whether a request was for the redirect lookup. */
    private static function isRewrite(string $path): bool
    {
        return str_ends_with($path, ':rewriteUri');
    }

    // ---- guards ----------------------------------------------------------------------------

    public function testAnUnsafeTargetIsRefusedBeforeTheServiceIsAsked(): void
    {
        foreach (['http://127.0.0.1/', 'http://10.1.2.3/', 'http://[::1]/', 'http://localhost/', 'ftp://example.com/x', 'https://user:supersecret@example.com/'] as $url) {
            $message = $this->failure($url);
            $this->assertMatchesRegularExpression('/private or local|Only http and https|credentials/', $message, $url);
            $this->assertStringNotContainsString('supersecret', $message);
        }

        $this->assertSame([], $this->server->calls, 'a refused target must not reach the service');
    }

    // ---- a readable page ---------------------------------------------------------------------

    public function testAReadablePageArrivesAsMarkdownWithProvenanceAndALongOneIsSavedWhole(): void
    {
        $body = trim(str_repeat('The quick brown fox jumps over the lazy dog. ', 200));
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => self::article($body)]]);

        [$text, $details] = $this->read('https://example.com/page', 1_000);

        $this->assertStringContainsString('Title: Test page', $text);
        $this->assertStringContainsString('Source: https://example.com/page (cache)', $text);
        $this->assertCount(1, array_filter(explode("\n", $text), static fn (string $line): bool => str_starts_with($line, 'Source: ')));
        $this->assertLessThanOrEqual(1_000, mb_strlen($text) + mb_strlen((string) json_encode($details, JSON_UNESCAPED_SLASHES)), 'text and details share the budget');
        $this->assertSame('cache', $details['source']);
        $this->assertTrue($details['truncated']);

        // The whole page is on disk, private to the person, and the answer says where.
        $path = $details['fullOutputPath'];
        $this->assertStringStartsWith($this->scratch, $path);
        $this->assertStringContainsString("[Full page saved to: {$path} (", $text);
        $saved = (string) file_get_contents($path);
        $this->assertStringStartsWith('Page metadata', $saved);
        $this->assertStringEndsWith("# Test page\n\n{$body}", $saved);
        $this->assertSame(0o600, fileperms($path) & 0o777);
        $this->assertStringContainsStringIgnoringCase('authorization: Bearer ya29.fixture-token', $this->server->calls[0]['head']);
        $this->assertSame([':rewriteUri', ':fetchFromTrawlerCache'], array_map(static fn (array $call): string => (string) strrchr($call['path'], ':'), $this->server->calls));
        $this->assertSame(['originalUri' => 'https://example.com/page'], $this->server->calls[0]['body']);
        $this->assertSame(['url' => 'https://example.com/page'], $this->server->calls[1]['body']);

        // A page that fits is answered whole, without a file.
        [$short, $shortDetails] = $this->read('https://example.com/short', 60_000);
        $this->assertArrayNotHasKey('fullOutputPath', $shortDetails);
        $this->assertStringNotContainsString('Full page saved', $short);
        $this->assertFalse($shortDetails['partial']);
    }

    public function testARedirectIsFollowedWhenItIsUsableAndRefusedWhenItIsNot(): void
    {
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? ['redirectUri' => '/moved'] : ['content' => self::article('Moved content.')]]);
        [$text] = $this->read('https://example.com/page');
        $this->assertStringContainsString('Requested: https://example.com/page', $text);
        $this->assertStringContainsString('Source: https://example.com/moved (cache)', $text);

        // "A malformed or unsupported destination says nothing about the requested page."
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? ['redirectUri' => 'https://exa mple.com/page'] : ['content' => self::article('Readable.')]]);
        [$kept] = $this->read('https://example.com/page');
        $this->assertStringContainsString('Readable.', self::bodyOf($kept));
        $this->assertStringContainsString('Source: https://example.com/page (cache)', $kept);

        // "A private destination fails closed, before the page is requested."
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? ['redirectUri' => 'http://127.0.0.1/'] : []]);
        $this->assertMatchesRegularExpression('/private or local/', $this->failure('https://example.com/page'));
        $this->assertCount(1, $this->server->calls, 'only the redirect lookup may happen');
    }

    public function testTheCacheNotHavingAPageLeavesTheLiveFetchToRead(): void
    {
        $this->server->handle(static fn (string $path, array $body): array => match (true) {
            self::isRewrite($path) => [200, []],
            ($body['liveFetch'] ?? false) === true => [200, ['response' => ['content' => self::article('Fresh from the site.'), 'contentType' => 'text/html; charset=utf-8']]],
            default => [404, ['error' => ['message' => 'not found in cache']]],
        });

        [$text, $details] = $this->read('https://example.com/new');

        $this->assertStringContainsString('Fresh from the site.', self::bodyOf($text));
        $this->assertStringContainsString('Source: https://example.com/new (live)', $text);
        $this->assertSame(['HTTP 200', 'HTTP 404', 'readable; source complete'], array_column($details['attempts'], 'outcome'));
    }

    // ---- recovery ----------------------------------------------------------------------------

    public function testAWallOrAScriptShellIsRecoveredLiveInsteadOfBeingHandedOver(): void
    {
        $wall = '<!doctype html><html><head><title>Example</title></head><body><main><h1>Please enable JavaScript to continue</h1><p>Checking your browser before accessing example.com.</p></main></body></html>';
        $shell = '<!doctype html><html><head><title>App</title></head><body><div id="root"></div><script src="/app.js"></script><p>Loading…</p></body></html>';

        foreach ([$wall, $shell] as $cached) {
            $this->server->handle(static fn (string $path, array $body): array => [200, self::isRewrite($path) ? [] : ['content' => ($body['liveFetch'] ?? false) ? self::article('The real article.') : $cached]]);
            [$text, $details] = $this->read('https://example.com/page');
            $this->assertStringContainsString('The real article.', self::bodyOf($text));
            $this->assertSame('live', $details['source']);
        }
    }

    public function testAPageAboutAStatusCodeIsAnArticleNotAnAccessWall(): void
    {
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => '<!doctype html><html><head><title>429 Too Many Requests - HTTP | MDN</title></head><body><main><h1>429 Too Many Requests</h1><p>The client has sent too many requests in a given amount of time.</p></main></body></html>']]);

        [$text, $details] = $this->read('https://example.com/429');

        $this->assertSame('readable', $details['assessment']);
        $this->assertStringContainsString('too many requests in a given amount of time', self::bodyOf($text));
    }

    public function testAUsefulCopyIsKeptWhenTheLiveFetchFailsAndTheAnswerSaysSo(): void
    {
        $this->server->handle(static fn (string $path, array $body): array => match (true) {
            self::isRewrite($path) => [200, []],
            ($body['liveFetch'] ?? false) === true => [503, ['error' => ['message' => 'backend unavailable']]],
            default => [200, ['content' => self::cutArticle('Cached article.')]],
        });

        [$text, $details] = $this->read('https://example.com/page');

        $this->assertStringContainsString('Cached article.', self::bodyOf($text));
        $this->assertTrue($details['partial']);
        $this->assertStringContainsString("Note: the crawler's copy ends mid-page, so some content may be missing.", $text);
        $this->assertContains('HTTP 503; retained usable copy', array_column($details['attempts'], 'outcome'));
    }

    public function testASignInRefusalStopsTheReadAtOnce(): void
    {
        $this->server->handle(static fn (string $path): array => self::isRewrite($path) ? [200, []] : [401, ['error' => ['message' => 'Request had invalid credentials. token=ya29.leaked-token']]]);

        $message = $this->failure('https://example.com/page');

        $this->assertStringContainsString('The crawl cache could not answer (HTTP 401:', $message);
        $this->assertStringNotContainsString('leaked-token', $message);
        $this->assertCount(2, $this->server->calls, 'the live fetch was not tried');
    }

    // ---- PDFs ----------------------------------------------------------------------------------

    public function testAPdfIsRefusedWithGuidanceHoweverTheServicePresentsIt(): void
    {
        foreach ([['content' => "preamble\n%PDF-1.7\nbinary"], ['content' => '', 'contentType' => 'application/pdf']] as $answer) {
            $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : $answer]);
            $this->assertMatchesRegularExpression('/does not support PDF content/', $this->failure('https://example.com/download'));
        }

        $this->server->handle(static fn (): array => [200, []]);
        $this->assertMatchesRegularExpression('/Unsupported representation https:\/\/example\.com\/paper\.pdf: This tool does not support PDF content/', $this->failure('https://example.com/paper.pdf'));
        $this->assertSame([], $this->server->calls);

        // "A live PDF behind a usable cached copy keeps the copy and says what happened."
        $this->server->handle(static fn (string $path, array $body): array => [200, self::isRewrite($path) ? [] : ['content' => ($body['liveFetch'] ?? false) ? "%PDF-1.7\nbinary" : self::cutArticle('Cached article.')]]);
        [$text, $details] = $this->read('https://example.com/download');
        $this->assertStringContainsString('Cached article.', self::bodyOf($text));
        $this->assertContains('unsupported PDF content; retained usable copy', array_column($details['attempts'], 'outcome'));
    }

    // ---- other copies of a page ----------------------------------------------------------------

    public function testTheCopyAPageAdvertisesIsUsedAndOnlyASupportedOne(): void
    {
        $links = '<link rel="alternate" type="text/markdown" href="/paper.pdf"><link rel="alternate" type="text/markdown" href="/a.md" hreflang="en">';
        $this->server->handle(static fn (string $path, array $body): array => match (true) {
            self::isRewrite($path) => [200, []],
            ($body['url'] ?? null) === 'https://example.com/a.md' => [200, ['content' => "# Alternate\n\nThe alternate article."]],
            default => [200, ['content' => self::cutArticle('Original article.', $links, 'en-US')]],
        });

        [$text, $details] = $this->read('https://example.com/page');

        $this->assertStringContainsString('The alternate article.', self::bodyOf($text));
        $this->assertSame('https://example.com/a.md', $details['representationUrl']);
        $this->assertStringContainsString('Representation: https://example.com/a.md', $text);
        $this->assertNotContains('https://example.com/paper.pdf', array_map(static fn (array $call): mixed => $call['body']['url'] ?? null, $this->server->calls), 'a PDF alternate is not fetched');
        $this->assertSame([], preg_grep('/alternate language/', array_column($details['attempts'], 'outcome')));
    }

    public function testAnAdvertisedCopyThatRedirectsToAnotherOriginIsNotRead(): void
    {
        $links = '<link rel="alternate" type="text/markdown" href="/a.md">';
        $this->server->handle(static fn (string $path, array $body): array => match (true) {
            self::isRewrite($path) && $body['originalUri'] === 'https://example.com/a.md' => [200, ['redirectUri' => 'https://elsewhere.example/a.md']],
            self::isRewrite($path) => [200, []],
            default => [200, ['content' => self::cutArticle('Original article.', $links)]],
        });

        [$text, $details] = $this->read('https://example.com/page');

        $this->assertStringContainsString('Original article.', self::bodyOf($text));
        $this->assertContains('cross-origin redirect rejected', array_column($details['attempts'], 'outcome'));
        $this->assertNotContains('https://elsewhere.example/a.md', array_map(static fn (array $call): mixed => $call['body']['url'] ?? null, $this->server->calls));
    }

    public function testAWikipediaArticleCutShortIsReadThroughItsParseApiWhenThatNamesTheSameArticle(): void
    {
        // The title goes to the API as it was asked for; the API's answer names the article.
        $api = 'https://en.wikipedia.org/w/api.php?action=parse&page=unix_philosophy&prop=text&format=json&formatversion=2&redirects=1&disableeditsection=1&disabletoc=1';
        $whole = (string) json_encode(['parse' => ['title' => 'Unix philosophy', 'text' => '<p>' . str_repeat('The Unix philosophy is a set of norms. ', 20) . '</p>']]);
        $this->server->handle(static fn (string $path, array $body): array => match (true) {
            self::isRewrite($path) => [200, []],
            ($body['url'] ?? null) === $api => [200, ['content' => $whole, 'contentType' => 'application/json']],
            default => [200, ['content' => '<!doctype html><html><head><title>Unix philosophy - Wikipedia</title></head><body><main><h1>Unix philosophy</h1>']],
        });

        [$text, $details] = $this->read('https://en.wikipedia.org/wiki/unix_philosophy');

        $this->assertStringContainsString('The Unix philosophy is a set of norms.', self::bodyOf($text));
        $this->assertFalse($details['partial'], 'a JSON document that parses is not cut off');
        $this->assertSame($api, $details['representationUrl']);
        // The API's address is built from the page's own, so no redirect is looked up for it.
        $this->assertCount(1, array_filter($this->server->calls, static fn (array $call): bool => self::isRewrite($call['path'])));

        // Another article's text is not this article.
        $other = (string) json_encode(['parse' => ['title' => 'Plan 9', 'text' => '<p>Something else entirely.</p>']]);
        $this->server->handle(static fn (string $path, array $body): array => match (true) {
            self::isRewrite($path) => [200, []],
            ($body['url'] ?? null) === $api => [200, ['content' => $other]],
            default => [200, ['content' => '<!doctype html><html><head><title>T</title></head><body><main><h1>Unix philosophy</h1><p>Opening words.</p>']],
        });
        [$kept, $keptDetails] = $this->read('https://en.wikipedia.org/wiki/unix_philosophy');
        $this->assertStringContainsString('Opening words.', self::bodyOf($kept));
        $this->assertStringNotContainsString('Something else', $kept);
        $this->assertContains('no supported article body', array_column($keptDetails['attempts'], 'outcome'));
    }

    public function testARawGithubFileIsReadWholeFromItsFilePage(): void
    {
        $embedded = (string) json_encode(['payload' => [
            'codeViewBlobLayoutRoute' => ['path' => 'docs/guide.md', 'blob' => ['truncated' => false]],
            'codeViewBlobLayoutRoute.StyledBlob' => ['rawLines' => ['# Guide', '', 'Line one.', 'Line two.']],
        ]]);
        $this->server->handle(static fn (string $path, array $body): array => match (true) {
            self::isRewrite($path) => [200, []],
            ($body['url'] ?? null) === 'https://github.com/acme/tool/blob/main/docs/guide.md' => [200, ['content' => "<html><body><script type=\"application/json\" data-target=\"react-app.embeddedData\">{$embedded}</script></body></html>"]],
            default => [200, ['content' => "# Guide\n\nLine one."]],
        });

        [$text, $details] = $this->read('https://raw.githubusercontent.com/acme/tool/main/docs/guide.md');

        $this->assertSame("# Guide\n\nLine one.\nLine two.", self::bodyOf($text));
        $this->assertFalse($details['partial']);
    }

    // ---- endpoints, deadlines, aborts ----------------------------------------------------------

    public function testAnEndpointThatCannotBeReachedIsPassedOverForTheNextOne(): void
    {
        $dead = stream_socket_server('tcp://127.0.0.1:0');
        $deadUrl = 'http://' . stream_socket_get_name($dead, false);
        fclose($dead);
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => self::article('Read from the second host.')]]);

        $result = Async::run(fn (): array => WebFetch::execute(self::KEY, 'https://example.com/page', endpoints: [$deadUrl, $this->endpoint]));

        $this->assertStringContainsString('Read from the second host.', $result['content']);
        $this->assertSame('transport failed', $result['attempts'][0]['outcome']);
        $this->assertSame($deadUrl, $result['attempts'][0]['endpoint']);
        $this->assertSame($this->endpoint, $result['attempts'][array_key_last($result['attempts'])]['endpoint']);

        // No host at all: one sentence, and nothing asked twice.
        try {
            Async::run(static fn (): array => WebFetch::execute(self::KEY, 'https://example.com/page', endpoints: [$deadUrl]));
            $this->fail('read');
        } catch (WebFetchError $error) {
            $this->assertStringContainsString('No Google endpoint could complete this request', $error->getMessage());
        }
    }

    public function testOneDeadlineBoundsTheWholeReadAndAUsefulCopyReadBeforeItIsKept(): void
    {
        $this->server->handle(static fn (string $path, array $body): array|string => match (true) {
            self::isRewrite($path) => [200, []],
            ($body['liveFetch'] ?? false) === true => 'hang',
            default => [200, ['content' => '<html><body><main><p>Useful partial content.</p>']],
        });

        $started = microtime(true);
        $result = Async::run(fn (): array => WebFetch::execute(self::KEY, 'https://example.com/deadline', maxChars: 10_000, timeoutMs: 150, endpoints: [$this->endpoint]));

        $this->assertStringContainsString('Useful partial content.', $result['content']);
        $this->assertCount(3, $this->server->calls);
        $this->assertLessThan(3.0, microtime(true) - $started);
        $this->assertSame('overall deadline exceeded; retained usable copy', $result['attempts'][2]['outcome']);
        $this->assertStringContainsString('overall deadline exceeded', (string) WebFetch::lastTrace());

        // With nothing in hand, the deadline is the answer.
        $this->server->handle(static fn (): string => 'hang');
        $message = '';

        try {
            Async::run(fn (): array => WebFetch::execute(self::KEY, 'https://example.com/slow', timeoutMs: 100, endpoints: [$this->endpoint]));
        } catch (WebFetchError $error) {
            $message = $error->getMessage();
        }

        $this->assertNotSame('', $message);
    }

    public function testAnAbortedCallAsksNothing(): void
    {
        $controller = new AbortController();
        $controller->abort();

        try {
            Async::run(fn (): array => WebFetch::execute(self::KEY, 'https://example.com/aborted', signal: $controller->signal, endpoints: [$this->endpoint]));
            $this->fail('read');
        } catch (WebFetchError $error) {
            $this->assertMatchesRegularExpression('/aborted/', $error->getMessage());
        }

        $this->assertSame([], $this->server->calls);
    }

    // ---- what is shown ---------------------------------------------------------------------------

    public function testCredentialsInTheAddressAreSentToTheServiceAndNeverShown(): void
    {
        $url = 'https://example.com/page?code=AUTHCODE&foo=ya29.TOKENSECRET#code=FRAGSECRET';
        $this->server->handle(static fn (string $path, array $body): array => [200, self::isRewrite($path) ? [] : ['content' => self::article('Echo ' . $body['url'])]]);

        [$text, $details] = $this->read($url, 4000);
        $visible = $text . json_encode($details);

        $this->assertStringContainsString('AUTHCODE', $this->server->calls[1]['body']['url'], 'the request to Google is not redacted');
        $this->assertStringNotContainsString('AUTHCODE', $visible);
        $this->assertStringNotContainsString('ya29.TOKENSECRET', $visible);
        $this->assertStringNotContainsString('FRAGSECRET', $visible);
        $this->assertStringContainsString('Source: https://example.com/page?code=[redacted]&foo=[redacted-access-token]#code=[redacted] (cache)', $text);
        $this->assertStringContainsString('Echo https://example.com/page?code=[redacted]', self::bodyOf($text), "the page's echo of the address is redacted too");
    }

    public function testALongAddressOrALongFenceCannotPushTheAnswerOverItsBudget(): void
    {
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => self::article('Body.')]]);
        [$text] = $this->read('https://example.com/page?' . str_repeat('q=abcdefghij', 150), 1_000);
        $this->assertLessThanOrEqual(1_000, mb_strlen($text));
        $this->assertStringContainsString('Source: https://example.com/page', $text);
        $this->assertStringContainsString('Body.', self::bodyOf($text));

        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => '```js ' . str_repeat('y', 3_000) . "\nThe rest of the page.\n"]]);
        [$fenced] = $this->read('https://example.com/code', 1_000);
        $this->assertLessThanOrEqual(1_000, mb_strlen($fenced));

        // A fence the cut goes through is closed, so the note after it is not read as code.
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => "# Code\n\n```php\n" . str_repeat("echo 'line';\n", 400) . "```\n"]]);
        putenv('ANTIGRAVITY_WEB_FETCH_MAX_CHARS');
        $result = Async::run(fn (): array => WebFetch::execute(self::KEY, 'https://example.com/long-code', maxChars: 900, endpoints: [$this->endpoint]));
        $shown = WebFetch::formatResult($result);
        $this->assertLessThanOrEqual(900, mb_strlen($shown));
        $this->assertMatchesRegularExpression("/echo 'line';\n+```\n\n… \\[output truncated at 900 characters\\]$/D", $shown);
    }

    public function testABudgetTooSmallToAnswerLeavesNoFileBehind(): void
    {
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => self::article(str_repeat('Long. ', 500))]]);

        try {
            $this->read('https://example.com/page', 120);
            $this->fail('answered');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('The character budget is too small for page provenance.', $error->getMessage());
        }

        $this->assertSame([], glob("{$this->scratch}/pig-url-*") ?: []);
    }

    public function testAPageBeyondTheConversionLimitsIsRefusedWithGooglesCopyKeptAndRawModeReturnsItWhole(): void
    {
        $source = '<!doctype html><html><head><title>T</title></head><body><main>' . str_repeat('<p>text</p>', 190_000) . '</main></body></html>';
        $this->assertGreaterThan(\PigAntigravity\WebFetch\Document::MAX_CHARS, strlen($source));
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => $source]]);

        $raw = Async::run(fn (): array => WebFetch::execute(self::KEY, 'https://example.com/huge', true, 5_000_000, endpoints: [$this->endpoint]));
        $this->assertSame($source, $raw['content']);
        $this->assertStringContainsString('Not converted: The page is too large to convert safely.', $raw['notes'][0]);

        try {
            Async::run(fn (): array => WebFetch::execute(self::KEY, 'https://example.com/huge', maxChars: 5_000_000, endpoints: [$this->endpoint]));
            $this->fail('converted');
        } catch (WebFetchError $error) {
            $this->assertStringContainsString('too large to convert safely', $error->getMessage());
            $this->assertSame($source, $error->unconvertedCopy);
            $told = $error->withSavedCopy();
            $this->assertMatchesRegularExpression("#Google's unconverted HTML \\(" . number_format(strlen($source)) . " characters\\) was saved to: (\\S+/page\\.html)\\. Search it with your file tools\\.$#D", $told);
            preg_match('#saved to: (\S+/page\.html)\.#', $told, $where);
            $this->assertSame($source, file_get_contents($where[1]));
        }
    }

    public function testAResponseTooLargeToReadSafelyIsRefused(): void
    {
        $this->server->handle(static fn (string $path): array => [200, self::isRewrite($path) ? [] : ['content' => str_repeat('x', WebFetch::MAX_RESPONSE_BYTES)]]);

        $this->assertStringContainsString('The fetch response was too large to read safely.', $this->failure('https://example.com/enormous'));
    }

    public function testTheCommandsArgumentsAreAnAddressAndAFlag(): void
    {
        $this->assertSame(['url' => 'https://example.com/a', 'raw' => true], WebFetch::parseFetchCommandArgs('  --raw   https://example.com/a '));
        $this->assertSame(['url' => '', 'raw' => false], WebFetch::parseFetchCommandArgs(''));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function statuses(): iterable
    {
        foreach ([204, 301, 404, 410] as $status) {
            yield "the cache answering {$status} has no copy" => ['cache', $status, 'missing'];
        }

        foreach (['cache', 'live', 'representation'] as $stage) {
            yield "{$stage} 200" => [$stage, 200, 'content'];
            yield "{$stage} 401" => [$stage, 401, 'stop'];
            yield "{$stage} 403" => [$stage, 403, 'stop'];
            yield "{$stage} 429" => [$stage, 429, 'recover'];
            yield "{$stage} 503" => [$stage, 503, 'recover'];
        }

        yield 'a live 404 is the page saying no' => ['live', 404, 'stop'];
    }

    #[DataProvider('statuses')]
    public function testAStatusMeansTheSameAtEveryStageButACacheMiss(string $stage, int $status, string $action): void
    {
        $this->assertSame($action, WebFetch::statusAction($stage, $status));
    }
}
