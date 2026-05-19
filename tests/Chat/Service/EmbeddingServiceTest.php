<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Exception\GeminiException;
use App\Chat\Exception\QuotaExceededException;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\QuotaGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EmbeddingServiceTest extends TestCase
{
    public function testEmbedsSingleText(): void
    {
        $payload = json_encode(['embedding' => ['values' => [0.1, 0.2, 0.3]]], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([new MockResponse($payload)]);
        $service = new EmbeddingService(
            $client,
            new QuotaGuard(new ArrayAdapter(), limit: 100),
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'text-embedding-004',
        );

        self::assertSame([0.1, 0.2, 0.3], $service->embed('hello'));
    }

    public function testBatchEmbedReturnsParallelVectors(): void
    {
        $payload = json_encode([
            'embeddings' => [
                ['values' => [0.1, 0.2]],
                ['values' => [0.3, 0.4]],
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([new MockResponse($payload)]);
        $service = new EmbeddingService(
            $client,
            new QuotaGuard(new ArrayAdapter(), limit: 100),
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'text-embedding-004',
        );

        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $service->embedBatch(['a', 'b']));
    }

    public function testThrowsQuotaExceededOn429(): void
    {
        $client = new MockHttpClient([new MockResponse('quota', ['http_code' => 429])]);
        $service = new EmbeddingService(
            $client,
            new QuotaGuard(new ArrayAdapter(), limit: 100),
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'text-embedding-004',
        );

        $this->expectException(QuotaExceededException::class);
        $service->embed('hello');
    }

    public function testBlocksWhenQuotaGuardExhausted(): void
    {
        $client = new MockHttpClient();
        $guard = new QuotaGuard(new ArrayAdapter(), limit: 1);
        $guard->exhaust();
        $service = new EmbeddingService(
            $client,
            $guard,
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'text-embedding-004',
        );

        $this->expectException(QuotaExceededException::class);
        $service->embed('hello');
    }

    public function testThrowsGeminiOnUnexpectedShape(): void
    {
        $client = new MockHttpClient([new MockResponse('{"oops":1}')]);
        $service = new EmbeddingService(
            $client,
            new QuotaGuard(new ArrayAdapter(), limit: 100),
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'text-embedding-004',
        );

        $this->expectException(GeminiException::class);
        $service->embed('hello');
    }
}
