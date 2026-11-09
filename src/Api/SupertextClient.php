<?php

/**
 * @package     Supertext Translation for Pimcore
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PimcoreTranslationBundle\Api;

use Supertext\PimcoreTranslationBundle\Sdk\V1\ApiKeyCheck;
use Supertext\PimcoreTranslationBundle\Sdk\V1\Client;
use Supertext\PimcoreTranslationBundle\Sdk\V1\HtmlDocument;
use Supertext\PimcoreTranslationBundle\Sdk\V1\Protocol;
use Supertext\PimcoreTranslationBundle\Sdk\V1\Segment;
use Supertext\PimcoreTranslationBundle\Sdk\V1\SupertextException as SdkException;

/**
 * Supertext AI file translation API v1 (https://api.supertext.com/v1/), through the
 * Supertext PHP SDK bundled in lib/supertext-sdk (namespace Supertext\PimcoreTranslationBundle\Sdk\V1).
 *
 * The SDK does the protocol (upload one HTML document, poll its status, download the
 * translation, delete the file), the auth header, HTTP 429 retries and the data-st-id
 * document. Language codes are resolved before (LanguageCodes): the SDK sends them as given. This class keeps the bundle's interface: HTTP goes through a
 * transport callable (Symfony HttpClient, or a fake in tests), and SDK errors come out
 * as this bundle's SupertextException with a key the Studio UI translates.
 *
 * Has no Pimcore dependencies.
 */
final class SupertextClient
{
    public const LIVE = Protocol::LIVE_BASE_URL;
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    /**
     * Supertext's review service (translation proxy of Supertext Localization Review). It speaks the
     * same API and forwards to the live Supertext API with the same key, keeping every translation so
     * reviewers can correct them on the website. Used for languages with *Translation review* on.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    /** The User-Agent's name; the version follows it (`Pimcore-Supertext-Translation/1.2.0`). */
    public const USER_AGENT = 'Pimcore-Supertext-Translation';

    /** Stay well below the API's 1,000,000 character limit per document. */
    public const MAX_DOCUMENT_CHARACTERS = Protocol::DEFAULT_MAX_CHARACTERS;

    /**
     * SDK error code → [key, English message]. The Studio UI shows `supertext.error.<key>`
     * (translations/studio.*.yaml) instead of the message.
     */
    private const KEYS = [
        SdkException::MISSING_API_KEY => ['no-api-key', 'No Supertext API key is configured.'],
        SdkException::AUTHENTICATION_FAILURE => ['auth-failed', 'Authentication failed. Please check the Supertext API key.'],
        SdkException::INVALID_LANGUAGE_PAIR => ['invalid-language-pair', 'Supertext does not support this language pair (%s → %s).'],
        SdkException::UNSUPPORTED_LANGUAGE => ['unsupported-language', 'The language %s has no Supertext language yet. Ask your administrator to choose one in Pimcore Studio: Supertext → Settings.'],
        SdkException::UNSUPPORTED_FEATURE => ['unsupported-feature', 'Supertext offers no formal or informal tone for this language. Remove the politeness setting for it (supertext_translation.languages.<language>.politeness).'],
        SdkException::NOT_FOUND => ['not-found', 'The requested Supertext resource was not found.'],
        SdkException::PAYLOAD_TOO_LARGE => ['too-large', 'The content is too large for Supertext to translate in one go.'],
        SdkException::TOO_MANY_REQUESTS => ['rate-limited', 'Too many requests to Supertext. Please try again shortly.'],
        SdkException::SERVICE_UNAVAILABLE => ['unavailable', 'The Supertext service is currently unavailable.'],
        SdkException::UNEXPECTED_STATUS => ['http-status', 'Supertext answered with HTTP %s.'],
        SdkException::TRANSPORT_ERROR => ['unreachable', 'The Supertext service could not be reached.'],
        SdkException::NO_FILE_ID => ['no-file-id', 'Supertext did not return a file id.'],
        SdkException::TRANSLATION_ERROR => ['failed', 'Supertext could not translate the document.'],
        SdkException::QUOTA_EXCEEDED => ['limit-exceeded', 'Your Supertext translation limit is exceeded. Please upgrade your subscription.'],
        SdkException::FILE_DELETED => ['deleted', 'The document was deleted at Supertext before it could be downloaded.'],
        SdkException::TIMEOUT => ['timeout', 'Timed out waiting for the Supertext translation.'],
        SdkException::INCOMPLETE_RESPONSE => ['empty', 'The translated document was empty.'],
    ];

    private readonly Client $sdk;
    private readonly string $apiKey;

    /** @var (\Closure(): self)|null builds the client for the review service (null: none) */
    private readonly ?\Closure $makeReviewClient;

    private ?self $reviewClient = null;

    /**
     * @param callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string, headers: array<string, string>} $transport
     * @param callable(float $seconds): void|null $sleep tests: the SDK's clock then counts the slept time
     * @param string $userAgent sent with every request, e.g. "Pimcore-Supertext-Translation/1.2.0"
     * @param string $reviewUrl the review service for languages with *Translation review* on ('' = none)
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        callable $transport,
        private readonly int $pollTimeout = 180,
        float $pollInterval = 2.0,
        ?callable $sleep = null,
        string $userAgent = self::USER_AGENT,
        string $reviewUrl = '',
    ) {
        $this->apiKey = self::normalizeKey($apiKey);
        $baseUrl = $baseUrl !== '' ? $baseUrl : self::LIVE;
        $reviewUrl = trim($reviewUrl);
        // Same key, transport, timeouts and User-Agent; only the base URL differs.
        $this->makeReviewClient = $reviewUrl === '' || rtrim($reviewUrl, '/') === rtrim($baseUrl, '/')
            ? null
            : fn (): self => new self($apiKey, $reviewUrl, $transport, $pollTimeout, $pollInterval, $sleep, $userAgent);

        $elapsed = 0.0;
        $sdkSleep = $sleep === null ? null : static function (float $seconds) use ($sleep, &$elapsed): void {
            $elapsed += $seconds;
            $sleep($seconds);
        };
        $clock = $sleep === null ? null : static function () use (&$elapsed): float {
            return $elapsed;
        };

        $this->sdk = new Client(
            $this->apiKey,
            baseUrl: $baseUrl,
            pollInterval: $pollInterval,
            timeout: (float) $pollTimeout,
            requestTimeout: 60.0,
            userAgent: $userAgent,
            transport: new CallableTransport($transport),
            sleep: $sdkSleep,
            clock: $clock,
        );
    }

    /** Supertext shows the key as "Supertext-Auth-Key <key>"; accept it with or without that prefix. */
    public static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/^Supertext-Auth-Key\s+/i', '', trim($key));
    }

    public static function baseUrlFor(string $environment, string $customEndpoint = ''): string
    {
        if (trim($customEndpoint) !== '') {
            return trim($customEndpoint);
        }

        return match ($environment) {
            'staging' => self::STAGING,
            'testing' => self::TESTING,
            default   => self::LIVE,
        };
    }

    /**
     * The client for a target language: the review service when its *Translation review* is on (same
     * key, same protocol; created once), else this one. Without a review URL always this one. Each
     * translation is a full round trip, so a job is always collected by the client that submitted it.
     */
    public function forReview(bool $review): self
    {
        if (!$review || $this->makeReviewClient === null) {
            return $this;
        }

        return $this->reviewClient ??= ($this->makeReviewClient)();
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Translates a complete HTML document and returns the translated HTML.
     *
     * @param string $targetLanguage a Supertext target code, e.g. "de-CH" or "es" (LanguageCodes::target()); sent as given
     * @param string $sourceLanguage a Supertext source code, e.g. "en" (LanguageCodes::source()); sent as given. Empty: auto-detect.
     * @param string $politeness     "default", "more" (formal) or "less" (informal)
     */
    public function translateDocument(string $html, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): string
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit($this->pollTimeout + 60);
        }

        return $this->call(fn (): string => $this->sdk->translateHtml($html, $targetLanguage, $sourceLanguage !== '' ? $sourceLanguage : null, $politeness));
    }

    /**
     * Translates keyed segments, packed into as few documents as the size limit allows.
     * A segment missing from Supertext's answer is left out of the result.
     *
     * @param array<int|string, array{text: string, html: bool}> $segments
     * @return array<int|string, string> segment key => translation
     */
    public function translateSegments(array $segments, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default', int $maxCharacters = self::MAX_DOCUMENT_CHARACTERS): array
    {
        $toSend = [];
        foreach ($segments as $key => $segment) {
            $item = $segment['html'] ? Segment::html($segment['text']) : Segment::text($segment['text']);
            if (!$item->isBlank()) {
                $toSend[$key] = $item;
            }
        }

        $result = [];
        foreach (Client::batches($toSend, $maxCharacters) as $batch) {
            $keys = array_keys($batch);
            $list = array_values($batch);
            $html = $this->translateDocument(HtmlDocument::build($list), $targetLanguage, $sourceLanguage, $politeness);
            foreach (HtmlDocument::parse($html, $list) as $index => $text) {
                if ($text !== null) {
                    $result[$keys[$index]] = $text;
                }
            }
        }

        return $result;
    }

    /** Cost-free check that the key is accepted. */
    public function validateApiKey(): void
    {
        $this->call(fn () => $this->sdk->validateApiKey());
    }

    /** The automatic API key check (cost-free GET features); never throws. */
    public function checkApiKey(): ApiKeyCheck
    {
        return $this->sdk->checkApiKey();
    }

    /** Seconds to wait before retry $attempt (0-based): Retry-After if sent, else 1, 2, 4, 8 plus jitter. */
    public static function retryDelay(int $attempt, string $retryAfter = ''): float
    {
        return Protocol::retryDelayMs($attempt, $retryAfter !== '' ? $retryAfter : null) / 1000;
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (SdkException $e) {
            throw self::fromSdk($e);
        }
    }

    /** An SDK error as this bundle's exception, with the key the Studio UI translates. */
    public static function fromSdk(SdkException $e): SupertextException
    {
        [$key, $message] = self::KEYS[$e->errorCode] ?? ['', $e->getMessage()];
        $detail = (string) $e->detail;
        $params = match ($e->errorCode) {
            SdkException::UNEXPECTED_STATUS => ['status' => (int) $e->status],
            SdkException::INVALID_LANGUAGE_PAIR => ['source' => (string) $e->sourceLang ?: '?', 'target' => (string) $e->targetLang ?: '?'],
            SdkException::UNSUPPORTED_LANGUAGE => ['language' => (string) ($e->targetLang ?? $e->sourceLang) ?: '?'],
            default => [],
        };
        $message = $params === [] ? $message : sprintf($message, ...array_values($params));
        if ($detail !== '') {
            $message .= ' (' . $detail . ')';
        }

        return new SupertextException($message, (int) $e->status, $e, $key, $params, $detail);
    }
}
