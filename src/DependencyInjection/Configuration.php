<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\DependencyInjection;

use Supertext\PimcoreTranslationBundle\Api\SupertextClient;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 *   supertext_translation:
 *       environment: live            # live | staging | testing
 *       api_url: ''                  # custom base URL; SUPERTEXT_API_URL wins
 *       review_url: '<Supertext's review service>'  # for languages with Translation review; '' = off; SUPERTEXT_REVIEW_URL wins
 *       timeout: 180                 # seconds per language
 *       version_reports: true        # "Send version information to Supertext"; SUPERTEXT_VERSION_REPORTS wins
 *       languages:                   # overrides of Supertext → Settings, per Pimcore language: the
 *           de_CH: { politeness: more }  # Supertext code, the tone and Translation review
 *           fr: { code: fr-CH, politeness: less, review: true }
 *           en: { code: en-US }
 *       object_field_types: [input, textarea, wysiwyg]
 *       document_editable_types: [input, textarea, wysiwyg, link]
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('supertext_translation');
        $tree->getRootNode()
            ->children()
                ->enumNode('environment')->values(['live', 'staging', 'testing'])->defaultValue('live')->end()
                ->scalarNode('api_url')->defaultValue('')->end()
                ->scalarNode('review_url')->defaultValue(SupertextClient::REVIEW_URL)->end()
                ->integerNode('timeout')->min(10)->defaultValue(180)->end()
                ->floatNode('poll_interval')->min(0)->defaultValue(2.0)->end()
                ->booleanNode('version_reports')->defaultTrue()->end()
                ->arrayNode('languages')
                    ->useAttributeAsKey('language')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('code')->defaultValue('')->end()
                            ->enumNode('politeness')->values(['', 'default', 'more', 'less'])->defaultValue('')->end()
                            ->enumNode('review')->values([null, true, false])->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('object_field_types')->scalarPrototype()->end()->defaultValue(['input', 'textarea', 'wysiwyg'])->end()
                ->arrayNode('document_editable_types')->scalarPrototype()->end()->defaultValue(['input', 'textarea', 'wysiwyg', 'link'])->end()
            ->end();

        return $tree;
    }
}
