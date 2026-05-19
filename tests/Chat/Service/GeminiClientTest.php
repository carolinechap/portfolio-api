<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Exception\GeminiException;
use App\Chat\Exception\QuotaExceededException;
use App\Chat\Service\GeminiClient;
use App\Chat\Service\QuotaGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeminiClientTest extends TestCase
{
    public function testStreamGenerateYieldsConcatenatedTokens(): void
    {
        $body = implode("\n", [
            'data: {"candidates":[{"content":{"parts":[{"text":"Hello "}]}}]}',
            '',
            'data: {"candidates":[{"content":{"parts":[{"text":"world"}]}}]}',
            '',
            'data: {"candidates":[{"content":{"parts":[{"text":"!"}]}}]}',
            '',
        ]);
        $client = new MockHttpClient([new MockResponse($body)]);
        $g = new GeminiClient(
            $client,
            new QuotaGuard(new ArrayAdapter(), limit: 100),
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'gemini-2.0-flash',
        );

        $tokens = iterator_to_array($g->streamGenerate('prompt'), false);

        self::assertSame(['Hello ', 'world', '!'], $tokens);
    }

    public function testStreamGenerateThrowsQuotaOn429(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 429])]);
        $g = new GeminiClient(
            $client,
            new QuotaGuard(new ArrayAdapter(), limit: 100),
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'gemini-2.0-flash',
        );

        $this->expectException(QuotaExceededException::class);
        iterator_to_array($g->streamGenerate('prompt'));
    }

    public function testBlocksWhenQuotaExhausted(): void
    {
        $client = new MockHttpClient();
        $guard = new QuotaGuard(new ArrayAdapter(), limit: 1);
        $guard->exhaust();
        $g = new GeminiClient(
            $client,
            $guard,
            apiKey: 'k',
            baseUrl: 'https://gen.test/v1beta',
            model: 'gemini-2.0-flash',
        );

        $this->expectException(QuotaExceededException::class);
        iterator_to_array($g->streamGenerate('prompt'));
    }
}
