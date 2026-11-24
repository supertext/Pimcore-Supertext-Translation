<?php

namespace Supertext\PimcoreTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PimcoreTranslationBundle\Api\InstallationReports;
use Supertext\PimcoreTranslationBundle\Api\InstalledExtensions;
use Supertext\PimcoreTranslationBundle\PluginHealth\KeyCheck;
use Supertext\PimcoreTranslationBundle\Sdk\Http\HttpRequest;
use Supertext\PimcoreTranslationBundle\Sdk\Http\HttpResponse;
use Supertext\PimcoreTranslationBundle\Sdk\Http\HttpTransport;
use Supertext\PimcoreTranslationBundle\Sdk\Supertext;

/** The Commando Center glue: state kept, when reports go out, ping answers. Never the live API or center. */
final class InstallationReportsTest extends TestCase
{
    private const SITE = 'https://pimcore.example.ch';
    private const REPORT_URL = 'http://127.0.0.1:9/report';

    /** @var list<HttpRequest> */
    private array $requests = [];
    private ?string $stored = null;
    private int $keyStatus = 200;
    private int $centerStatus = 202;

    private function reports(bool $enabled = true, ?callable $save = null): InstallationReports
    {
        $transport = new class ($this) implements HttpTransport {
            public function __construct(private readonly InstallationReportsTest $test)
            {
            }

            public function send(HttpRequest $request): HttpResponse
            {
                return $this->test->answer($request);
            }
        };

        return new InstallationReports(
            fn (): ?string => $this->stored,
            $save ?? function (string $json): void {
                $this->stored = $json;
            },
            $enabled,
            '1.2.0',
            '2026.3.1',
            $transport,
            'https://api.test/v1/',
            self::REPORT_URL,
            null,
            static fn (): float => 1_792_000_000.0,
        );
    }

    /** @internal the fake Supertext API and center */
    public function answer(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        return str_starts_with($request->url, self::REPORT_URL)
            ? new HttpResponse($this->centerStatus, '{"ok":true}', [])
            : new HttpResponse($this->keyStatus, $this->keyStatus === 200 ? '{"data":[]}' : '{"error_code":"UNAUTHORIZED"}', []);
    }

    /** @return list<array<string, mixed>> */
    private function reportsSent(): array
    {
        $out = [];
        foreach ($this->requests as $request) {
            if ($request->url === self::REPORT_URL) {
                $out[] = json_decode((string) $request->body, true);
            }
        }

        return $out;
    }

    public function testFirstRequestChecksTheKeyAndReportsInstalled(): void
    {
        $result = $this->reports()->run(InstallationReports::ON_REQUEST, 'secret-key-123', self::SITE);

        self::assertNotNull($result);
        self::assertSame('installed', $result->event);
        self::assertTrue($result->sent);
        self::assertSame('ok', $result->check?->status);
        self::assertSame('https://api.test/v1/features', $this->requests[0]->url);

        $report = $this->reportsSent()[0];
        self::assertSame('installed', $report['event']);
        self::assertSame(self::SITE, $report['site_url']);
        self::assertSame(['name' => 'Pimcore-Supertext-Translation', 'version' => '1.2.0'], $report['plugin']);
        self::assertSame(['name' => 'Pimcore', 'version' => '2026.3.1'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version']);
        self::assertSame(['url' => self::SITE . '/supertext/ping'], $report['callback']);
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);
        self::assertStringNotContainsString('secret-key-123', (string) $this->requests[1]->body);
        self::assertLessThanOrEqual(3.0, $this->requests[1]->timeout);

        $state = json_decode((string) $this->stored, true);
        self::assertSame('ok', $state['keyCheck']['status']);
        self::assertStringNotContainsString('secret-key-123', (string) $this->stored);
    }

    public function testAStateStoredByTheSdkReporterIsKept(): void
    {
        // As the Supertext SDK 0.2 reporter stored it: switching to Plugin Health sends no new "installed".
        $this->stored = '{"reportedVersions":"1.2.0|2026.3.1|' . PHP_VERSION . '","reportedKeyStatus":"ok","lastFailureAt":null,'
            . '"keyCheck":{"status":"ok","checkedAt":"2026-10-14T08:00:00Z","httpStatus":200,"errorCode":null,"fingerprint":"' . hash('sha256', 'key') . '"}}';
        $before = $this->stored;

        $result = $this->reports()->run(InstallationReports::ON_REQUEST, 'key', self::SITE);
        self::assertNull($result?->event);
        self::assertSame([], $this->requests);
        // Plugin Health 0.2+ adds keyChecks and reportedKeyStatuses (null with one key); everything else is kept.
        self::assertSame(json_decode($before, true) + ['keyChecks' => null, 'reportedKeyStatuses' => null], json_decode((string) $this->stored, true), 'stored in the same format');
    }

    public function testSendConfigurationSendsBundlesAndTheLanguageMappingNow(): void
    {
        $bundles   = [['name' => 'acme/pimcore-connector', 'version' => '3.0.1', 'active' => true, 'type' => 'bundle']];
        $languages = [['code' => 'en', 'supertext' => 'en-US'], ['code' => 'de_CH', 'supertext' => 'de-CH'], ['code' => 'fr_BE', 'supertext' => null]];

        // Within the hour after a failed report: still sent, the administrator asked for it.
        $this->centerStatus = 503;
        $reports = $this->reports();
        $reports->run(InstallationReports::ON_REQUEST, 'key', self::SITE);
        $this->centerStatus = 202;
        $this->requests = [];

        $result = $reports->sendConfiguration(self::SITE, $bundles, $languages);
        self::assertSame('extensions', $result?->event);
        self::assertTrue($result->sent);
        [$report] = $this->reportsSent();
        self::assertSame('extensions', $report['event']);
        self::assertSame($bundles, $report['extensions']);
        self::assertSame($languages, $report['languages']);

        self::assertNull($reports->sendConfiguration(null, $bundles, $languages), 'no site URL: nothing');
    }

    public function testSendConfigurationSendsNothingWithReportsOff(): void
    {
        self::assertFalse((bool) $this->reports(false)->sendConfiguration(self::SITE, [['name' => 'acme/x']], [['code' => 'de', 'supertext' => 'de-DE']])?->sent);
        self::assertSame([], $this->reportsSent());
    }

    public function testInstalledExtensionsLeaveOutPimcoreAndTheFramework(): void
    {
        $bundles = [
            'FrameworkBundle' => 'Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle',
            'PimcoreCoreBundle' => 'Pimcore\\Bundle\\CoreBundle\\PimcoreCoreBundle',
            'SupertextTranslationBundle' => 'Supertext\\PimcoreTranslationBundle\\SupertextTranslationBundle',
        ];
        $packages = ['Supertext\\PimcoreTranslationBundle\\SupertextTranslationBundle' => ['name' => 'supertext/pimcore-supertext-translation', 'version' => '1.1.0']];

        self::assertSame(
            [['name' => 'supertext/pimcore-supertext-translation', 'version' => '1.1.0', 'active' => true, 'type' => 'bundle']],
            InstalledExtensions::from($bundles, static fn (string $class): ?array => $packages[$class] ?? null),
        );
    }

    public function testLaterRequestsSendNothingUntilSomethingChanges(): void
    {
        $reports = $this->reports();
        $reports->run(InstallationReports::ON_REQUEST, 'key', self::SITE);
        $this->requests = [];

        $result = $reports->run(InstallationReports::ON_REQUEST, 'key', self::SITE);
        self::assertNull($result?->event);
        self::assertSame([], $this->requests, 'same key: no new check, no report');

        $this->keyStatus = 401;
        $result = $reports->run(InstallationReports::ON_REQUEST, 'another-key', self::SITE);
        self::assertSame('api_key_check', $result?->event);
        self::assertSame('invalid', $this->reportsSent()[0]['api_key_check']['status']);
    }

    public function testARejectedKeyIsReportedOnce(): void
    {
        $reports = $this->reports();
        $reports->run(InstallationReports::ON_REQUEST, 'key', self::SITE);
        $this->requests = [];

        self::assertSame('api_key_check', $reports->run(InstallationReports::AUTHENTICATION_FAILED, 'key', self::SITE, 403)?->event);
        self::assertCount(1, $this->requests, 'no request to Supertext, one report');
        self::assertSame(403, $this->reportsSent()[0]['api_key_check']['http_status']);

        self::assertNull($reports->run(InstallationReports::AUTHENTICATION_FAILED, 'key', self::SITE)?->event);
        self::assertSame('invalid', $reports->lastCheck()?->status);
    }

    public function testTestConnectionAndCheckCommand(): void
    {
        $reports = $this->reports();
        self::assertSame('ok', $reports->run(InstallationReports::TEST_CONNECTION, 'key', self::SITE)?->check?->status);
        $this->requests = [];

        $result = $reports->run(InstallationReports::REPORT_CHECK, 'key', self::SITE);
        self::assertSame('check', $result?->event);
        self::assertSame('https://api.test/v1/features', $this->requests[0]->url, 'the command checks the key first');
        self::assertSame('check', $this->reportsSent()[0]['event']);
    }

    public function testOffMeansNoReportsButTheKeyIsStillChecked(): void
    {
        $result = $this->reports(false)->run(InstallationReports::ON_REQUEST, 'key', self::SITE);

        self::assertSame('ok', $result?->check?->status);
        self::assertSame([], $this->reportsSent());
        self::assertSame(404, $this->reports(false)->ping('abcdefghijklmnop')->status);
    }

    public function testAFailedReportIsNotRetriedForAnHour(): void
    {
        $this->centerStatus = 503;
        $reports = $this->reports();
        self::assertFalse($reports->run(InstallationReports::ON_REQUEST, 'key', self::SITE)?->sent);
        $this->requests = [];

        $this->centerStatus = 202;
        $reports->run(InstallationReports::ON_REQUEST, 'key', self::SITE);
        self::assertSame([], $this->reportsSent());
    }

    public function testNothingWithoutASiteUrl(): void
    {
        self::assertNull($this->reports()->run(InstallationReports::ON_REQUEST, 'key', null));
        self::assertNull($this->reports()->run(InstallationReports::ON_REQUEST, 'key', ''));
        self::assertSame([], $this->requests);
    }

    public function testStorageProblemsNeverThrow(): void
    {
        $this->stored = '{broken';
        self::assertNull($this->reports()->lastCheck());

        $failing = $this->reports(true, static function (): void {
            throw new \RuntimeException('database down');
        });
        self::assertNull($failing->run(InstallationReports::ON_REQUEST, 'key', self::SITE));
    }

    public function testPingAnswers(): void
    {
        $ping = $this->reports()->ping('abcdefghijklmnop_-12');
        self::assertSame(200, $ping->status);
        self::assertSame('no-store', $ping->headers['Cache-Control']);
        self::assertSame(['ok' => true, 'plugin' => 'Pimcore-Supertext-Translation', 'challenge' => 'abcdefghijklmnop_-12'], json_decode($ping->body, true));

        self::assertSame(400, $this->reports()->ping('short')->status);
        self::assertSame(400, $this->reports()->ping(['array'])->status);
        self::assertSame(400, $this->reports()->ping(null)->status);
    }

    public function testCheckForTheUi(): void
    {
        $check = new KeyCheck('invalid', '2026-10-14T08:00:00Z', 401, 'UNAUTHORIZED', 'fingerprint');

        self::assertSame(['status' => 'invalid', 'checkedAt' => '2026-10-14T08:00:00Z', 'httpStatus' => 401, 'errorCode' => 'UNAUTHORIZED'], InstallationReports::checkToArray($check));
        self::assertNull(InstallationReports::checkToArray(null));
        self::assertStringContainsString('https://www.supertext.com/en/integrations/api', InstallationReports::checkMessage($check));
        self::assertStringContainsString('(500)', InstallationReports::checkMessage(new KeyCheck('error', '2026-10-14T08:00:00Z', 500)));
        self::assertSame('https://x.ch/supertext/ping', InstallationReports::callbackUrl('https://x.ch/'));
    }
}
