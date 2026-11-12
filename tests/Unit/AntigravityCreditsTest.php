<?php

declare(strict_types=1);

namespace PigAntigravity\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Context;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Settings;
use Pig\Test\CannedServer;
use PigAntigravity\AntigravityApi;
use PigAntigravity\Credits;
use PigAntigravity\QuotaClient;

/** Google One AI credit overages: pi-antigravity 0.13's `utils/credits.ts` and what reads it. */
final class AntigravityCreditsTest extends TestCase
{
    use \PigAntigravity\Test\LoadsAntigravity;

    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        AntigravityApi::forgetRequestNonces();
        $this->dir = sys_get_temp_dir() . '/pig-ag-credits-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('ANTIGRAVITY_USE_CREDITS');
        putenv('NOAGY_USE_G1_CREDITS');
        putenv('USE_G1_CREDITS');
        \Pig\Ai\Extension\ProviderRegistry::forget();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testOveragesAreOffUntilSomethingOfThePersonsTurnsThemOn(): void
    {
        $pig = "{$this->dir}/settings.json";
        $cli = "{$this->dir}/cli.json";

        $this->assertSame(['enabled' => false, 'source' => 'default', 'detail' => 'Default (disabled; enable with /antigravity.credits on)'], Credits::status($pig, $cli));

        // The official CLI's file, either spelling; then pig's own, which is asked before it.
        file_put_contents($cli, '{"useAiCredits": true}');
        $this->assertSame(['enabled' => true, 'source' => 'antigravity_settings', 'detail' => '~/.gemini/antigravity-cli/settings.json (useG1Credits: true)'], Credits::status($pig, $cli));

        file_put_contents($pig, '{"antigravity": {"useCredits": false}}');
        $this->assertSame(['enabled' => false, 'source' => 'pig_settings', 'detail' => '~/.pig/agent/settings.json (antigravity.useCredits: false)'], Credits::status($pig, $cli));

        file_put_contents($pig, '{"useG1Credits": true, "antigravity": {"useCredits": false}}');
        $this->assertSame('~/.pig/agent/settings.json (useG1Credits: true)', Credits::status($pig, $cli)['detail']);

        // The environment before both; a word that is neither yes nor no decides nothing.
        putenv('ANTIGRAVITY_USE_CREDITS=Off');
        $this->assertSame(['enabled' => false, 'source' => 'env', 'detail' => 'Environment variable (ANTIGRAVITY_USE_CREDITS=Off)'], Credits::status($pig, $cli));
        putenv('ANTIGRAVITY_USE_CREDITS=maybe');
        $this->assertSame('pig_settings', Credits::status($pig, $cli)['source']);
        putenv('ANTIGRAVITY_USE_CREDITS');
        putenv('USE_G1_CREDITS=yes');
        $this->assertTrue(Credits::status("{$this->dir}/none.json", "{$this->dir}/none.json")['enabled']);
    }

    public function testAFileThatIsNotASettingsObjectTurnsNothingOn(): void
    {
        file_put_contents("{$this->dir}/settings.json", '{"useG1Credits": true');
        file_put_contents("{$this->dir}/cli.json", '[true]');

        $this->assertFalse(Credits::status("{$this->dir}/settings.json", "{$this->dir}/cli.json")['enabled']);
    }

    public function testTurningThemOnWritesThePersonsSettingsAndTheClisOnlyWhereItAlreadyIs(): void
    {
        $settings = Settings::load($this->dir, $this->dir);
        $cli = "{$this->dir}/cli.json";

        Credits::set($settings, true, $cli);

        $this->assertTrue($settings->get('useG1Credits'));
        $this->assertFileDoesNotExist($cli, 'never created');
        $this->assertTrue(Credits::status("{$this->dir}/settings.json", $cli)['enabled']);

        file_put_contents($cli, '{"theme": "dark", "useG1Credits": true}');
        Credits::set($settings, false, $cli);
        $this->assertSame(['theme' => 'dark', 'useG1Credits' => false], json_decode((string) file_get_contents($cli), true));

        file_put_contents($cli, '{broken');
        Credits::set($settings, true, $cli);
        $this->assertSame('{broken', file_get_contents($cli), 'never overwritten after a parse error');
    }

    public function testARequestOffersCreditsOnlyWhenOveragesAreOn(): void
    {
        self::registerAntigravity();

        $this->assertArrayNotHasKey('enabledCreditTypes', $this->sent());

        putenv('ANTIGRAVITY_USE_CREDITS=1');
        $this->assertSame(['GOOGLE_ONE_AI'], $this->sent()['enabledCreditTypes']);
    }

    public function testAQuotaWallSaysWhereCreditsStandAndAnEmptyBalanceIsItsOwnSentence(): void
    {
        $wall = '{"error":{"message":"Individual quota reached. Resets in 3h."}}';
        $this->assertSame('Quota reached. Please wait 3h. (Enable AI credits fallback with /antigravity.credits on) Next: switch models or try again after reset.', AntigravityApi::friendlyAntigravityError(429, $wall));
        $this->assertSame('Quota reached. (Enable AI credits fallback with /antigravity.credits on) Next: switch models or retry later.', AntigravityApi::friendlyAntigravityError(429, 'You have exceeded your daily limit'));

        putenv('ANTIGRAVITY_USE_CREDITS=1');
        $this->assertSame('Quota reached. Please wait 3h. (AI credits overage active; credit balance may be depleted) Next: switch models or try again after reset.', AntigravityApi::friendlyAntigravityError(429, $wall));

        $empty = 'Your AI credits balance is exhausted. Please refill credits in Google One or wait for your regular quota to reset. Next: switch models or retry later.';
        $this->assertSame($empty, AntigravityApi::friendlyAntigravityError(429, '{"error":{"message":"AI credits balance is too low"}}'));
        $this->assertSame($empty, AntigravityApi::friendlyAntigravityError(429, 'INSUFFICIENT_G1_CREDITS'));
        // A wall for the request loop: no other endpoint, no sibling tier.
        $this->assertTrue(AntigravityApi::isQuotaWall('INSUFFICIENT_G1_CREDITS'));
    }

    public function testBalancesAreReadUnderEitherSpellingAndOnlyWhereThereIsAnAmount(): void
    {
        $this->assertSame([
            ['creditType' => 'GOOGLE_ONE_AI', 'creditAmount' => 1250.5, 'minimumCreditAmountForUsage' => 10.0],
            ['creditType' => 'OTHER', 'creditAmount' => 3.0, 'minimumCreditAmountForUsage' => null],
        ], QuotaClient::parseCredits([
            ['creditAmount' => '1250.5', 'minimum_credit_amount_for_usage' => 10],
            ['credit_type' => 'OTHER', 'credit_amount' => 3],
            ['creditType' => 'NO_AMOUNT'],
            ['creditAmount' => 'lots'],
            'not an entry',
        ]));
        $this->assertNull(QuotaClient::parseCredits([]));
        $this->assertNull(QuotaClient::parseCredits('nope'));
    }

    public function testTheBalanceIsThePaidTiersThenTheCurrentTiersThenTheAnswersOwn(): void
    {
        $ask = static function (array $answer): array {
            $server = new CannedServer();
            $body = (string) json_encode($answer);
            $url = $server->start(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

            return Async::run(static fn (): array => (new QuotaClient(endpoint: rtrim($url, '/')))->fetchRemainingCredits('ya29.a', 'proj-1'));
        };

        $this->assertSame(
            ['availableCredits' => [['creditType' => 'GOOGLE_ONE_AI', 'creditAmount' => 40.0, 'minimumCreditAmountForUsage' => null]], 'planLabel' => 'Google AI Ultra (g1-ultra-tier)'],
            $ask([
                'currentTier' => ['id' => 'free-tier', 'name' => 'Free', 'availableCredits' => [['creditAmount' => 1]]],
                'paidTier' => ['id' => 'g1-ultra-tier', 'name' => 'Google AI Ultra', 'availableCredits' => [['creditAmount' => 40]]],
            ]),
        );
        $this->assertSame(
            ['availableCredits' => [['creditType' => 'GOOGLE_ONE_AI', 'creditAmount' => 7.0, 'minimumCreditAmountForUsage' => null]], 'planLabel' => 'Free (free-tier)'],
            $ask(['currentTier' => ['id' => 'free-tier', 'name' => 'Free'], 'available_credits' => [['credit_amount' => 7]]]),
        );
        $this->assertSame(['availableCredits' => null, 'planLabel' => null], $ask([]));
    }

    /** @return array<string, mixed> the envelope of one request */
    private function sent(): array
    {
        AntigravityApi::forgetRequestNonces();
        $server = new CannedServer();
        $chunk = 'data: ' . json_encode(['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]) . "\n\n";
        $url = $server->start(["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n", sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk), "0\r\n\r\n"]);
        $model = new \Pig\Ai\Model('gemini-3-flash', 'Gemini 3 Flash', \Pig\Ai\Api::Extension, 'antigravity', rtrim($url, '/'), 1_048_576, 65_536, true, ['text', 'image'], new \Pig\Ai\Pricing());

        Async::run(static function () use ($model, $url): void {
            $stream = (new AntigravityApi(fallback: rtrim($url, '/')))->stream(
                $model,
                Transcript::normalizeContext(new Context([new UserMessage('hi')])),
                new GoogleOptions(apiKey: (string) json_encode(['token' => 'ya29.a', 'projectId' => 'proj-1'])),
            );

            foreach ($stream as $ignored) {
                // What is under test is what went out.
            }
        });

        return $server->receivedJson();
    }
}
