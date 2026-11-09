<?php

/**
 * @package     Supertext Translation for Pimcore
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PimcoreTranslationBundle\Api;

use Supertext\PimcoreTranslationBundle\Sdk\V1\Languages;
use Supertext\PimcoreTranslationBundle\Sdk\V1\SupertextException as SdkException;

/**
 * Pimcore languages → Supertext language codes, defaults only where obvious (the SDK's `Languages`,
 * the same rules in every Supertext plugin):
 *
 * 1. the language mapping (Supertext → Settings in Studio, `supertext_translation.languages.<language>.code`
 *    overriding it; see LanguageMapping), if set;
 * 2. else a Pimcore code that equals a Supertext code apart from case and `_`/`-` (`de_CH` → `de-CH`, `es`);
 * 3. else the SDK's default (`de` → `de-DE`, `en` → `en-US`, `zh_CN` → `zh-Hans`, `es_ES` → `es`);
 * 4. else `unsupported-language`, and nothing is sent (`de_LI`, `en_IE`, `fr_BE` need a mapping).
 *
 * Supertext accepts only the codes its `features` list names: regional targets for de/en/fr/it/pt
 * (`de-CH`, `en-US`, `pt-BR`), `zh-Hans`/`zh-Hant`, `sr-Latn`/`sr-Cyrl`, `gsw-u-sd-chzh`/`chbe`, bare
 * for all others (`es`, `nl`, `sl`). Sources are bare (`en`); one mapping serves both directions
 * (`en` mapped to `en-US` is sent as source `en`).
 */
final class LanguageCodes
{
    /** @return list<array{code: string, name: string, politeness: bool}> the targets Supertext offers */
    public static function targets(): array
    {
        return Languages::TARGETS;
    }

    /**
     * Target code to send.
     *
     * @param array<string, string> $mapping Pimcore language → Supertext code
     *
     * @throws SupertextException key `unsupported-language` when the language is neither mapped nor a Supertext code
     */
    public static function target(string $language, array $mapping = []): string
    {
        try {
            return Languages::target($language, $mapping);
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** The target code, or null when the language isn't mapped (status screens). */
    public static function targetOrNull(string $language, array $mapping = []): ?string
    {
        try {
            return Languages::target($language, $mapping);
        } catch (SdkException) {
            return null;
        }
    }

    /**
     * Source code to send ("" stays "": auto-detect).
     *
     * @param array<string, string> $mapping Pimcore language → Supertext code
     *
     * @throws SupertextException key `unsupported-language`
     */
    public static function source(string $language, array $mapping = []): string
    {
        try {
            return Languages::source($language, $mapping);
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** Whether Supertext offers formal/informal for a target code; null for a code the SDK doesn't list. */
    public static function supportsPoliteness(string $target): ?bool
    {
        return Languages::supportsPoliteness($target);
    }

    /**
     * Politeness to send: "more"/"less" where the target offers it, "default" when none is set.
     *
     * @throws SupertextException key `unsupported-feature` when a tone is set for a target without it (never dropped silently)
     */
    public static function politeness(string $target, string $politeness): string
    {
        if (!\in_array($politeness, ['more', 'less'], true)) {
            return 'default';
        }
        if (Languages::supportsPoliteness($target) === false) {
            throw SupertextClient::fromSdk(new SdkException(SdkException::UNSUPPORTED_FEATURE, targetLang: $target, feature: 'politeness'));
        }

        return $politeness;
    }
}
