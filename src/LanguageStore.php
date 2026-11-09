<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle;

use Pimcore\Model\Tool\SettingsStore;

/**
 * The language mapping chosen on Studio's *Supertext* settings screen, in Pimcore's settings store
 * (table `settings_store`, scope `supertext_translation`, id `languages`, JSON; see Api\LanguageMapping).
 */
class LanguageStore
{
    public const SCOPE = 'supertext_translation';
    public const ID = 'languages';

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @return array<string, mixed> language => {code, politeness, review}; empty while nothing is saved or the store can't be read */
    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $item = SettingsStore::get(self::ID, self::SCOPE);
            $data = $item === null ? null : json_decode((string) $item->getData(), true);
        } catch (\Throwable) {
            // No database yet (installer, cache warm-up): the bundle configuration and the defaults apply.
            return [];
        }

        return $this->cache = \is_array($data) ? $data : [];
    }

    /** @param array<string, array{code: string, politeness: string, review: bool}> $languages */
    public function save(array $languages): void
    {
        SettingsStore::set(self::ID, (string) json_encode($languages, JSON_UNESCAPED_SLASHES), SettingsStore::TYPE_STRING, self::SCOPE);
        $this->cache = $languages;
    }
}
