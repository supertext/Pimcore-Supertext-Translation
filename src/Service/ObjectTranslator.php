<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Service;

use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Service as DataObjectService;
use Pimcore\Model\User;
use Pimcore\Tool;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;
use Supertext\PimcoreTranslationBundle\Settings;

/**
 * Translates the localized fields of a data object from one language into others.
 *
 * Values are read from the latest version of the object (drafts included) and the result is
 * saved as a new version: what's published doesn't change until an editor publishes.
 */
final class ObjectTranslator
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SegmentTranslator $segments,
        private readonly History $history,
    ) {
    }

    /** @return array<string, bool> field name => is HTML, for the object's translatable localized fields */
    public function fields(Concrete $object): array
    {
        $definition = $object->getClass()->getFieldDefinition('localizedfields');
        if (!$definition instanceof Localizedfields) {
            return [];
        }
        $types = $this->settings->objectFieldTypes();
        $out = [];
        foreach ($definition->getFieldDefinitions() as $name => $field) {
            if (\in_array($field->getFieldtype(), $types, true)) {
                $out[$name] = $field->getFieldtype() === 'wysiwyg';
            }
        }

        return $out;
    }

    /**
     * @return list<array{language: string, name: string, hasContent: bool, editable: bool, lastTranslation: ?int}>
     */
    public function describe(Concrete $object, User $user): array
    {
        $object = $this->latest($object, $user);
        $fields = $this->fields($object);
        $editable = DataObjectService::getLanguagePermissions($object, $user, 'lEdit');
        $last = $this->history->lastTranslations($object);
        $out = [];
        foreach (Tool::getValidLanguages() as $language) {
            $out[] = [
                'language' => $language,
                'name' => \Locale::getDisplayName($language, $user->getLanguage() ?: 'en'),
                'hasContent' => $this->hasContent($object, $fields, $language),
                'editable' => $editable === null || isset($editable[$language]),
                'lastTranslation' => $last[$language] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $targets
     *
     * @return list<array{language: string, status: string, message: string, key?: string, params?: array<string, string|int>, detail?: string}>
     *         message: English; key/params/detail: for the UI (`supertext.error.<key>`)
     */
    public function translate(Concrete $object, string $source, array $targets, bool $overwrite, User $user): array
    {
        $object = $this->latest($object, $user);
        $fields = $this->fields($object);
        if ($fields === []) {
            throw new SupertextException('This object has no localized text fields to translate.', key: 'no-fields');
        }
        if (!\in_array($source, Tool::getValidLanguages(), true)) {
            throw new SupertextException(sprintf('Unknown language %s.', $source), key: 'unknown-language', params: ['language' => $source]);
        }
        if (!$this->hasContent($object, $fields, $source)) {
            throw new SupertextException(sprintf('The object has no %s text to translate from.', $source), key: 'no-source-text', params: ['language' => $source]);
        }
        $this->segments->assertConfigured();

        $segments = [];
        foreach ($fields as $name => $html) {
            $value = trim((string) $object->getLocalizedfields()->getLocalizedValue($name, $source, true));
            if ($value !== '' && !($html && trim(strip_tags($value)) === '')) {
                $segments[$name] = ['text' => $value, 'html' => $html];
            }
        }

        $editable = DataObjectService::getLanguagePermissions($object, $user, 'lEdit');
        $results = [];
        $changed = [];
        foreach (array_unique($targets) as $target) {
            if ($target === $source) {
                continue;
            }
            if (!\in_array($target, Tool::getValidLanguages(), true)) {
                $results[] = ['language' => $target, 'status' => 'error', 'message' => sprintf('Unknown language %s.', $target), 'key' => 'unknown-language', 'params' => ['language' => $target]];
                continue;
            }
            if ($editable !== null && !isset($editable[$target])) {
                $results[] = ['language' => $target, 'status' => 'error', 'message' => 'You are not allowed to edit this language.', 'key' => 'language-not-allowed'];
                continue;
            }
            if (!$overwrite && $this->hasContent($object, $fields, $target)) {
                $results[] = ['language' => $target, 'status' => 'skipped', 'message' => 'Already translated.'];
                continue;
            }
            try {
                foreach ($this->segments->translate($segments, $source, $target) as $name => $value) {
                    if (trim($value) !== '') {
                        $object->getLocalizedfields()->setLocalizedValue($name, $value, $target);
                    }
                }
                $changed[] = $target;
                $results[] = ['language' => $target, 'status' => 'translated', 'message' => ''];
            } catch (SupertextException $e) {
                $results[] = ['language' => $target, 'status' => 'error'] + $e->toArray();
            }
        }

        if ($changed !== []) {
            $object->setUserModification($user->getId());
            $object->saveVersion(true, true, sprintf('Translated with Supertext: %s → %s', $source, implode(', ', $changed)));
            foreach ($changed as $target) {
                $this->history->record($object, $source, $target, $user->getId());
            }
        }

        return $results;
    }

    /**
     * The texts an API translation from $source into $target would send, for a translation file
     * (Export for translation / Import translation), from the latest version; `kept`: the target
     * already has text and $overwrite is off; `editable`: the user may edit the target language.
     *
     * @return array{source: string, segments: array<string, array{text: string, html: bool}>, kept: bool, editable: bool}
     */
    public function fileSegments(Concrete $object, string $source, string $target, bool $overwrite, User $user): array
    {
        $object = $this->latest($object, $user);
        $fields = $this->fields($object);
        $segments = [];
        foreach ($fields as $name => $html) {
            $value = trim((string) $object->getLocalizedfields()->getLocalizedValue($name, $source, true));
            if ($value !== '' && !($html && trim(strip_tags($value)) === '')) {
                $segments[$name] = ['text' => $value, 'html' => $html];
            }
        }
        $editable = DataObjectService::getLanguagePermissions($object, $user, 'lEdit');

        return [
            'source' => $source,
            'segments' => $segments,
            'kept' => !$overwrite && $this->hasContent($object, $fields, $target),
            'editable' => $editable === null || isset($editable[$target]),
        ];
    }

    /**
     * Writes translations from a file (field name => text) into $target, like an API translation:
     * one new version (not published) with a note, and the Supertext history entry.
     *
     * @param array<string, string> $translated
     */
    public function writeTranslations(Concrete $object, string $source, string $target, array $translated, User $user): void
    {
        $object = $this->latest($object, $user);
        $editable = DataObjectService::getLanguagePermissions($object, $user, 'lEdit');
        if ($editable !== null && !isset($editable[$target])) {
            throw new SupertextException('You are not allowed to edit this language.', key: 'language-not-allowed');
        }
        $fields = $this->fields($object);
        foreach ($translated as $name => $value) {
            if (isset($fields[$name]) && trim($value) !== '') {
                $object->getLocalizedfields()->setLocalizedValue($name, $value, $target);
            }
        }
        $object->setUserModification($user->getId());
        $object->saveVersion(true, true, sprintf('Translation file imported (Supertext): %s → %s', $source, $target));
        $this->history->record($object, $source, $target, $user->getId());
    }

    /** True if any translatable field has text in the language (fallback values ignored). */
    private function hasContent(Concrete $object, array $fields, string $language): bool
    {
        foreach (array_keys($fields) as $name) {
            $value = $object->getLocalizedfields()->getLocalizedValue($name, $language, true);
            if (\is_string($value) && trim(strip_tags($value)) !== '') {
                return true;
            }
        }

        return false;
    }

    private function latest(Concrete $object, User $user): Concrete
    {
        $latest = $object->getLatestVersion($user->getId())?->getData();

        return $latest instanceof Concrete ? $latest : $object;
    }
}
