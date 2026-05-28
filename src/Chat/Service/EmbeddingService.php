<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Exception\GeminiException;
use App\Chat\Exception\QuotaExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client over the Gemini batchEmbedContents endpoint.
 *
 * Always asks the model for {@see self::OUTPUT_DIM}-dimensional vectors
 * (Matryoshka-truncated) and routes every call through {@see QuotaGuard}.
 */
class EmbeddingService
{
    private const int OUTPUT_DIM = 768;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly QuotaGuard $quotaGuard,
        #[Autowire(value: '%env(string:GEMINI_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire(value: '%env(string:GEMINI_API_BASE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire(value: '%env(string:GEMINI_EMBEDDING_MODEL)%')]
        private readonly string $model,
    ) {
    }

    /**
     * Embeds a single text via the Gemini batchEmbedContents endpoint.
     *
     * @return float[] Vector of 768 floats (Matryoshka-truncated output)
     *
     * @throws QuotaExceededException When the daily Gemini quota is exhausted or the API returns 429
     * @throws GeminiException        On transport errors or unexpected response shape
     */
    public function embed(string $text): array
    {
        $vectors = $this->call([$text]);

        return $vectors[0];
    }

    /**
     * Embeds a batch of texts in a single API call.
     *
     * Returns an empty array when $texts is empty (no API call is made).
     *
     * @param  string[]  $texts
     * @return float[][] One vector per input text, in the same order
     *
     * @throws QuotaExceededException When the daily Gemini quota is exhausted or the API returns 429
     * @throws GeminiException        On transport errors or unexpected response shape
     */
    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return $this->call($texts);
    }

    /**
     * Performs the actual HTTP call to the Gemini batchEmbedContents endpoint.
     *
     * @param  string[]  $texts
     * @return float[][] One vector per input text
     *
     * @throws QuotaExceededException When the daily quota is exhausted or the API returns 429
     * @throws GeminiException        On transport errors or unexpected response shape
     */
    private function call(array $texts): array
    {
        if (!$this->quotaGuard->canCall()) {
            throw new QuotaExceededException('Daily Gemini quota exhausted');
        }

        $this->quotaGuard->recordCall();

        $url = \sprintf('%s/models/%s:batchEmbedContents', rtrim($this->baseUrl, '/'), $this->model);
        $payload = [
            'requests' => array_map(
                fn (string $t): array => [
                    'model' => 'models/' . $this->model,
                    'content' => ['parts' => [['text' => $t]]],
                    'outputDimensionality' => self::OUTPUT_DIM,
                ],
                $texts,
            ),
        ];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['x-goog-api-key' => $this->apiKey],
                'json'    => $payload,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if ($status === 429) {
                $this->quotaGuard->exhaust();
                throw new QuotaExceededException('Gemini returned 429');
            }
            if ($status >= 400) {
                throw new GeminiException(\sprintf('Gemini embeddings HTTP %d', $status));
            }
            $data = $response->toArray(false);
        } catch (HttpClientExceptionInterface | \JsonException $e) {
            throw new GeminiException('Gemini embeddings transport error', previous: $e);
        }

        $embeddings = $data['embeddings'] ?? null;
        if (!is_array($embeddings)) {
            $embedding = $data['embedding'] ?? null;
            if (is_array($embedding) && isset($embedding['values']) && is_array($embedding['values'])) {
                return [self::toFloatVector($embedding['values'])];
            }
            throw new GeminiException('Unexpected Gemini embeddings response shape');
        }

        return array_map(
            static function (mixed $entry): array {
                if (!is_array($entry) || !isset($entry['values']) || !is_array($entry['values'])) {
                    throw new GeminiException('Missing values in embedding entry');
                }

                return self::toFloatVector($entry['values']);
            },
            $embeddings,
        );
    }

    /**
     * Coerces a decoded JSON array to a plain float vector, failing loudly on
     * any non-numeric entry.
     *
     * @param  array<mixed> $values Raw "values" array returned by Gemini
     * @return float[]
     *
     * @throws GeminiException When any element is not int or float
     */
    private static function toFloatVector(array $values): array
    {
        return array_map(
            static function (mixed $v): float {
                if (!is_int($v) && !is_float($v)) {
                    throw new GeminiException('Embedding vector contains a non-numeric value');
                }

                return (float) $v;
            },
            $values,
        );
    }
}
