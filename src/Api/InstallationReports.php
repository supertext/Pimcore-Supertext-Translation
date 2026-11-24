<?php

/**
 * @package     Supertext Translation for Pimcore
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PimcoreTranslationBundle\Api;

use Supertext\PimcoreTranslationBundle\PluginHealth\CallableKeyChecker;
use Supertext\PimcoreTranslationBundle\PluginHealth\InstallationReporter;
use Supertext\PimcoreTranslationBundle\PluginHealth\KeyCheck;
use Supertext\PimcoreTranslationBundle\PluginHealth\PingResponse;
use Supertext\PimcoreTranslationBundle\PluginHealth\ReporterResult;
use Supertext\PimcoreTranslationBundle\PluginHealth\ReporterState;
use Supertext\PimcoreTranslationBundle\Sdk\Http\HttpTransport;
use Supertext\PimcoreTranslationBundle\Sdk\Supertext;
use Supertext\PimcoreTranslationBundle\Sdk\V1\Client;
use Supertext\PimcoreTranslationBundle\Sdk\V1\Protocol;

/**
 * The bundle's side of the Supertext Commando Center (Commando-Center-Supertext-Translation →
 * docs/DEVELOPER.md → Report protocol): version reports, the automatic API key check and the ping
 * answer. The protocol is Supertext Plugin Health's InstallationReporter (bundled in
 * lib/supertext-plugin-health); the key check is the Supertext SDK's Client::checkApiKey(). This class
 * only loads and stores the reporter's state and builds it with what the bundle knows. Nothing here
 * ever throws.
 *
 * Has no Pimcore or Symfony dependencies (src/Reporting/ supplies the Pimcore parts).
 */
final class InstallationReports
{
    /** The GitHub repository name (the center reads the latest release from it). */
    public const PLUGIN_NAME = 'Pimcore-Supertext-Translation';

    public const SYSTEM_NAME = 'Pimcore';

    /** Path of the public ping route, below the site's base URL. */
    public const PING_PATH = '/supertext/ping';

    public const ON_REQUEST = 'onRequest';
    public const TEST_CONNECTION = 'testConnection';
    public const AUTHENTICATION_FAILED = 'authenticationFailed';
    public const REPORT_CHECK = 'reportCheck';

    /** @var callable(): ?string */
    private $loadState;

    /** @var callable(string): void */
    private $saveState;

    /** @var callable(string): void */
    private $log;

    /**
     * @param callable(): ?string    $loadState the stored JSON state (null: none yet)
     * @param callable(string): void $saveState stores the JSON state
     * @param bool                   $enabled   "Send version information to Supertext" (InstallationReporter::reportsEnabled())
     * @param HttpTransport|null     $transport the SDK transport, for the key check and (wrapped in ReportTransport) the reports
     * @param callable(string): void|null $log  debug messages (never contain the key)
     * @param (\Closure(): float)|null $now     wall-clock Unix time (tests)
     */
    public function __construct(
        callable $loadState,
        callable $saveState,
        public readonly bool $enabled,
        private readonly string $pluginVersion,
        private readonly ?string $systemVersion,
        private readonly ?HttpTransport $transport = null,
        private readonly string $apiBaseUrl = SupertextClient::LIVE,
        private readonly ?string $reportUrl = null,
        ?callable $log = null,
        private readonly ?\Closure $now = null,
    ) {
        $this->loadState = $loadState;
        $this->saveState = $saveState;
        $this->log = $log ?? static function (string $message): void {
        };
    }

    /** The absolute URL of the ping endpoint for a site base URL ("https://example.ch" → "https://example.ch/supertext/ping"). */
    public static function callbackUrl(string $siteUrl): string
    {
        return rtrim($siteUrl, '/') . self::PING_PATH;
    }

    /** The answer of the public ping route: status, headers and body exactly as Plugin Health gives them. */
    public function ping(mixed $challenge): PingResponse
    {
        return InstallationReporter::pingResponse(\is_string($challenge) ? $challenge : null, self::PLUGIN_NAME, $this->enabled);
    }

    /** @param (callable(): list<array{code: string, supertext: ?string}>)|null $languages the language mapping (sendConfiguration only) */
    public function reporter(string $siteUrl, ?callable $languages = null): InstallationReporter
    {
        return new InstallationReporter(
            enabled: $this->enabled,
            siteUrl: rtrim($siteUrl, '/'),
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $this->pluginVersion,
            systemName: self::SYSTEM_NAME,
            keyChecker: new CallableKeyChecker(
                fn (string $apiKey) => (new Client(
                    $apiKey,
                    baseUrl: $this->apiBaseUrl,
                    userAgent: self::PLUGIN_NAME . '/' . $this->pluginVersion,
                    transport: $this->transport,
                    now: $this->now,
                ))->checkApiKey(),
                [Protocol::class, 'apiKeyFingerprint'],
            ),
            systemVersion: $this->systemVersion,
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: self::callbackUrl($siteUrl),
            reportUrl: $this->reportUrl,
            transport: $this->transport === null ? null : new ReportTransport($this->transport),
            now: $this->now,
            languages: $languages,
        );
    }

    /**
     * "Send configuration to Supertext": one `extensions` report with the installed bundles and the
     * language mapping, sent now (also within the hour after a failed report). Nothing with reports
     * off or without a site URL. Never throws.
     *
     * @param list<array{name: string, version?: ?string, active?: ?bool, type?: ?string}> $extensions
     * @param list<array{code: string, supertext: ?string}>                                $languages
     */
    public function sendConfiguration(?string $siteUrl, array $extensions, array $languages): ?ReporterResult
    {
        if ($siteUrl === null || !preg_match('#^https?://[^/]#i', $siteUrl)) {
            return null;
        }
        try {
            $result = $this->reporter($siteUrl, static fn (): array => $languages)->sendExtensions($this->state(), $extensions);
            ($this->saveState)((string) json_encode($result->state, JSON_UNESCAPED_SLASHES));

            return $result;
        } catch (\Throwable $e) {
            ($this->log)('[supertext] configuration not sent: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Runs one reporter action with the stored state and stores the new state. Without a site URL
     * (e.g. a command on a server whose domain isn't set) nothing runs. Never throws.
     *
     * @param string $action one of the constants of this class
     */
    public function run(string $action, #[\SensitiveParameter] string $apiKey, ?string $siteUrl, int $httpStatus = 401): ?ReporterResult
    {
        if ($siteUrl === null || !preg_match('#^https?://[^/]#i', $siteUrl)) {
            ($this->log)('[supertext] version report skipped: no site URL');

            return null;
        }
        try {
            $reporter = $this->reporter($siteUrl);
            $state = $this->state();
            $result = match ($action) {
                self::TEST_CONNECTION => $reporter->testConnection($state, $apiKey),
                self::AUTHENTICATION_FAILED => $reporter->authenticationFailed($state, $apiKey, $httpStatus),
                self::REPORT_CHECK => $reporter->reportCheck($reporter->testConnection($state, $apiKey)->state),
                default => $reporter->onRequest($state, $apiKey),
            };
            ($this->saveState)((string) json_encode($result->state, JSON_UNESCAPED_SLASHES));
            if ($result->event !== null) {
                ($this->log)(sprintf('[supertext] version report %s: %s', $result->event, $result->sent ? 'sent' : 'not sent'));
            }

            return $result;
        } catch (\Throwable $e) {
            ($this->log)('[supertext] version report skipped: ' . $e->getMessage());

            return null;
        }
    }

    /** The stored state (empty when there is none or it can't be read). */
    public function state(): ReporterState
    {
        try {
            return ReporterState::from(($this->loadState)());
        } catch (\Throwable) {
            return ReporterState::empty();
        }
    }

    /** The latest API key check, for the status output. */
    public function lastCheck(): ?KeyCheck
    {
        return $this->state()->keyCheck;
    }

    /**
     * A key check for JSON (without the fingerprint, which stays on the server).
     *
     * @return array{status: string, checkedAt: string, httpStatus: int|null, errorCode: string|null}|null
     */
    public static function checkToArray(?KeyCheck $check): ?array
    {
        return $check === null ? null : ['status' => $check->status, 'checkedAt' => $check->checkedAt, 'httpStatus' => $check->httpStatus, 'errorCode' => $check->errorCode];
    }

    /** The key check in English (the Commando Center's texts), for the console and the log. */
    public static function checkMessage(?KeyCheck $check): string
    {
        return match ($check?->status) {
            null => 'Not checked yet.',
            KeyCheck::OK => 'API key checked: the connection to Supertext works.',
            KeyCheck::INVALID => 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one at supertext.com → Integrations → API (requires the Admin role): https://www.supertext.com/en/integrations/api. No Supertext account yet? Create one at supertext.com: https://www.supertext.com/person/en/account/signin',
            KeyCheck::MISSING => 'No API key is set yet. No Supertext account yet? Create one at supertext.com: https://www.supertext.com/person/en/account/signin. Generate your API key at supertext.com → Integrations → API (requires the Admin role): https://www.supertext.com/en/integrations/api',
            KeyCheck::UNREACHABLE => "The API key couldn't be checked because Supertext can't be reached. The settings are saved; check again later.",
            default => sprintf("The API key couldn't be checked: Supertext answered with an error (%s). The settings are saved; check again later.", $check->httpStatus ?? $check->errorCode ?? '?'),
        };
    }
}
