<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Service;

use Supertext\PimcoreTranslationBundle\Api\InstallationReports;
use Supertext\PimcoreTranslationBundle\Api\SupertextClient;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;
use Supertext\PimcoreTranslationBundle\Reporting\Reporting;
use Supertext\PimcoreTranslationBundle\Settings;

/**
 * Sends values to Supertext as one HTML document per target language (one data-st-id
 * element per value; split below the API's size limit by the Supertext SDK) and returns
 * the translations. Languages are resolved by Settings::languageCode(): the mapping, else an exact
 * Supertext code, else the SDK's default, else `unsupported-language` and nothing is sent.
 */
final class SegmentTranslator
{
    private ?SupertextClient $client = null;

    public function __construct(private readonly Settings $settings, private readonly ?Reporting $reporting = null)
    {
    }

    public function setClient(SupertextClient $client): void
    {
        $this->client = $client;
    }

    public function assertConfigured(): void
    {
        if ($this->client === null && $this->settings->apiKey() === '') {
            throw new SupertextException(
                'No Supertext API key is configured. Set the SUPERTEXT_API_KEY environment variable. ' . Settings::KEY_HELP,
                key: 'not-configured',
            );
        }
    }

    /**
     * @param array<int|string, array{text: string, html: bool}> $segments
     *
     * @return array<int|string, string> segment key => translation (missing keys: not translated)
     */
    public function translate(array $segments, string $sourceLanguage, string $targetLanguage): array
    {
        if ($segments === []) {
            return [];
        }
        $this->assertConfigured();
        // Languages with Translation review go through Supertext's review service (same key and protocol).
        $client = ($this->client ?? $this->settings->client())->forReview($this->settings->review($targetLanguage));

        $target = $this->settings->languageCode($targetLanguage);
        $source = $this->settings->sourceCode($sourceLanguage);
        $politeness = $this->settings->politenessFor($targetLanguage, $target);

        try {
            return $client->translateSegments($segments, $target, $source, $politeness);
        } catch (SupertextException $e) {
            if (\in_array($e->getCode(), [401, 403], true)) {
                // The key was rejected: the Commando Center hears about it once (after the response).
                $this->reporting?->later(InstallationReports::AUTHENTICATION_FAILED, $e->getCode());
            }
            throw Settings::withKeyHelp($e);
        }
    }
}
