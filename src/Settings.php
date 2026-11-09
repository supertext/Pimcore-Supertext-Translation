<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle;

use Supertext\PimcoreTranslationBundle\Api\CallableTransport;
use Supertext\PimcoreTranslationBundle\Api\LanguageCodes;
use Supertext\PimcoreTranslationBundle\Api\LanguageMapping;
use Supertext\PimcoreTranslationBundle\Api\SupertextClient;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;
use Supertext\PimcoreTranslationBundle\PluginHealth\InstallationReporter;
use Supertext\PimcoreTranslationBundle\Sdk\Http\HttpTransport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Settings (bundle config + SUPERTEXT_API_KEY / SUPERTEXT_API_URL / SUPERTEXT_REVIEW_URL / SUPERTEXT_VERSION_REPORTS) and the API client.
 */
final class Settings
{
    public const PERMISSION = 'supertext_translate';

    public const SIGNUP_URL = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    /** Appended wherever the API key is missing or rejected (plain text: API and console). */
    public const KEY_HELP = 'No Supertext account yet? Create one at ' . self::SIGNUP_URL . '. '
        . 'Generate your API key at supertext.com → Integrations → API (requires the Admin role): ' . self::API_KEY_URL;

    public const PACKAGE = 'supertext/pimcore-supertext-translation';
    public const RELEASES_URL = 'https://github.com/Supertext/Pimcore-Supertext-Translation/releases/tag/';

    /** The installed version, from Composer (the Git tag of the release), e.g. "1.2.0" or "dev-main". */
    public static function version(): string
    {
        try {
            $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\OutOfBoundsException) {
            $version = null;
        }

        return ltrim((string) ($version ?? 'unknown'), 'v');
    }

    /** Link to the GitHub release for X.Y.Z versions, otherwise null. */
    public static function releaseUrl(string $version): ?string
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) ? self::RELEASES_URL . 'v' . $version : null;
    }

    /** A rejected key (HTTP 401/403) gets the account and API key links. */
    public static function withKeyHelp(SupertextException $e): SupertextException
    {
        return \in_array($e->getCode(), [401, 403], true)
            ? new SupertextException($e->getMessage() . ' ' . self::KEY_HELP, $e->getCode(), $e, $e->key, $e->params, $e->detail)
            : $e;
    }

    public function __construct(
        #[Autowire(param: 'supertext_translation.config')]
        private readonly array $config,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LanguageStore $languageStore = null,
    ) {
    }

    /**
     * An environment variable as Symfony sees it ($_SERVER/$_ENV from .env files, else the process
     * environment); null when not set.
     */
    public static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_scalar($value) && $value !== false ? (string) $value : null;
    }

    public function apiKey(): string
    {
        return SupertextClient::normalizeKey((string) self::env('SUPERTEXT_API_KEY'));
    }

    public function apiKeySource(): string
    {
        return $this->apiKey() !== '' ? 'environment' : '';
    }

    public function baseUrl(): string
    {
        $url = (string) (self::env('SUPERTEXT_API_URL') ?: $this->config['api_url']);

        return SupertextClient::baseUrlFor((string) $this->config['environment'], $url);
    }

    /**
     * The review service for languages with *Translation review* on: SUPERTEXT_REVIEW_URL if set (non-empty),
     * else `review_url` (default Supertext's review service); '' = review off for every language.
     */
    public function reviewUrl(): string
    {
        $env = trim((string) self::env('SUPERTEXT_REVIEW_URL'));

        return $env !== '' ? $env : trim((string) ($this->config['review_url'] ?? SupertextClient::REVIEW_URL));
    }

    /** Whether translations into a Pimcore language go through the review service. */
    public function review(string $language): bool
    {
        return $this->reviewUrl() !== '' && $this->languageMapping()->review($language);
    }

    public function timeout(): int
    {
        return (int) $this->config['timeout'];
    }

    /**
     * The language mapping: the *Supertext → Settings* screen (Pimcore's settings store), overridden by the
     * bundle configuration's `languages.<language>.code` / `.politeness` where they are set.
     */
    public function languageMapping(): LanguageMapping
    {
        return new LanguageMapping((array) ($this->config['languages'] ?? []), $this->languageStore?->load() ?? []);
    }

    /**
     * Pimcore language → Supertext code (the screen's choices and the configuration, non-blank only).
     * Languages in neither use the SDK's exact match or default.
     *
     * @return array<string, string>
     */
    public function mapping(): array
    {
        return $this->languageMapping()->mapping();
    }

    /**
     * Supertext target code for a Pimcore language: the mapping, else the Pimcore code if it is a Supertext
     * code (de_CH -> de-CH, es), else the SDK's default (de -> de-DE, zh_CN -> zh-Hans).
     *
     * @throws SupertextException key `unsupported-language`
     */
    public function languageCode(string $language): string
    {
        return LanguageCodes::target($language, $this->mapping());
    }

    /** The target code, or null for a language without mapping, exact match or default. */
    public function languageCodeOrNull(string $language): ?string
    {
        return LanguageCodes::targetOrNull($language, $this->mapping());
    }

    /**
     * Supertext source code for a Pimcore language (bare: en_GB -> en; a mapped en-US -> en).
     *
     * @throws SupertextException key `unsupported-language`
     */
    public function sourceCode(string $language): string
    {
        return LanguageCodes::source($language, $this->mapping());
    }

    /** The tone: the configuration's, else the settings screen's; "more", "less" or "" (default). */
    public function politeness(string $language): string
    {
        return $this->languageMapping()->politeness($language);
    }

    /**
     * The tone to send for a target: the configured one, "default" if none.
     *
     * @throws SupertextException key `unsupported-feature` when a tone is set but Supertext offers none for the target
     */
    public function politenessFor(string $language, string $target): string
    {
        return LanguageCodes::politeness($target, $this->politeness($language));
    }

    /** "Send version information to Supertext": the `version_reports` setting, SUPERTEXT_VERSION_REPORTS wins. */
    public function versionReports(): bool
    {
        return InstallationReporter::reportsEnabled((bool) ($this->config['version_reports'] ?? true), self::env('SUPERTEXT_VERSION_REPORTS'));
    }

    /** Whether SUPERTEXT_VERSION_REPORTS decides (rather than the `version_reports` setting). */
    public function versionReportsFromEnvironment(): bool
    {
        $env = self::env('SUPERTEXT_VERSION_REPORTS');

        // The variable decides when the setting makes no difference.
        return InstallationReporter::reportsEnabled(true, $env) === InstallationReporter::reportsEnabled(false, $env);
    }

    /** Where version reports go: SUPERTEXT_REPORT_URL (tests), else Plugin Health's default. */
    public function reportUrl(): ?string
    {
        $url = trim((string) self::env('SUPERTEXT_REPORT_URL'));

        return $url !== '' ? $url : null;
    }

    /** @return list<string> */
    public function objectFieldTypes(): array
    {
        return array_values($this->config['object_field_types']);
    }

    /** @return list<string> */
    public function documentEditableTypes(): array
    {
        return array_values($this->config['document_editable_types']);
    }

    /** HTTP through Symfony HttpClient (Pimcore's proxy settings apply), for the SDK's transport. */
    private function send(): \Closure
    {
        $http = $this->httpClient;

        return static function (string $method, string $url, array $headers, ?string $body, ?float $timeout = null) use ($http): array {
            try {
                $options = ['headers' => $headers, 'body' => $body ?? '', 'timeout' => min(60.0, $timeout ?? 60.0)];
                if ($timeout !== null) {
                    $options['max_duration'] = $timeout;
                }
                $response = $http->request($method, $url, $options);
                $out = [];
                foreach ($response->getHeaders(false) as $name => $values) {
                    $out[strtolower($name)] = implode(', ', $values);
                }

                return ['status' => $response->getStatusCode(), 'body' => $response->getContent(false), 'headers' => $out];
            } catch (TransportExceptionInterface $e) {
                throw new SupertextException('The Supertext service could not be reached. ' . $e->getMessage(), 0, $e, 'unreachable', [], $e->getMessage());
            }
        };
    }

    /** The SDK transport for the API key check and (through Api\ReportTransport) the version reports. */
    public function transport(): HttpTransport
    {
        return new CallableTransport($this->send());
    }

    /** The API client; `->forReview($this->review($language))` for a translation into $language. */
    public function client(): SupertextClient
    {
        return new SupertextClient(
            $this->apiKey(),
            $this->baseUrl(),
            $this->send(),
            $this->timeout(),
            (float) $this->config['poll_interval'],
            // The bundle's version too (as in the reports), so Supertext and the review service see which release calls.
            userAgent: SupertextClient::USER_AGENT . '/' . self::version(),
            reviewUrl: $this->reviewUrl(),
        );
    }
}
