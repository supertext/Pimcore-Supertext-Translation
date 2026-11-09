<?php

declare(strict_types=1);

namespace Supertext\PimcoreTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PimcoreTranslationBundle\Api\FileElements;
use Supertext\PimcoreTranslationBundle\Api\FileExchange;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;
use Supertext\PimcoreTranslationBundle\Sdk\Files\FileJob;
use Supertext\PimcoreTranslationBundle\Sdk\Files\FileSegment;
use Supertext\PimcoreTranslationBundle\Sdk\Files\TranslationFiles;

/** Export for translation / Import translation against a fake Pimcore. */
final class FileExchangeTest extends TestCase
{
    private FakeElements $elements;

    protected function setUp(): void
    {
        $this->elements = new FakeElements();
    }

    private function exchange(): FileExchange
    {
        return new FileExchange($this->elements, ['de' => 'de-CH']);
    }

    public function testReferenceAndFileName(): void
    {
        self::assertSame('document:12:en:de_CH', FileExchange::reference('document', 12, 'en', 'de_CH'));
        self::assertSame(['type' => 'object', 'id' => 7, 'source' => 'en', 'target' => 'de_CH'], FileExchange::parseReference('object:7:en:de_CH'));

        foreach (['page:12', 'document:0:en:de', 'document:12:en', 'object:x:en:de', 'document:12:en:de:x', 'data-object:1:en:de'] as $bad) {
            self::assertNull(FileExchange::parseReference($bad), $bad);
        }

        self::assertSame('pimcore-document-12-de_CH.xlf', FileExchange::fileName('document', 12, ['de_CH'], 'xliff'));
        self::assertSame('pimcore-object-7.xlf', FileExchange::fileName('object', 7, ['de', 'fr'], 'xliff'));
        self::assertSame('pimcore-object-7-fr.html', FileExchange::fileName('object', 7, ['fr'], 'html'));
        self::assertSame('application/x-xliff+xml', FileExchange::contentType('xliff'));
        self::assertSame('text/html; charset=utf-8', FileExchange::contentType('html'));
    }

    public function testExportUsesTheServicesKeysAndTheMapping(): void
    {
        $file  = $this->exchange()->export('document', 12, 'ignored', ['de', 'xx_YY', 'fr'], 'xliff', false);
        $files = TranslationFiles::read($file['content']);

        self::assertSame(['de', 'xx_YY'], $file['targets'], 'fr already has a translation and overwrite is off');
        self::assertSame('pimcore-document-12.xlf', $file['fileName']);
        self::assertSame(FileExchange::PLUGIN, $files[0]->plugin);
        self::assertSame('document:12:en:de', $files[0]->reference, 'a document is translated from its own language');
        self::assertSame(['en', 'de-CH'], [$files[0]->sourceLanguage, $files[0]->targetLanguage]);
        self::assertSame('xx-YY', $files[1]->targetLanguage, 'an unmapped language never blocks the export');
        self::assertSame(['meta:title', 'editable:content'], array_map(static fn ($u) => $u->key, $files[0]->units));

        $html = $this->exchange()->export('document', 12, '', ['fr'], 'html', true);
        self::assertSame('html', TranslationFiles::read($html['content'])[0]->format);
        self::assertSame('text/html; charset=utf-8', $html['contentType']);
    }

    public function testExportErrors(): void
    {
        foreach ([
            'choose-target'        => fn () => $this->exchange()->export('document', 12, '', [], 'xliff', false),
            'html-one-language'    => fn () => $this->exchange()->export('document', 12, '', ['de', 'fr'], 'html', false),
            'unknown-language'     => fn () => $this->exchange()->export('document', 12, '', ['es'], 'xliff', false),
            'element-not-found'    => fn () => $this->exchange()->export('document', 99, '', ['de'], 'xliff', false),
            'nothing-to-translate' => fn () => $this->exchange()->export('document', 12, '', ['fr'], 'xliff', false),
        ] as $key => $call) {
            try {
                $call();
                self::fail("Expected $key");
            } catch (SupertextException $e) {
                self::assertSame($key, $e->key);
            }
        }
    }

    public function testImportWritesOkSegmentsOnly(): void
    {
        $xliff = $this->exchange()->export('document', 12, '', ['de'], 'xliff', false)['content'];
        $xliff = self::translate($xliff, 'meta:title', 'Schweizer Schokolade');
        $xliff = self::translate($xliff, 'editable:content', '<bpt id="1">p</bpt>Von <bpt id="2">b</bpt>Hand<ept id="2">/b</ept> gemacht.<ept id="1">/p</ept>');

        $jobs = $this->exchange()->import($xliff, false, false);

        self::assertNull($jobs[0]['error']);
        self::assertSame(['document', 12, 'de'], [$jobs[0]['type'], $jobs[0]['id'], $jobs[0]['language']]);
        self::assertSame(2, $jobs[0]['counts']['imported']);
        self::assertSame([['document', 12, 'en', 'de', ['meta:title' => 'Schweizer Schokolade', 'editable:content' => '<p>Von <b>Hand</b> gemacht.</p>']]], $this->elements->written);
    }

    public function testSourceChangedOnlyWithTheOptionAndOthersNeverWritten(): void
    {
        $xliff = $this->exchange()->export('document', 12, '', ['de'], 'xliff', false)['content'];
        $xliff = self::translate($xliff, 'meta:title', 'Schweizer Schokolade');
        $this->elements->texts['meta:title'] = 'Swiss chocolate, shipped worldwide';

        $jobs = $this->exchange()->import($xliff, false, false);
        self::assertSame(['imported' => 0, 'sourceChanged' => 1, 'untranslated' => 1, 'invalidMarkup' => 0, 'missing' => 0, 'kept' => 0], $jobs[0]['counts']);
        self::assertSame([], $this->elements->written);

        $jobs = $this->exchange()->import($xliff, true, false);
        self::assertSame(1, $jobs[0]['counts']['imported']);
        self::assertSame(['meta:title' => 'Schweizer Schokolade'], $this->elements->written[0][4]);
    }

    public function testInvalidMarkupMissingAndKept(): void
    {
        $html = $this->exchange()->export('document', 12, '', ['de'], 'html', false)['content'];
        $html = str_replace('Made <b>by hand</b>.', 'Von <img src=x onerror=alert(1)>Hand.', $html);
        $this->elements->texts['property:navigation_name'] = 'Chocolate';

        $jobs = $this->exchange()->import($html, false, false);
        self::assertSame(1, $jobs[0]['counts']['invalidMarkup']);
        self::assertSame(1, $jobs[0]['counts']['missing']);
        self::assertSame(1, $jobs[0]['counts']['imported'], 'an HTML file imported unchanged writes the source text');
        self::assertSame(['meta:title' => 'Swiss chocolate'], $this->elements->written[0][4]);

        // An existing translation is kept without overwrite.
        $this->elements->written = [];
        $fr = $this->exchange()->export('document', 12, '', ['fr'], 'xliff', true)['content'];
        $jobs = $this->exchange()->import($fr, false, false);
        self::assertSame(['imported' => 0, 'sourceChanged' => 0, 'untranslated' => 0, 'invalidMarkup' => 0, 'missing' => 0, 'kept' => 3], $jobs[0]['counts']);
        self::assertSame([], $this->elements->written);
    }

    public function testJobErrors(): void
    {
        $file  = static fn (string $plugin, string $reference): string => TranslationFiles::exportXliff([new FileJob($plugin, $reference, 'en', 'de-CH', [new FileSegment('meta:title', 'Swiss chocolate')])]);
        $error = fn (string $content): ?array => $this->exchange()->import($content, false, false)[0]['error'];

        $other = $error($file('AtroPIM-Supertext-Translation', 'document:12:en:de'));
        self::assertSame(['file-other-system', ['plugin' => 'AtroPIM-Supertext-Translation']], [$other['key'], $other['params']]);
        self::assertSame('file-not-found', $error($file(FileExchange::PLUGIN, 'page:12'))['key']);
        self::assertSame('file-not-found', $error($file(FileExchange::PLUGIN, 'document:99:en:de'))['key']);
        self::assertSame(['language' => 'es'], $error($file(FileExchange::PLUGIN, 'document:12:en:es'))['params']);

        $this->elements->editable = false;
        self::assertSame('file-not-allowed', $error($file(FileExchange::PLUGIN, 'document:12:en:de'))['key']);

        $this->elements->editable = true;
        $this->elements->fail = new SupertextException('Translate the parent page into this language first.', key: 'parent-missing');
        $xliff = self::translate($file(FileExchange::PLUGIN, 'document:12:en:de'), 'meta:title', 'Schokolade');
        self::assertSame('parent-missing', $error($xliff)['key']);
        self::assertSame([], $this->elements->written);
    }

    public function testFileErrors(): void
    {
        foreach ([
            'file-too-large'        => str_repeat('a', FileExchange::MAX_UPLOAD_BYTES + 1),
            'file-invalid-file'     => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><xliff/>',
            'file-missing-metadata' => '<!doctype html><html><head></head><body><p data-st-id="1">x</p></body></html>',
        ] as $key => $content) {
            try {
                $this->exchange()->import($content, false, false);
                self::fail("Expected $key");
            } catch (SupertextException $e) {
                self::assertSame($key, $e->key);
            }
        }

        self::assertSame(10_000_000, FileExchange::MAX_UPLOAD_BYTES);
    }

    private static function translate(string $xliff, string $key, string $target): string
    {
        return (string) preg_replace_callback(
            '~(<trans-unit id="' . preg_quote($key, '~') . '".*?</source>)~s',
            static fn (array $m): string => $m[1] . '<target>' . $target . '</target>',
            $xliff,
        );
    }
}

/** Pimcore stand-in: document 12 in English, translated into fr; languages en, de, fr, xx_YY. */
final class FakeElements implements FileElements
{
    /** @var array<string, string> */
    public array $texts = ['meta:title' => 'Swiss chocolate', 'editable:content' => '<p>Made <b>by hand</b>.</p>'];

    /** @var list<array{string, int, string, string, array<string, string>}> */
    public array $written = [];

    public bool $editable = true;

    public ?SupertextException $fail = null;

    public function languages(): array
    {
        return ['en', 'de', 'fr', 'xx_YY'];
    }

    public function segments(string $type, int $id, string $source, string $target, bool $overwrite): ?array
    {
        if ($type !== 'document' || $id !== 12) {
            return null;
        }
        $segments = [];
        foreach ($this->texts as $key => $text) {
            $segments[$key] = ['text' => $text, 'html' => str_starts_with($key, 'editable:')];
        }

        return ['source' => 'en', 'segments' => $segments, 'kept' => $target === 'fr' && !$overwrite, 'editable' => $this->editable];
    }

    public function write(string $type, int $id, string $source, string $target, array $translations): void
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }
        $this->written[] = [$type, $id, $source, $target, $translations];
    }
}
