<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Reporting;

use Pimcore\Model\Tool\SettingsStore;
use Pimcore\Tool;
use Pimcore\Version;
use Psr\Log\LoggerInterface;
use Supertext\PimcoreTranslationBundle\Api\InstallationReports;
use Supertext\PimcoreTranslationBundle\PluginHealth\KeyCheck;
use Supertext\PimcoreTranslationBundle\PluginHealth\PingResponse;
use Supertext\PimcoreTranslationBundle\PluginHealth\ReporterResult;
use Supertext\PimcoreTranslationBundle\Settings;
use Supertext\PimcoreTranslationBundle\Api\InstalledExtensions;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The Pimcore side of the Commando Center (src/Api/InstallationReports.php does the rest):
 *
 * - state: Pimcore's settings store (`settings_store`, scope `supertext_translation`, id `reporter`), JSON;
 * - site URL: Pimcore's host URL of the back-end request (Tool::getHostUrl()); commands, which have no
 *   request, use the one last seen on a request (settings store id `site_url`), else the system
 *   settings' domain;
 * - system: Pimcore and its version (`Pimcore\Version`), plugin version from Composer (Settings::version());
 * - when: later() queues an action for kernel.terminate (after the response is sent) or console.terminate,
 *   so no request waits for Supertext or the center; run() runs it now (Test connection, supertext:check).
 *
 * Nothing here ever throws into a request.
 */
final class Reporting implements EventSubscriberInterface
{
    public const STATE_SCOPE = 'supertext_translation';
    public const STATE_ID = 'reporter';
    public const SITE_URL_ID = 'site_url';

    /** @var array<string, int> action => HTTP status (authenticationFailed) */
    private array $pending = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
        /** @var array<string, string> kernel.bundles */
        #[Autowire('%kernel.bundles%')]
        private readonly array $bundles = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function core(): InstallationReports
    {
        return new InstallationReports(
            static function (): ?string {
                $item = SettingsStore::get(self::STATE_ID, self::STATE_SCOPE);

                return $item === null ? null : (string) $item->getData();
            },
            static function (string $json): void {
                SettingsStore::set(self::STATE_ID, $json, SettingsStore::TYPE_STRING, self::STATE_SCOPE);
            },
            $this->settings->versionReports(),
            Settings::version(),
            self::systemVersion(),
            $this->settings->transport(),
            $this->settings->baseUrl(),
            $this->settings->reportUrl(),
            fn (string $message) => $this->logger->debug($message),
        );
    }

    /** Queues an action for the end of this request or command (several requests for one action run once). */
    public function later(string $action, int $httpStatus = 401): void
    {
        $this->pending[$action] = $httpStatus;
    }

    /** Runs an action now. */
    public function run(string $action, int $httpStatus = 401, ?Request $request = null): ?ReporterResult
    {
        try {
            return $this->core()->run($action, $this->settings->apiKey(), $this->siteUrl($request), $httpStatus);
        } catch (\Throwable $e) {
            $this->logger->debug('[supertext] version report skipped: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * "Send configuration to Supertext" (Supertext → Settings, administrators): the installed bundles
     * (not Pimcore's own) and every Pimcore language with its Supertext code, in one report.
     *
     * @return array{sent: bool, extensions: int, languages: int}|null null with reports off
     */
    public function sendConfiguration(?Request $request = null): ?array
    {
        if (!$this->settings->versionReports()) {
            return null;
        }
        $extensions = InstalledExtensions::from($this->bundles);
        $mapping = $this->settings->languageMapping();
        $languages = [];
        foreach (Tool::getValidLanguages() as $language) {
            $languages[] = ['code' => (string) $language, 'supertext' => $mapping->row((string) $language)['code']];
        }
        $result = $this->core()->sendConfiguration($this->siteUrl($request), $extensions, $languages);

        return ['sent' => (bool) $result?->sent, 'extensions' => \count($extensions), 'languages' => \count($languages)];
    }

    public function onTerminate(object $event): void
    {
        if ($this->pending === []) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        $request = $event instanceof TerminateEvent ? $event->getRequest() : null;
        // A rejected key already includes what onRequest would do.
        if (isset($pending[InstallationReports::AUTHENTICATION_FAILED])) {
            unset($pending[InstallationReports::ON_REQUEST]);
        }
        foreach ($pending as $action => $httpStatus) {
            $this->run($action, $httpStatus, $request);
        }
    }

    public function ping(mixed $challenge): PingResponse
    {
        try {
            return $this->core()->ping($challenge);
        } catch (\Throwable) {
            return new PingResponse(404, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], '{"ok":false}');
        }
    }

    public function lastCheck(): ?KeyCheck
    {
        try {
            return $this->core()->lastCheck();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The site's base URL as Pimcore knows it (no trailing slash), or null. */
    public function siteUrl(?Request $request = null): ?string
    {
        try {
            $request ??= $this->requests->getMainRequest();
            if ($request === null) {
                $stored = SettingsStore::get(self::SITE_URL_ID, self::STATE_SCOPE);
                $url = $stored !== null ? (string) $stored->getData() : '';

                return $url !== '' ? $url : (rtrim(Tool::getHostUrl(), '/') ?: null);
            }
            $url = rtrim(Tool::getHostUrl(null, $request), '/');
            if ($url !== '' && SettingsStore::get(self::SITE_URL_ID, self::STATE_SCOPE)?->getData() !== $url) {
                SettingsStore::set(self::SITE_URL_ID, $url, SettingsStore::TYPE_STRING, self::STATE_SCOPE);
            }

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function systemVersion(): ?string
    {
        try {
            return ltrim(Version::getVersion(), 'v');
        } catch (\Throwable) {
            return null;
        }
    }
}
