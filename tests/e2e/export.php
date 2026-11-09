<?php

/**
 * End-to-end test helper, run inside the demo container by live.mjs:
 *
 *   docker cp tests/e2e/export.php demo-live:/tmp/e2e-export.php
 *   docker exec -u www-data -w /app/demo/project demo-live php /tmp/e2e-export.php
 *
 * Prints the demo's English pages, their linked translations and the sample article as JSON,
 * each at its latest version (what an editor sees in Studio: the bundle saves translations of
 * existing documents and of data objects as new versions, not published). Read only.
 */

declare(strict_types=1);

use Pimcore\Model\DataObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element\Note;
use Pimcore\Tool;

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require '/app/demo/project/vendor/autoload.php';
\Pimcore\Bootstrap::startupCli();

Document::setHideUnpublished(false);
DataObject::setHideUnpublished(false);
DataObject::setGetInheritedValues(false);
DataObject\Localizedfield::setGetFallbackValues(false);

/** The latest version of an element (drafts included), else the element itself. */
function latest(object $element): object
{
    $data = $element->getLatestVersion(null, true)?->getData();

    return is_object($data) && get_class($data) === get_class($element) ? $data : $element;
}

/** @return array<string, mixed> */
function page(Document\PageSnippet $document): array
{
    $latest = latest($document);
    $editables = [];
    foreach ($latest->getEditables() as $name => $editable) {
        $editables[$name] = ['type' => $editable->getType(), 'data' => $editable->getData()];
    }

    return [
        'id' => $document->getId(),
        'path' => $document->getRealFullPath(),
        'published' => $document->isPublished(),
        'language' => $document->getProperty('language'),
        'title' => $latest instanceof Document\Page ? $latest->getTitle() : null,
        'description' => $latest instanceof Document\Page ? $latest->getDescription() : null,
        'navigation_name' => $latest->getProperty('navigation_name'),
        'navigation_title' => $latest->getProperty('navigation_title'),
        'editables' => $editables,
        'versions' => count($document->getVersions()),
    ];
}

$languages = Tool::getValidLanguages();
$service = new Document\Service();
$out = ['languages' => $languages, 'pages' => [], 'object' => null];

// The English pages: /en and everything below it.
$queue = array_filter([Document::getByPath('/en')]);
while ($queue !== []) {
    $document = array_shift($queue);
    if (!$document instanceof Document\PageSnippet) {
        continue;
    }
    $translations = [];
    foreach ($service->getTranslations($document) as $language => $id) {
        $translation = Document::getById((int) $id);
        if ($translation instanceof Document\PageSnippet && $translation->getId() !== $document->getId()) {
            $translations[$language] = page($translation);
        }
    }
    $out['pages'][] = ['source' => page($document), 'translations' => $translations];
    foreach ($document->getChildren(true) as $child) {
        $queue[] = $child;
    }
}

$object = DataObject::getByPath('/Articles/swiss-chocolate');
if ($object instanceof DataObject\Concrete) {
    $latest = latest($object);
    $fields = ['name', 'summary', 'body'];
    $values = static function (DataObject\Concrete $o) use ($fields, $languages): array {
        $v = [];
        foreach ($languages as $language) {
            foreach ($fields as $field) {
                $v[$language][$field] = $o->{'get' . ucfirst($field)}($language);
            }
        }

        return $v;
    };
    $out['object'] = [
        'id' => $object->getId(),
        'published' => $object->isPublished(),
        'fields' => $fields,
        'latest' => $values($latest),
        'stored' => $values($object),
        'versions' => count($object->getVersions()),
    ];
}

$documents = new Document\Listing();
$documents->setUnpublished(true);
$objects = new DataObject\Listing();
$objects->setUnpublished(true);
$objects->setCondition("className = 'Article'");
$notes = new Note\Listing();
$notes->setCondition("type = 'supertext'");
$out['counts'] = ['documents' => $documents->getTotalCount(), 'articles' => $objects->getTotalCount(), 'notes' => $notes->getTotalCount()];

echo "@@E2E@@\n" . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
