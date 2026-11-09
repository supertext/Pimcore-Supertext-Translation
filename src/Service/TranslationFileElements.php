<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Service;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document;
use Pimcore\Model\Document\PageSnippet;
use Pimcore\Model\User;
use Pimcore\Tool;
use Supertext\PimcoreTranslationBundle\Api\FileElements;
use Supertext\PimcoreTranslationBundle\Api\FileExchange;

/**
 * Documents and data objects for translation files, as the signed-in Studio user (or the console's
 * user): the same texts and write-back as DocumentTranslator / ObjectTranslator use for API
 * translations, with their permission checks.
 */
final class TranslationFileElements implements FileElements
{
    public function __construct(
        private readonly DocumentTranslator $documents,
        private readonly ObjectTranslator $objects,
        private readonly User $user,
    ) {
    }

    public function languages(): array
    {
        return array_values(array_map('strval', Tool::getValidLanguages()));
    }

    public function segments(string $type, int $id, string $source, string $target, bool $overwrite): ?array
    {
        $element = $this->load($type, $id);
        if ($element === null) {
            return null;
        }

        return $element instanceof PageSnippet
            ? $this->documents->fileSegments($element, $target, $overwrite, $this->user)
            : $this->objects->fileSegments($element, $source !== '' ? $source : ($this->languages()[0] ?? ''), $target, $overwrite, $this->user);
    }

    public function write(string $type, int $id, string $source, string $target, array $translations): void
    {
        $element = $this->load($type, $id);
        if ($element instanceof PageSnippet) {
            $this->documents->writeTranslations($element, $target, $translations, $this->user);
        } elseif ($element instanceof Concrete) {
            $this->objects->writeTranslations($element, $source, $target, $translations, $this->user);
        }
    }

    /** The element if it exists and the user may view it. */
    private function load(string $type, int $id): PageSnippet|Concrete|null
    {
        $element = $type === FileExchange::DOCUMENT ? Document::getById($id) : ($type === FileExchange::OBJECT ? Concrete::getById($id) : null);

        return ($element instanceof PageSnippet || $element instanceof Concrete) && $element->isAllowed('view', $this->user) ? $element : null;
    }
}
