<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Api;

use Supertext\PimcoreTranslationBundle\Sdk\Files\FileJob;
use Supertext\PimcoreTranslationBundle\Sdk\Files\FileSegment;
use Supertext\PimcoreTranslationBundle\Sdk\Files\ImportedSegment;
use Supertext\PimcoreTranslationBundle\Sdk\Files\TranslationFile;
use Supertext\PimcoreTranslationBundle\Sdk\Files\TranslationFileError;
use Supertext\PimcoreTranslationBundle\Sdk\Files\TranslationFiles;

/**
 * "Export for translation" and "Import translation": a document's or data object's texts as an
 * XLIFF 1.2 or HTML file (the SDK's TranslationFiles), translated outside the API, and the
 * translated file written back like an API translation.
 *
 * Reference of a job: "<type>:<id>:<source language>:<target language>" with type "document" or
 * "object", e.g. "document:12:en:de_CH" (ids and Pimcore language codes, never titles). Segment
 * keys are the services' own keys (`meta:title`, `editable:content`, a localized field name).
 *
 * No Pimcore or Symfony classes: the elements are a FileElements.
 */
final class FileExchange
{
    /** The `plugin` of every job: the GitHub repository name. */
    public const PLUGIN = 'Pimcore-Supertext-Translation';

    public const SHORT_NAME = 'pimcore';

    public const XLIFF = 'xliff';
    public const HTML = 'html';

    public const DOCUMENT = 'document';
    public const OBJECT = 'object';

    /** Uploads larger than this are refused before they are read (10 MB, the SDK's limit). */
    public const MAX_UPLOAD_BYTES = TranslationFiles::MAX_FILE_CHARACTERS;

    /** Counters of an imported job. */
    public const COUNTS = ['imported', 'sourceChanged', 'untranslated', 'invalidMarkup', 'missing', 'kept'];

    /** @param array<string, string> $mapping Settings::mapping() */
    public function __construct(private readonly FileElements $elements, private readonly array $mapping = [])
    {
    }

    public static function reference(string $type, int $id, string $source, string $target): string
    {
        return $type . ':' . $id . ':' . $source . ':' . $target;
    }

    /** @return array{type: string, id: int, source: string, target: string}|null */
    public static function parseReference(string $reference): ?array
    {
        if (preg_match('/^(document|object):([1-9][0-9]{0,18}):([A-Za-z]{2,3}(?:[_-][A-Za-z0-9]+)*):([A-Za-z]{2,3}(?:[_-][A-Za-z0-9]+)*)$/', $reference, $m) !== 1) {
            return null;
        }

        return ['type' => $m[1], 'id' => (int) $m[2], 'source' => $m[3], 'target' => $m[4]];
    }

    /**
     * "pimcore-document-12-de_CH.xlf", or "pimcore-document-12.xlf" for several target languages.
     *
     * @param list<string> $targets
     */
    public static function fileName(string $type, int $id, array $targets, string $format): string
    {
        $name = self::SHORT_NAME . '-' . $type . '-' . $id . (\count($targets) === 1 ? '-' . $targets[0] : '');

        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . ($format === self::HTML ? '.html' : '.xlf');
    }

    public static function contentType(string $format): string
    {
        return $format === self::HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml';
    }

    /**
     * The Supertext code through the mapping; a language without one is written as its Pimcore
     * code with "-" (a file may go to any translator, so it never blocks the export).
     */
    public function supertextCode(string $language, bool $source): string
    {
        try {
            return $source ? LanguageCodes::source($language, $this->mapping) : LanguageCodes::target($language, $this->mapping);
        } catch (SupertextException) {
            return str_replace('_', '-', $language);
        }
    }

    /**
     * @param list<string> $targets
     *
     * @return array{content: string, fileName: string, contentType: string, targets: list<string>}
     *
     * @throws SupertextException element-not-found, choose-target, html-one-language, unknown-language, nothing-to-translate
     */
    public function export(string $type, int $id, string $source, array $targets, string $format, bool $overwrite): array
    {
        $format    = $format === self::HTML ? self::HTML : self::XLIFF;
        $languages = $this->elements->languages();
        $targets   = array_values(array_unique(array_filter($targets, static fn (string $t): bool => $t !== '')));

        if ($targets === []) {
            throw new SupertextException('Choose at least one language to translate into.', key: 'choose-target');
        }

        if ($format === self::HTML && \count($targets) > 1) {
            throw new SupertextException('An HTML file holds one target language: choose exactly one, or use XLIFF.', key: 'html-one-language');
        }

        $jobs     = [];
        $exported = [];

        foreach ($targets as $target) {
            if (!\in_array($target, $languages, true)) {
                throw new SupertextException(sprintf('Unknown language %s.', $target), key: 'unknown-language', params: ['language' => $target]);
            }

            $element = $this->elements->segments($type, $id, $source, $target, $overwrite);

            if ($element === null) {
                throw new SupertextException('Not found.', key: 'element-not-found');
            }

            if ($element['source'] === $target || $element['kept'] || $element['segments'] === []) {
                continue;
            }

            $segments = [];

            foreach ($element['segments'] as $key => $segment) {
                $segments[] = new FileSegment((string) $key, $segment['text'], $segment['html']);
            }

            $jobs[]     = new FileJob(self::PLUGIN, self::reference($type, $id, $element['source'], $target), $this->supertextCode($element['source'], true), $this->supertextCode($target, false), $segments);
            $exported[] = $target;
        }

        if ($jobs === []) {
            throw new SupertextException('Nothing to translate.', key: 'nothing-to-translate');
        }

        try {
            $content = $format === self::HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        } catch (TranslationFileError $e) {
            throw new SupertextException($e->getMessage(), key: self::fileKey($e), previous: $e);
        }

        return ['content' => $content, 'fileName' => self::fileName($type, $id, $exported, $format), 'contentType' => self::contentType($format), 'targets' => $exported];
    }

    /**
     * @return list<array{reference: string, type: string, id: int, language: string, error: array{message: string, key: string, params: array<string, string|int>, detail: string}|null, counts: array<string, int>}>
     *
     * @throws SupertextException file-too-large, file-invalid-file, file-missing-metadata
     */
    public function import(string $content, bool $includeChanged, bool $overwrite): array
    {
        if (\strlen($content) > self::MAX_UPLOAD_BYTES) {
            throw new SupertextException('The file is too large (at most 10 MB).', key: 'file-too-large');
        }

        try {
            $files = TranslationFiles::read($content);
        } catch (TranslationFileError $e) {
            throw new SupertextException($e->getMessage(), key: self::fileKey($e), previous: $e);
        }

        $results = [];

        foreach ($files as $file) {
            $results[] = $this->importJob($file, $includeChanged, $overwrite);
        }

        return $results;
    }

    /**
     * @return array{reference: string, type: string, id: int, language: string, error: array{message: string, key: string, params: array<string, string|int>, detail: string}|null, counts: array<string, int>}
     */
    private function importJob(TranslationFile $file, bool $includeChanged, bool $overwrite): array
    {
        $result = ['reference' => $file->reference, 'type' => '', 'id' => 0, 'language' => $file->targetLanguage, 'error' => null, 'counts' => array_fill_keys(self::COUNTS, 0)];
        $fail   = static fn (array $result, SupertextException $e): array => ['error' => $e->toArray()] + $result;

        if ($file->plugin !== self::PLUGIN) {
            return $fail($result, new SupertextException(sprintf('This file belongs to another system (%s).', $file->plugin), key: 'file-other-system', params: ['plugin' => $file->plugin]));
        }

        $reference = self::parseReference($file->reference);

        if ($reference === null) {
            return $fail($result, new SupertextException('The item this file was exported from no longer exists.', key: 'file-not-found'));
        }

        $result = ['type' => $reference['type'], 'id' => $reference['id'], 'language' => $reference['target']] + $result;

        foreach ([$reference['target'], $reference['source']] as $language) {
            if (!\in_array($language, $this->elements->languages(), true)) {
                return $fail($result, new SupertextException(sprintf('The site has no language %s.', $language), key: 'file-no-language', params: ['language' => $language]));
            }
        }

        try {
            $element = $this->elements->segments($reference['type'], $reference['id'], $reference['source'], $reference['target'], $overwrite);
        } catch (SupertextException $e) {
            return $fail($result, $e);
        }

        if ($element === null) {
            return $fail($result, new SupertextException('The item this file was exported from no longer exists.', key: 'file-not-found'));
        }

        if (!$element['editable']) {
            return $fail($result, new SupertextException('You may not edit this translation.', key: 'file-not-allowed'));
        }

        $counts = $result['counts'];

        // An existing translation is only replaced with overwrite, as in an API translation.
        if ($element['kept']) {
            $counts['kept'] = \count($file->units);

            return ['counts' => $counts] + $result;
        }

        $current = [];

        foreach ($element['segments'] as $key => $segment) {
            $current[] = new FileSegment((string) $key, $segment['text'], $segment['html']);
        }

        try {
            $imported = TranslationFiles::importTranslations($file, $current);
        } catch (TranslationFileError $e) {
            return $fail($result, new SupertextException($e->getMessage(), key: self::fileKey($e)));
        }

        $translations = [];
        $status       = [
            ImportedSegment::SOURCE_CHANGED => 'sourceChanged',
            ImportedSegment::UNTRANSLATED   => 'untranslated',
            ImportedSegment::INVALID_MARKUP => 'invalidMarkup',
            ImportedSegment::MISSING        => 'missing',
        ];

        foreach ($imported as $segment) {
            $write = $segment->status === ImportedSegment::OK || ($segment->status === ImportedSegment::SOURCE_CHANGED && $includeChanged);

            if ($write && $segment->text !== null && trim(strip_tags($segment->text)) !== '') {
                $translations[$segment->key] = $segment->text;
            } else {
                $counts[$status[$segment->status] ?? 'untranslated']++;
            }
        }

        if ($translations !== []) {
            try {
                $this->elements->write($reference['type'], $reference['id'], $element['source'], $reference['target'], $translations);
            } catch (SupertextException $e) {
                return ['counts' => $counts] + $fail($result, $e);
            }
        }

        $counts['imported'] = \count($translations);

        return ['counts' => $counts] + $result;
    }

    private static function fileKey(TranslationFileError $e): string
    {
        return 'file-' . str_replace('_', '-', $e->errorCode);
    }
}
