<?php

namespace Supertext\PimcoreTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PimcoreTranslationBundle\Api\SupertextClient;
use Supertext\PimcoreTranslationBundle\Api\SupertextException;

final class SupertextClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /** @param callable(string, string, ?string): array{status: int, body: string, headers?: array<string, string>} $handler */
    private function client(callable $handler, string $key = 'test-key'): SupertextClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use ($handler): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return $handler($method, $url, $body) + ['headers' => []];
        };

        return new SupertextClient($key, 'https://api.test/v1', $transport, 10, 0.01, static function (): void {
        });
    }

    public function testRunsTheFullProtocol(): void
    {
        $polls  = 0;
        $client = $this->client(function (string $method, string $url) use (&$polls): array {
            return match (true) {
                $method === 'POST'                    => ['status' => 200, 'body' => '{"file_id":"f1"}'],
                str_ends_with($url, '/status')        => ['status' => 200, 'body' => json_encode(['status' => ++$polls < 2 ? 'running' : 'done'])],
                str_ends_with($url, '/translation')   => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>'],
                default                               => ['status' => 200, 'body' => '{}'],
            };
        });

        $html = $client->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en', 'more');

        self::assertStringContainsString('Hallo', $html);
        self::assertSame(
            ['POST translate/ai/file', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/translation', 'DELETE translate/ai/file/f1'],
            array_map(static fn (array $c): string => $c['method'] . ' ' . str_replace('https://api.test/v1/', '', $c['url']), $this->calls)
        );

        $post = $this->calls[0];
        self::assertSame('Supertext-Auth-Key test-key', $post['headers']['Authorization']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $post['headers']['Content-Type']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH\r\n", $post['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", $post['body']);
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore\r\n", $post['body']);
        self::assertStringContainsString("filename=\"content.html\"\r\nContent-Type: text/html\r\n\r\n<div", $post['body']);
    }

    public function testSendsTheCodesAsGiven(): void
    {
        $client = $this->client(fn (string $method, string $url) => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hola</div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        });

        // No default regions any more: "es" is the Supertext code for Spanish.
        $client->translateDocument('<div data-st-id="0">Hello</div>', 'es', 'en');

        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nes\r\n", $this->calls[0]['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", $this->calls[0]['body']);
    }

    public function testReportsAnUnsupportedTone(): void
    {
        $client = $this->client(fn () => ['status' => 400, 'body' => '{"error_code":"FEATURE_ERROR","feature":"politeness","source_lang":"de","target_lang":"en-US"}']);

        try {
            $client->translateDocument('<p>x</p>', 'en-US', 'de', 'more');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('unsupported-feature', $e->key);
        }
    }

    public function testChecksTheKeyWithoutThrowing(): void
    {
        $client = $this->client(fn () => ['status' => 401, 'body' => '{"error_code":"UNAUTHORIZED"}']);
        $check = $client->checkApiKey();

        self::assertSame('invalid', $check->status);
        self::assertSame(401, $check->httpStatus);
        self::assertStringEndsWith('/v1/features', $this->calls[0]['url']);

        self::assertSame('missing', $this->client(fn () => ['status' => 200, 'body' => '{}'], '')->checkApiKey()->status);
    }

    public function testAcceptsTheKeyWithItsPrefix(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '  Supertext-Auth-Key abc+/= ');
        $client->validateApiKey();

        self::assertSame('Supertext-Auth-Key abc+/=', $this->calls[0]['headers']['Authorization']);
    }

    public function testRetriesWhenRateLimited(): void
    {
        $limited = 2;
        $client  = $this->client(function () use (&$limited): array {
            return $limited-- > 0 ? ['status' => 429, 'body' => 'slow down'] : ['status' => 200, 'body' => '{}'];
        });
        $client->validateApiKey();

        self::assertCount(3, $this->calls);
    }

    public function testGivesUpAfterFourRetries(): void
    {
        $client = $this->client(fn () => ['status' => 429, 'body' => 'slow down']);

        try {
            $client->validateApiKey();
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('Too many requests', $e->getMessage());
            self::assertSame('rate-limited', $e->key);
        }

        self::assertCount(5, $this->calls);
    }

    public function testHonoursRetryAfter(): void
    {
        self::assertSame(3.0, SupertextClient::retryDelay(0, '3'));
        self::assertGreaterThanOrEqual(2.0, SupertextClient::retryDelay(1));
    }

    public function testExplainsAuthenticationErrors(): void
    {
        $client = $this->client(fn () => ['status' => 401, 'body' => '{"detail":"bad key"}']);

        $this->expectException(SupertextException::class);
        $this->expectExceptionMessageMatches('/Authentication failed.*bad key/');
        $client->translateDocument('<p>x</p>', 'fr-FR');
    }

    public function testStopsOnLimitExceededAndCleansUp(): void
    {
        $client = $this->client(function (string $method, string $url): array {
            return match (true) {
                $method === 'POST'             => ['status' => 200, 'body' => '{"file_id":"f2"}'],
                str_ends_with($url, '/status') => ['status' => 200, 'body' => '{"status":"limit_exceeded"}'],
                default                        => ['status' => 200, 'body' => '{}'],
            };
        });

        try {
            $client->translateDocument('<p>x</p>', 'fr-FR');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('limit is exceeded', $e->getMessage());
            self::assertSame('limit-exceeded', $e->key);
        }

        self::assertSame('DELETE', end($this->calls)['method']);
    }

    public function testRefusesToRunWithoutKey(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '');

        self::assertFalse($client->hasApiKey());
        $this->expectException(SupertextException::class);
        $client->validateApiKey();
    }

    public function testTranslatesSegmentsAndToleratesMissingOnes(): void
    {
        $client = $this->client(fn (string $method, string $url) => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hallo<br>Welt</div><div data-st-id="1"><p>Ein <b>Text</b></p></div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        });

        $result = $client->translateSegments([
            3 => ['text' => "Hello\nworld", 'html' => false],
            5 => ['text' => '<p>A <b>text</b></p>', 'html' => true],
            7 => ['text' => '   ', 'html' => false],
            9 => ['text' => 'Lost', 'html' => false],
        ], 'de-CH', 'en');

        self::assertSame([3 => "Hallo\nWelt", 5 => '<p>Ein <b>Text</b></p>'], $result);
        self::assertStringContainsString('<div data-st-id="0">Hello<br>world</div>', (string) $this->calls[0]['body']);
        self::assertStringContainsString('<div data-st-id="2">Lost</div>', (string) $this->calls[0]['body']);
    }

    public function testSplitsLargeContentIntoSeveralDocuments(): void
    {
        $posts = 0;
        $client = $this->client(function (string $method, string $url) use (&$posts) {
            return match (true) {
                $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f' . ++$posts . '"}'],
                str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
                str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">x</div>'],
                default                             => ['status' => 200, 'body' => '{}'],
            };
        });

        $client->translateSegments([['text' => str_repeat('a', 8), 'html' => false], ['text' => str_repeat('b', 8), 'html' => false]], 'de-DE', '', 'default', 10);

        self::assertSame(2, $posts);
    }

    public function testReportsAnUnsupportedLanguagePair(): void
    {
        $client = $this->client(fn () => ['status' => 400, 'body' => '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx-YY"}']);

        try {
            $client->translateDocument('<p>x</p>', 'xx-YY', 'en');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('invalid-language-pair', $e->key);
            self::assertSame(['source' => 'en', 'target' => 'xx-YY'], $e->params);
            self::assertStringStartsWith('Supertext does not support this language pair (en → xx-YY).', $e->getMessage());
        }
    }

    public function testEnvironments(): void
    {
        self::assertSame(SupertextClient::LIVE, SupertextClient::baseUrlFor('live'));
        self::assertSame(SupertextClient::STAGING, SupertextClient::baseUrlFor('staging'));
        self::assertSame('http://127.0.0.1:8765/v1/', SupertextClient::baseUrlFor('live', 'http://127.0.0.1:8765/v1/'));
    }

    /** @return callable(string, string, ?string): array{status: int, body: string} a Supertext that answers every call */
    private static function supertext(): callable
    {
        return static fn (string $method, string $url): array => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>'],
            default                             => ['status' => 200, 'body' => '{"languages":[]}'],
        };
    }

    private function reviewClient(string $reviewUrl): SupertextClient
    {
        $handler = self::supertext();
        $transport = function (string $method, string $url, array $headers, ?string $body) use ($handler): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return $handler($method, $url, $body) + ['headers' => []];
        };

        return new SupertextClient('test-key', 'https://api.test/v1/', $transport, 10, 0.01, static function (): void {
        }, 'Pimcore-Supertext-Translation/1.2.0', $reviewUrl);
    }

    public function testTranslationReviewGoesThroughTheReviewService(): void
    {
        $client = $this->reviewClient('https://review.test/v1/');
        self::assertSame($client, $client->forReview(false));
        $review = $client->forReview(true);
        self::assertNotSame($client, $review);
        self::assertSame($review, $client->forReview(true), 'one review client');

        self::assertStringContainsString('Hallo', $review->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en'));
        self::assertSame(
            ['POST translate/ai/file', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/translation', 'DELETE translate/ai/file/f1'],
            array_map(static fn (array $c): string => $c['method'] . ' ' . str_replace('https://review.test/v1/', '', $c['url']), $this->calls),
            'upload, status, download and delete all at the review service'
        );
        foreach ($this->calls as $call) {
            self::assertSame('Supertext-Auth-Key test-key', $call['headers']['Authorization'], 'the same key');
            self::assertStringContainsString('Pimcore-Supertext-Translation/1.2.0', $call['headers']['User-Agent'], 'the same User-Agent, with the version');
        }

        // Other languages and the key check go directly to Supertext.
        $this->calls = [];
        $client->translateDocument('<div data-st-id="0">Hello</div>', 'fr-CH', 'en');
        $client->checkApiKey();
        foreach ($this->calls as $call) {
            self::assertStringStartsWith('https://api.test/v1/', $call['url']);
        }
        self::assertCount(5, $this->calls);
    }

    public function testTranslationReviewOffWithoutReviewUrl(): void
    {
        $client = $this->reviewClient('');
        self::assertSame($client, $client->forReview(true), 'empty review_url: review off for every language');

        $client = $this->reviewClient(' https://api.test/v1 ');
        self::assertSame($client, $client->forReview(true), 'the same address as the API: no second client');

        self::assertSame('https://localizationreview-production.up.railway.app/v1/', SupertextClient::REVIEW_URL);
    }
}
