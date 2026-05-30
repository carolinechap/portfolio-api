<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Exception\GeminiException;
use App\Chat\Exception\QuotaExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly QuotaGuard $quotaGuard,
        #[Autowire(value: '%env(string:GEMINI_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire(value: '%env(string:GEMINI_API_BASE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire(value: '%env(string:GEMINI_GENERATION_MODEL)%')]
        private readonly string $model,
    ) {
    }

    /** @return \Generator<int, string> */
    public function streamGenerate(string $prompt): \Generator
    {
        if (!$this->quotaGuard->canCall()) {
            throw new QuotaExceededException('Daily Gemini quota exhausted');
        }

        $this->quotaGuard->recordCall();

        $url = \sprintf(
            '%s/models/%s:streamGenerateContent?alt=sse',
            rtrim($this->baseUrl, '/'),
            $this->model,
        );

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'x-goog-api-key' => $this->apiKey,
                    'Accept' => 'text/event-stream',
                ],
                'json' => [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                ],
                'timeout' => 60,
                'buffer'  => false,
            ]);
            $status = $response->getStatusCode();
            if ($status === 429) {
                $this->quotaGuard->exhaust();
                throw new QuotaExceededException('Gemini returned 429');
            }
            if ($status >= 400) {
                throw new GeminiException(\sprintf('Gemini stream HTTP %d', $status));
            }

            $buffer = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $buffer .= $chunk->getContent();
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = rtrim($line, "\r");
                    if ($line === '' || !str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $json = trim(substr($line, 5));
                    if ($json === '' || $json === '[DONE]') {
                        continue;
                    }
                    try {
                        $event = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        continue;
                    }
                    if (!is_array($event)) {
                        continue;
                    }
                    $candidates = $event['candidates'] ?? null;
                    if (!is_array($candidates)) {
                        continue;
                    }
                    foreach ($candidates as $candidate) {
                        if (!is_array($candidate)) {
                            continue;
                        }
                        $content = $candidate['content'] ?? null;
                        $parts = is_array($content) ? ($content['parts'] ?? null) : null;
                        if (!is_array($parts)) {
                            continue;
                        }
                        foreach ($parts as $part) {
                            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                                yield $part['text'];
                            }
                        }
                    }
                }
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new GeminiException('Gemini stream transport error', previous: $e);
        }
    }
}
