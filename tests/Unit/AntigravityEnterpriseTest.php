<?php

declare(strict_types=1);

namespace PigAntigravity\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Context;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\ModelType;
use Pig\Ai\Models;
use Pig\Ai\ProviderError;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use PigAntigravity\AntigravityApi;
use PigAntigravity\Enterprise;
use PigAntigravity\ImageGenerator;

/** Gemini Enterprise routing: pi-antigravity 0.13's `client/enterprise.ts`. */
final class AntigravityEnterpriseTest extends TestCase
{
    use \PigAntigravity\Test\LoadsAntigravity;

    private const array VARIABLES = ['ANTIGRAVITY_LOCATION', 'ANTIGRAVITY_ENTERPRISE', 'ANTIGRAVITY_ENTERPRISE_PROJECT', 'ANTIGRAVITY_USER_TIER', 'GOOGLE_CLOUD_LOCATION'];

    private string $cli;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        AntigravityApi::forgetRequestNonces();
        $this->cli = sys_get_temp_dir() . '/pig-ag-enterprise-' . bin2hex(random_bytes(4)) . '.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $variable) {
            putenv($variable);
        }

        \Pig\Ai\Extension\ProviderRegistry::forget();
        Models::forgetRegistered();

        if (is_file($this->cli)) {
            unlink($this->cli);
        }
    }

    public function testWithoutARegionalLocationRequestsStayOnTheConsumerPath(): void
    {
        $this->assertNull(Enterprise::resolve($this->cli));
        $this->assertSame('consumer', Enterprise::describe($this->cli));

        // `global` alone asks for nothing either: that is where the consumer path already is.
        putenv('ANTIGRAVITY_LOCATION=global');
        $this->assertNull(Enterprise::resolve($this->cli));
    }

    public function testARegionalLocationGoesToThatLocationsHostWithTheLicensesProjectAndTier(): void
    {
        putenv('ANTIGRAVITY_LOCATION=EU');
        putenv('ANTIGRAVITY_ENTERPRISE_PROJECT=acme-code-1234');
        putenv('ANTIGRAVITY_USER_TIER=enterprise-standard');

        $target = Enterprise::resolve($this->cli);

        $this->assertNotNull($target);
        $this->assertSame(['eu', 'acme-code-1234', 'enterprise-standard', 'https://businessaicode.eu.rep.googleapis.com'], [$target->location, $target->projectId, $target->userTier, $target->host]);
        $this->assertSame('https://businessaicode.eu.rep.googleapis.com/v1beta/projects/acme-code-1234/locations/eu:streamGenerateContent?alt=sse', $target->streamUrl());
        $this->assertSame('enterprise location=eu host=https://businessaicode.eu.rep.googleapis.com project=acme-code-1234', Enterprise::describe($this->cli));
        $this->assertSame('https://businessaicode.googleapis.com', Enterprise::host('global'));
    }

    public function testTheOfficialClisSettingsCanNameTheLicenseAndTheEnvironmentWinsOverThem(): void
    {
        file_put_contents($this->cli, (string) json_encode(['selectedLicense' => ['location' => 'us', 'projectId' => 'from-license-01', 'tier' => 'tier.one'], 'project' => 'from-settings-01']));

        $target = Enterprise::resolve($this->cli);
        $this->assertSame(['us', 'from-settings-01', 'tier.one'], [$target?->location, $target?->projectId, $target?->userTier]);

        putenv('ANTIGRAVITY_USER_TIER=from-env');
        $this->assertSame('from-env', Enterprise::resolve($this->cli)?->userTier);
    }

    public function testForcedWithoutALocationIsTheCloudLocationOrGlobal(): void
    {
        putenv('ANTIGRAVITY_ENTERPRISE=yes');
        putenv('ANTIGRAVITY_ENTERPRISE_PROJECT=acme-code-1234');
        putenv('ANTIGRAVITY_USER_TIER=t1');

        $this->assertSame('https://businessaicode.googleapis.com', Enterprise::resolve($this->cli)?->host);

        putenv('GOOGLE_CLOUD_LOCATION=us');
        $this->assertSame('us', Enterprise::resolve($this->cli)?->location);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function incomplete(): iterable
    {
        yield 'a location that is none of the three' => [['ANTIGRAVITY_LOCATION' => 'asia'], 'ANTIGRAVITY_LOCATION must be global, us, or eu. The prompt was not sent.'];
        yield 'no project' => [['ANTIGRAVITY_LOCATION' => 'eu', 'ANTIGRAVITY_USER_TIER' => 't1'], "Gemini Enterprise routing needs ANTIGRAVITY_ENTERPRISE_PROJECT set to the license's Google Cloud project id. Refusing to send this prompt to the consumer endpoint."];
        yield 'a project that is not a project id' => [['ANTIGRAVITY_LOCATION' => 'eu', 'ANTIGRAVITY_ENTERPRISE_PROJECT' => 'Acme/../x', 'ANTIGRAVITY_USER_TIER' => 't1'], "Gemini Enterprise routing needs ANTIGRAVITY_ENTERPRISE_PROJECT set to the license's Google Cloud project id. Refusing to send this prompt to the consumer endpoint."];
        yield 'no tier' => [['ANTIGRAVITY_LOCATION' => 'us', 'ANTIGRAVITY_ENTERPRISE_PROJECT' => 'acme-code-1234'], 'Gemini Enterprise routing needs ANTIGRAVITY_USER_TIER from the selected license (for example the tier id shown by agy). Refusing to send this prompt to the consumer endpoint.'];
    }

    /** @param array<string, string> $environment */
    #[\PHPUnit\Framework\Attributes\DataProvider('incomplete')]
    public function testAnIncompleteConfigurationSendsNothingAnywhere(array $environment, string $message): void
    {
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
        }

        try {
            Enterprise::resolve($this->cli);
            $this->fail('resolved');
        } catch (ProviderError $error) {
            $this->assertSame($message, $error->getMessage());
        }

        $this->assertSame("enterprise misconfigured ({$message})", Enterprise::describe($this->cli));

        // And the turn fails with it, the consumer endpoint never asked.
        $consumer = new CannedServer();
        $url = rtrim($consumer->start(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]), '/');
        $model = new \Pig\Ai\Model('gemini-3-flash', 'Gemini 3 Flash', \Pig\Ai\Api::Extension, 'antigravity', $url, 1_048_576, 65_536, true, ['text', 'image'], new \Pig\Ai\Pricing());

        $failure = Async::run(static function () use ($model, $url): ?string {
            $stream = (new AntigravityApi(fallback: $url))->stream(
                $model,
                Transcript::normalizeContext(new Context([new UserMessage('a secret prompt')])),
                new GoogleOptions(apiKey: (string) json_encode(['token' => 'ya29.a', 'projectId' => 'proj-1'])),
            );

            foreach ($stream as $ignored) {
                // Drained for its result.
            }

            return $stream->result()->await()->errorMessage;
        });

        $this->assertSame($message, $failure);
        $this->assertSame(0, $consumer->connections());
    }

    public function testTheEnterpriseBodyKeepsTheGeminiFieldsAndDropsTheConsumerEnvelope(): void
    {
        putenv('ANTIGRAVITY_LOCATION=eu');
        putenv('ANTIGRAVITY_ENTERPRISE_PROJECT=acme-code-1234');
        putenv('ANTIGRAVITY_USER_TIER=enterprise-standard');
        $target = Enterprise::resolve($this->cli);
        self::assertNotNull($target);

        $contents = [['role' => 'user', 'parts' => [['text' => 'hi']]]];
        $body = $target->generateBody([
            'project' => 'acme-code-1234',
            'model' => 'gemini-3.8-flash-medium',
            'request' => ['contents' => $contents, 'systemInstruction' => ['parts' => [['text' => 's']]], 'generationConfig' => ['temperature' => 0.2], 'labels' => ['model_enum' => 'M'], 'sessionId' => '-42'],
            'requestType' => 'agent',
            'userAgent' => 'antigravity',
            'requestId' => 'agent/x',
            'enabledCreditTypes' => ['GOOGLE_ONE_AI'],
        ]);

        $this->assertSame([
            'contents' => $contents,
            'aicode' => ['experience' => 'gemini-3.8-flash-medium'],
            'entitlement' => ['userTier' => 'enterprise-standard'],
            'systemInstruction' => ['parts' => [['text' => 's']]],
            'generationConfig' => ['temperature' => 0.2],
            'labels' => ['model_enum' => 'M'],
        ], $body);
    }

    public function testALicenseTheConsumerEndpointRefusesIsSaidWithTheWayOut(): void
    {
        $this->assertSame(
            "This account's Gemini Enterprise license was not accepted on the consumer endpoint. Next: set ANTIGRAVITY_LOCATION to eu, us, or global, plus ANTIGRAVITY_ENTERPRISE_PROJECT and ANTIGRAVITY_USER_TIER from the license selector, then retry. A regional location is not sent to cloudcode-pa.",
            AntigravityApi::friendlyAntigravityError(403, '{"error":{"message":"You do not have a valid license of this product. (#3501)"}}'),
        );
    }

    public function testNoImageIsGeneratedWhileEnterpriseRoutingIsOn(): void
    {
        putenv('ANTIGRAVITY_LOCATION=eu');
        putenv('ANTIGRAVITY_ENTERPRISE_PROJECT=acme-code-1234');
        putenv('ANTIGRAVITY_USER_TIER=enterprise-standard');
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
        self::registerAntigravity(images: new ImageGenerator(endpoint: $url));
        $model = Models::findOfType(ModelType::Image, 'antigravity', ImageGenerator::DEFAULT_MODEL);
        self::assertInstanceOf(\Pig\Ai\ImageModel::class, $model);

        $result = Async::run(static fn () => Models::generateImages($model, new ImagesContext([new TextContent('a cat')]), new ImagesOptions(apiKey: (string) json_encode(['token' => 'ya29.a', 'projectId' => 'proj-1']))));

        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame('Image generation is not sent while Gemini Enterprise routing is active (location=eu). Google documents image generation on the global deployment only, and this extension does not upload prompts or reference images to a second host.', $result->errorMessage);
        $this->assertSame(0, $server->connections());
    }
}
