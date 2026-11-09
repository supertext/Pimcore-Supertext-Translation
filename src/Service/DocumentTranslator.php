<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Service;

use Pimcore\Model\Document;
use Pimcore\Model\Document\Editable;
use Pimcore\Model\Document\PageSnippet;
use Pimcore\Model\Element\Service as ElementService;
use Pimcore\Model\User;
use Pimcore\Tool;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;
use Supertext\PimcoreTranslationBundle\Settings;

/**
 * Translates a document (page or snippet) into linked translations in other languages.
 *
 * Pimcore keeps one document per language (property "language") and links them as
 * translations. A missing translation is created as an unpublished copy under the parent's
 * translation (the parent must already exist in that language, unless the document sits at
 * the top of the tree), its key made from the translated navigation name or title. An
 * existing translation is only changed with "overwrite", as a new version. The texts that are
 * translated: title, description, the navigation name and title properties, and the
 * editables of the configured types (input, textarea, wysiwyg and link texts by default).
 */
final class DocumentTranslator
{
    private const TEXT_PROPERTIES = ['navigation_name', 'navigation_title'];

    public function __construct(
        private readonly Settings $settings,
        private readonly SegmentTranslator $segments,
        private readonly History $history,
    ) {
    }

    public function language(Document $document): string
    {
        $language = (string) $document->getProperty('language');
        $valid = Tool::getValidLanguages();

        return \in_array($language, $valid, true) ? $language : ($valid[0] ?? '');
    }

    /** @return array<string, int> language => document id of the linked translations (the document itself included) */
    public function translations(Document $document): array
    {
        $service = new Document\Service();
        $ids = $service->getTranslations($document);
        $ids[$this->language($document)] = $document->getId();

        return array_map('intval', $ids);
    }

    /**
     * @return array{source: string, languages: list<array{language: string, name: string, documentId: ?int, path: ?string, parentReady: bool, allowed: bool, lastTranslation: ?int}>}
     */
    public function describe(PageSnippet $document, User $user): array
    {
        $source = $this->language($document);
        $translations = $this->translations($document);
        $last = $this->history->lastTranslations($document);
        $out = [];
        foreach (Tool::getValidLanguages() as $language) {
            $existing = isset($translations[$language]) ? Document::getById($translations[$language]) : null;
            $parent = $existing ? null : $this->targetParent($document, $language);
            $out[] = [
                'language' => $language,
                'name' => \Locale::getDisplayName($language, $user->getLanguage() ?: 'en'),
                'documentId' => $existing?->getId(),
                'path' => $existing?->getRealFullPath(),
                'parentReady' => $existing !== null || $parent !== null,
                'allowed' => $existing ? $existing->isAllowed('save', $user) : ($parent?->isAllowed('create', $user) ?? false),
                'lastTranslation' => $last[$language] ?? null,
            ];
        }

        return ['source' => $source, 'languages' => $out];
    }

    /**
     * @param list<string> $targets
     *
     * @return list<array{language: string, status: string, message: string, key?: string, params?: array<string, string|int>, detail?: string, documentId?: int, path?: string, created?: bool}>
     *         message: English; key/params/detail: for the UI (`supertext.error.<key>`)
     */
    public function translate(PageSnippet $document, array $targets, bool $overwrite, User $user): array
    {
        $source = $this->language($document);
        $document = $this->latest($document, $user);
        $units = $this->units($document);
        if ($units === []) {
            throw new SupertextException('This document has no text to translate.', key: 'no-document-text');
        }
        $this->segments->assertConfigured();
        $translations = $this->translations($document);

        $results = [];
        foreach (array_unique($targets) as $target) {
            if ($target === $source) {
                continue;
            }
            if (!\in_array($target, Tool::getValidLanguages(), true)) {
                $results[] = ['language' => $target, 'status' => 'error', 'message' => sprintf('Unknown language %s.', $target), 'key' => 'unknown-language', 'params' => ['language' => $target]];
                continue;
            }
            $existing = isset($translations[$target]) ? Document::getById($translations[$target]) : null;
            if ($existing && !$overwrite) {
                $results[] = ['language' => $target, 'status' => 'skipped', 'message' => 'Already translated.', 'documentId' => $existing->getId(), 'path' => $existing->getRealFullPath()];
                continue;
            }
            try {
                $results[] = $existing instanceof PageSnippet
                    ? $this->update($document, $existing, $units, $source, $target, $user)
                    : $this->create($document, $units, $source, $target, $user);
            } catch (SupertextException $e) {
                $results[] = ['language' => $target, 'status' => 'error'] + $e->toArray();
            }
        }

        return $results;
    }

    /**
     * The texts an API translation of the document into $target would send, for a translation
     * file (Export for translation / Import translation): the latest version's units, and whether
     * the target is kept (a linked translation exists and $overwrite is off) and writable.
     *
     * @return array{source: string, segments: array<string, array{text: string, html: bool}>, kept: bool, editable: bool}
     */
    public function fileSegments(PageSnippet $document, string $target, bool $overwrite, User $user): array
    {
        $source = $this->language($document);
        $existing = ($id = $this->translations($document)[$target] ?? null) ? Document::getById($id) : null;
        $parent = $existing ? null : $this->targetParent($document, $target);

        return [
            'source' => $source,
            'segments' => $this->segmentsOf($this->units($this->latest($document, $user))),
            'kept' => $existing !== null && !$overwrite,
            // A missing parent translation is reported when writing (parent-missing).
            'editable' => $existing ? $existing->isAllowed('save', $user) : ($parent === null || $parent->isAllowed('create', $user)),
        ];
    }

    /**
     * Writes translations from a file (key => text, keys of units()) into the linked translation
     * in $target, exactly like an API translation: a new unpublished translation, or a new version
     * of the existing one.
     *
     * @param array<string, string> $translated
     *
     * @return array{language: string, status: string, message: string, documentId: int, path: string, created: bool}
     */
    public function writeTranslations(PageSnippet $document, string $target, array $translated, User $user): array
    {
        $source = $this->language($document);
        $document = $this->latest($document, $user);
        $units = array_intersect_key($this->units($document), $translated);
        $existing = ($id = $this->translations($document)[$target] ?? null) ? Document::getById($id) : null;

        return $existing instanceof PageSnippet
            ? $this->update($document, $existing, $units, $source, $target, $user, $translated)
            : $this->create($document, $units, $source, $target, $user, $translated);
    }

    /**
     * The texts of a document: key => [text, html, kind, name].
     *
     * @return array<string, array{text: string, html: bool, kind: string, name: string}>
     */
    public function units(PageSnippet $document): array
    {
        $units = [];
        $add = static function (string $key, string $kind, string $name, ?string $value, bool $html) use (&$units): void {
            $value = trim((string) $value);
            if ($value !== '' && !($html && trim(strip_tags($value)) === '')) {
                $units[$key] = ['text' => $value, 'html' => $html, 'kind' => $kind, 'name' => $name];
            }
        };
        if ($document instanceof Document\Page) {
            $add('meta:title', 'meta', 'title', $document->getTitle(), false);
            $add('meta:description', 'meta', 'description', $document->getDescription(), false);
        }
        foreach (self::TEXT_PROPERTIES as $property) {
            $value = $document->getProperty($property);
            $add('property:' . $property, 'property', $property, \is_string($value) ? $value : null, false);
        }
        $types = $this->settings->documentEditableTypes();
        foreach ($document->getEditables() as $name => $editable) {
            $type = $editable->getType();
            if (!\in_array($type, $types, true)) {
                continue;
            }
            $value = $type === 'link' ? ($editable->getData()['text'] ?? null) : $editable->getData();
            $add('editable:' . $name, 'editable', $name, \is_string($value) ? $value : null, $type === 'wysiwyg');
        }

        return $units;
    }

    /** @param array<string, string>|null $translated null: translate with Supertext */
    private function create(PageSnippet $document, array $units, string $source, string $target, User $user, ?array $translated = null): array
    {
        $parent = $this->targetParent($document, $target);
        if (!$parent) {
            throw new SupertextException('Translate the parent page into this language first.', key: 'parent-missing');
        }
        if (!$parent->isAllowed('create', $user)) {
            throw new SupertextException('You are not allowed to create documents there.', key: 'create-not-allowed');
        }
        $translated ??= $this->segments->translate($this->segmentsOf($units), $source, $target);

        $service = new Document\Service($user);
        /** @var PageSnippet $copy */
        $copy = $service->copyAsChild($parent, $document, false, false, $target);
        $copy->setPublished(false);
        $keySource = $translated['property:navigation_name'] ?? $translated['meta:title'] ?? null;
        $key = $parent->getId() === 1
            ? strtolower(str_replace('_', '-', $target))
            : ($keySource !== null ? $this->slug($keySource, $target) : $copy->getKey());
        $copy->setKey($key);
        $copy->setKey(Document\Service::getUniqueKey($copy));
        $this->apply($copy, $units, $translated);
        $copy->setUserModification($user->getId());
        $copy->save();
        $this->history->record($document, $source, $target, $user->getId(), $copy);

        return ['language' => $target, 'status' => 'translated', 'message' => '', 'documentId' => $copy->getId(), 'path' => $copy->getRealFullPath(), 'created' => true];
    }

    /** @param array<string, string>|null $translated null: translate with Supertext */
    private function update(PageSnippet $document, PageSnippet $existing, array $units, string $source, string $target, User $user, ?array $translated = null): array
    {
        if (!$existing->isAllowed('save', $user)) {
            throw new SupertextException('You are not allowed to edit the translation.', key: 'edit-not-allowed');
        }
        $translated ??= $this->segments->translate($this->segmentsOf($units), $source, $target);
        $existing = $this->latest($existing, $user);
        $this->apply($existing, $units, $translated, $document);
        $existing->setUserModification($user->getId());
        $existing->saveVersion(true, true, sprintf('Translated with Supertext from %s', $source));
        $this->history->record($document, $source, $target, $user->getId(), $existing);

        return ['language' => $target, 'status' => 'translated', 'message' => '', 'documentId' => $existing->getId(), 'path' => $existing->getRealFullPath(), 'created' => false];
    }

    /** Writes the translations into a document; missing editables are copied from the source first. */
    private function apply(PageSnippet $document, array $units, array $translated, ?PageSnippet $from = null): void
    {
        foreach ($units as $key => $unit) {
            $value = trim($translated[$key] ?? '');
            if ($value === '') {
                continue;
            }
            switch ($unit['kind']) {
                case 'meta':
                    if ($document instanceof Document\Page) {
                        $unit['name'] === 'title' ? $document->setTitle($value) : $document->setDescription($value);
                    }
                    break;
                case 'property':
                    $document->setProperty($unit['name'], 'text', $value, false, true);
                    break;
                case 'editable':
                    $editable = $document->getEditable($unit['name']);
                    if (!$editable && $from) {
                        $original = $from->getEditable($unit['name']);
                        if ($original) {
                            $editable = clone $original;
                            $editable->setDocument($document);
                            $document->setEditable($editable);
                        }
                    }
                    if ($editable instanceof Editable\Link) {
                        $data = $editable->getData();
                        $data['text'] = $value;
                        $editable->setDataFromEditmode($data);
                    } elseif ($editable) {
                        $editable->setDataFromEditmode($value);
                    }
                    break;
            }
        }
    }

    /** URL-friendly key from a translated name: "Schweizer Schokolade" → "schweizer-schokolade". */
    private function slug(string $text, string $language): string
    {
        $slug = (string) (new AsciiSlugger(explode('_', $language)[0]))->slug(strip_tags($text))->lower();

        return ElementService::getValidKey($slug !== '' ? $slug : $text, 'document');
    }

    /** @return array<string, array{text: string, html: bool}> */
    private function segmentsOf(array $units): array
    {
        return array_map(static fn (array $u): array => ['text' => $u['text'], 'html' => $u['html']], $units);
    }

    /** The parent's translation in the language, or the root for documents at the top of the tree. */
    private function targetParent(Document $document, string $language): ?Document
    {
        $parent = $document->getParent();
        if (!$parent) {
            return null;
        }
        if ($parent->getId() === 1) {
            return $parent;
        }
        $id = $this->translations($parent)[$language] ?? null;

        return $id ? Document::getById($id) : null;
    }

    private function latest(PageSnippet $document, User $user): PageSnippet
    {
        $latest = $document->getLatestVersion($user->getId())?->getData();

        return $latest instanceof PageSnippet ? $latest : $document;
    }
}
