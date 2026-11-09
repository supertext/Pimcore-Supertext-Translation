<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Api;

/**
 * What translation files need from Pimcore: the languages, the texts of a document or data object
 * as an API translation would send them, and writing translations back the same way.
 * Service\TranslationFileElements is the Pimcore implementation; the unit tests use a fake.
 * No Pimcore or Symfony classes here.
 */
interface FileElements
{
    /** @return list<string> Pimcore's valid languages */
    public function languages(): array;

    /**
     * The element's texts in the source language (key => text, html), or null when it doesn't
     * exist or the user may not view it.
     *
     * `source`: the language really used (a document's own language). `kept`: the target already
     * has a translation and isn't replaced without overwrite (the API translation's rule).
     * `editable`: the user may write the target (save/create the document translation, `lEdit`).
     *
     * @param string $type "document" or "object"
     *
     * @return array{source: string, segments: array<string, array{text: string, html: bool}>, kept: bool, editable: bool}|null
     */
    public function segments(string $type, int $id, string $source, string $target, bool $overwrite): ?array;

    /**
     * Writes translated texts (key => text) into the target language, like an API translation
     * (a new unpublished document translation or a new version).
     *
     * @param array<string, string> $translations
     *
     * @throws SupertextException with a key (`parent-missing`, `create-not-allowed`, …)
     */
    public function write(string $type, int $id, string $source, string $target, array $translations): void;
}
