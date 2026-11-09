<?php

namespace Supertext\PimcoreTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PimcoreTranslationBundle\Api\SupertextClient;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;
use Supertext\PimcoreTranslationBundle\LanguageStore;
use Supertext\PimcoreTranslationBundle\Settings;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The unit tests run without Symfony (CI's test job installs nothing): a stand-in for the interface.
if (!interface_exists(HttpClientInterface::class)) {
    eval('namespace Symfony\Contracts\HttpClient; interface HttpClientInterface {}');
}

/** The bundle configuration: language mapping, tone, "Send version information to Supertext". */
final class SettingsTest extends TestCase
{
    private const ENV = ['SUPERTEXT_VERSION_REPORTS', 'SUPERTEXT_REPORT_URL', 'SUPERTEXT_REVIEW_URL'];

    protected function tearDown(): void
    {
        foreach (self::ENV as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }

    /** @param array<string, mixed> $config */
    private function settings(array $config = []): Settings
    {
        return new Settings($config + [
            'environment' => 'live',
            'api_url' => '',
            'timeout' => 180,
            'poll_interval' => 2.0,
            'version_reports' => true,
            'languages' => [
                'en' => ['code' => 'en-US', 'politeness' => ''],
                'de' => ['code' => 'de-CH', 'politeness' => 'more'],
                'en_GB' => ['code' => '', 'politeness' => 'less'],
                'fr_CH' => ['code' => '', 'politeness' => 'more'],
            ],
            'object_field_types' => [],
            'document_editable_types' => [],
        ], $this->createMock(HttpClientInterface::class));
    }

    public function testLanguageMapping(): void
    {
        $settings = $this->settings();

        self::assertSame(['en' => 'en-US', 'de' => 'de-CH'], $settings->mapping());
        self::assertSame('de-CH', $settings->languageCode('de'));
        self::assertSame('fr-CH', $settings->languageCode('fr_CH'), 'exact Supertext code, no mapping needed');
        self::assertSame('en', $settings->sourceCode('en'), 'the mapped en-US is sent as source en');
        self::assertSame('it-IT', $settings->languageCodeOrNull('it'), 'the default since SDK 0.4.0');
        self::assertNull($settings->languageCodeOrNull('fr_BE'));

        $this->expectException(SupertextException::class);
        $settings->languageCode('fr_BE');
    }

    public function testTheSettingsScreenWithTheConfigurationOverridingIt(): void
    {
        $store = new class extends LanguageStore {
            public function load(): array
            {
                return [
                    'en' => ['code' => 'en-GB', 'politeness' => ''],   // the configuration's en-US wins
                    'it' => ['code' => 'it-CH', 'politeness' => 'more'],
                    'fr_BE' => ['code' => 'fr-FR', 'politeness' => 'less'],
                ];
            }
        };
        $settings = new Settings([
            'environment' => 'live', 'api_url' => '', 'timeout' => 180, 'poll_interval' => 2.0, 'version_reports' => true,
            'languages' => ['en' => ['code' => 'en-US', 'politeness' => '']],
            'object_field_types' => [], 'document_editable_types' => [],
        ], $this->createMock(HttpClientInterface::class), $store);

        self::assertSame('en-US', $settings->languageCode('en'));
        self::assertSame('it-CH', $settings->languageCode('it'));
        self::assertSame('more', $settings->politeness('it'));
        self::assertSame('fr-FR', $settings->languageCode('fr_BE'), 'a language without default, mapped on the screen');
        self::assertSame('fr', $settings->sourceCode('fr_BE'));
        self::assertSame('de-DE', $settings->languageCode('de'), 'neither: the SDK default');
    }

    public function testToneOnlyWhereSupertextOffersIt(): void
    {
        $settings = $this->settings();

        self::assertSame('more', $settings->politenessFor('de', 'de-CH'));
        self::assertSame('default', $settings->politenessFor('en', 'en-US'));
        try {
            $settings->politenessFor('en_GB', 'en-GB');
            self::fail('Expected unsupported-feature');
        } catch (SupertextException $e) {
            self::assertSame('unsupported-feature', $e->key);
        }
    }

    public function testVersionReportsAreOnByDefaultAndTheEnvironmentWins(): void
    {
        self::assertTrue($this->settings()->versionReports());
        self::assertFalse($this->settings()->versionReportsFromEnvironment());
        self::assertFalse($this->settings(['version_reports' => false])->versionReports());

        $_SERVER['SUPERTEXT_VERSION_REPORTS'] = '0';
        self::assertFalse($this->settings()->versionReports());
        self::assertTrue($this->settings()->versionReportsFromEnvironment());

        $_SERVER['SUPERTEXT_VERSION_REPORTS'] = 'on';
        self::assertTrue($this->settings(['version_reports' => false])->versionReports());
    }

    public function testReportUrlFromTheEnvironment(): void
    {
        self::assertNull($this->settings()->reportUrl());
        $_ENV['SUPERTEXT_REPORT_URL'] = ' http://127.0.0.1:9/report ';
        self::assertSame('http://127.0.0.1:9/report', $this->settings()->reportUrl());
    }

    public function testTranslationReviewDefaultsToSupertextsReviewService(): void
    {
        $settings = $this->settings(['review_url' => SupertextClient::REVIEW_URL, 'languages' => ['de_CH' => ['code' => '', 'politeness' => '', 'review' => true], 'fr_CH' => ['code' => '', 'politeness' => '', 'review' => null]]]);
        self::assertSame(SupertextClient::REVIEW_URL, $settings->reviewUrl());
        self::assertTrue($settings->review('de_CH'));
        self::assertFalse($settings->review('fr_CH'), 'off by default');

        $_SERVER['SUPERTEXT_REVIEW_URL'] = 'http://127.0.0.1:3000/v1/';
        self::assertSame('http://127.0.0.1:3000/v1/', $settings->reviewUrl(), 'SUPERTEXT_REVIEW_URL wins');

        unset($_SERVER['SUPERTEXT_REVIEW_URL']);
        $off = $this->settings(['review_url' => '', 'languages' => ['de_CH' => ['code' => '', 'politeness' => '', 'review' => true]]]);
        self::assertFalse($off->review('de_CH'), "review_url '': review off for every language");
    }
}
