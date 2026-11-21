<?php

declare(strict_types=1);

namespace PigAntigravity\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use PigAntigravity\DailyLimitedTransport;
use PigAntigravity\DailyLimitReached;
use PigAntigravity\DailyRateLimits;

/**
 * The daily limit of 429s an account may receive (`DailyRateLimits`), pig's own: the developer
 * asked for it so that a run of refusals cannot get an account suspended.
 */
final class DailyLimitsTest extends TestCase
{
    private string $counts;

    private string $settings;

    private string $today = '2026-11-21';

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->counts = sys_get_temp_dir() . '/pa-429-' . bin2hex(random_bytes(4)) . '.json';
        $this->settings = sys_get_temp_dir() . '/pa-settings-' . bin2hex(random_bytes(4)) . '.json';
        DailyRateLimits::useFiles($this->counts, $this->settings, fn (): string => $this->today);
    }

    #[\Override]
    protected function tearDown(): void
    {
        DailyRateLimits::useFiles(null);
        DailyRateLimits::useAccounts(null);

        foreach ([$this->counts, $this->settings] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheLimitIsFiveUnlessThePersonsSettingsSayOtherwise(): void
    {
        $this->assertSame(5, DailyRateLimits::limit());

        $this->settle(['antigravity' => ['daily429Limit' => 2]]);
        $this->assertSame(2, DailyRateLimits::limit());

        foreach ([0, -1, '7', 2.5, null] as $unusable) {
            $this->settle(['antigravity' => ['daily429Limit' => $unusable]]);
            $this->assertSame(5, DailyRateLimits::limit(), var_export($unusable, true));
        }
    }

    public function testEvery429IsCountedPerAccountAndTheLimitHoldsTheNextRequestBack(): void
    {
        $this->settle(['antigravity' => ['daily429Limit' => 2]]);
        DailyRateLimits::useAccounts(static fn (string $token): ?string => ['ya29.a' => 'acct-a', 'ya29.b' => 'acct-b'][$token] ?? null);
        $server = new CannedServer();
        $refusal = "HTTP/1.1 429 Too Many Requests\r\nContent-Length: 2\r\n\r\n{}";
        $ok = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}";
        $url = $server->startSequence([[$refusal], [$refusal], [$ok], [$ok]]);
        $transport = new DailyLimitedTransport(new HttpClient(5.0));
        $send = static fn (string $token): int => Async::run(static fn (): int => $transport->send(new Request('POST', $url, ['Authorization' => "Bearer {$token}"], '{}'))->status);

        $this->assertSame(429, $send('ya29.a'));
        $this->assertSame(429, $send('ya29.a'));
        $this->assertTrue(DailyRateLimits::exceeded('acct-a'));

        try {
            $send('ya29.a');
            $this->fail('the third request on acct-a was sent');
        } catch (DailyLimitReached $reached) {
            $this->assertSame([2, 2], [$reached->count, $reached->limit]);
        }

        $this->assertSame(2, $server->connections(), 'held back before it left the machine');

        // Another account is counted apart, and a 200 counts nothing.
        $this->assertSame(200, $send('ya29.b'));
        $this->assertSame(0, DailyRateLimits::count('acct-b'));
        $this->assertFalse(DailyRateLimits::exceeded('acct-b'));
    }

    public function testARenewedTokenOfTheSameAccountKeepsItsCount(): void
    {
        DailyRateLimits::useAccounts(static fn (string $token): ?string => str_starts_with($token, 'ya29.') ? 'acct-a' : null);

        $this->assertSame('acct-a', DailyRateLimits::accountOf(new Request('GET', 'https://x', ['authorization' => 'Bearer ya29.first'])));
        $this->assertSame('acct-a', DailyRateLimits::accountOf(new Request('GET', 'https://x', ['Authorization' => 'Bearer ya29.renewed'])));
        // One the store does not know is counted under its own fingerprint, never under nobody.
        $this->assertStringStartsWith('token-', (string) DailyRateLimits::accountOf(new Request('GET', 'https://x', ['Authorization' => 'Bearer other'])));
        // The token exchange carries no bearer token: it is not an account's request.
        $this->assertNull(DailyRateLimits::accountOf(new Request('POST', 'https://oauth2.googleapis.com/token', [], 'grant_type=refresh_token')));
    }

    public function testTheCountStartsOverOnTheNextDay(): void
    {
        DailyRateLimits::record('acct-a');
        DailyRateLimits::record('acct-a');
        $this->assertSame(2, DailyRateLimits::count('acct-a'));

        $this->today = '2026-11-22';
        $this->assertSame(0, DailyRateLimits::count('acct-a'));
        DailyRateLimits::record('acct-a');
        $this->assertSame(['date' => '2026-11-22', 'counts' => ['acct-a' => 1]], json_decode((string) file_get_contents($this->counts), true));
    }

    public function testTheRefusalTellsThePersonAndIsNotSentAgainByTheSession(): void
    {
        $message = (new DailyLimitReached(5, 5))->getMessage();

        $this->assertStringContainsString('antigravity.daily429Limit', $message);
        $this->assertFalse(\Pig\Ai\Utils\Retry::isRetryableAssistantError(new \Pig\Ai\AssistantMessage([], \Pig\Ai\Api::Extension, 'antigravity', 'm', new \Pig\Ai\Usage(), \Pig\Ai\StopReason::Error, $message)), $message);
    }

    public function testNoClientInTheExtensionGoesRoundTheLimit(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/extensions/pig-antigravity.php'];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with((string) $file, '.php')) {
                $files[] = (string) $file;
            }
        }

        $offenders = [];

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $bare = preg_match_all('/new HttpClient\(/', $code) - preg_match_all('/new DailyLimitedTransport\(new HttpClient\(/', $code);

            if ($bare > 0 || preg_match('/(?<![\w\\\\])\??HttpClient \$|\): \??HttpClient\b/', $code) === 1) {
                $offenders[] = substr($file, strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders, 'Every client is `new DailyLimitedTransport(new HttpClient(…))`, and is typed HttpTransport.');
    }

    /** @param array<string, mixed> $settings */
    private function settle(array $settings): void
    {
        file_put_contents($this->settings, (string) json_encode($settings));
    }
}
