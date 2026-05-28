<?php

declare(strict_types=1);

namespace App\Chat\Controller;

use App\Chat\Dto\ChatMessage;
use App\Chat\Dto\ChatRequest;
use App\Chat\Entity\ChatOutcome;
use App\Chat\Exception\GeminiException;
use App\Chat\Exception\QuotaExceededException;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Service\ChatLogger;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\GeminiClient;
use App\Chat\Service\HCaptchaVerifier;
use App\Chat\Service\PromptBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Single-action controller backing the public `/api/chat` endpoint.
 *
 * Validates hCaptcha and per-IP rate limits, deserializes/validates the
 * payload, runs retrieval against the chat knowledge base, then streams the
 * Gemini answer back to the client as Server-Sent Events.
 *
 * As a defense against prompt-leak / prompt-injection attacks, the
 * accumulated answer is scanned **during** streaming for a distinctive
 * snippet of the system prompt ({@see self::SYSTEM_PROMPT_LEAK_MARKER}).
 * The moment the marker appears, the generator is abandoned (no further
 * tokens are emitted), the stream is closed cleanly with a `done` event,
 * and the audit log records the request as
 * {@see ChatOutcome::InternalError} so operators can investigate. Tokens
 * already flushed before detection are not recallable — the early
 * termination is best-effort but cuts off any continued leak.
 */
final readonly class ChatController
{
    private const string SYSTEM_PROMPT_LEAK_MARKER = 'développeuse back spécialisée Symfony et Drupal. Tu réponds UNIQUEMENT';

    public function __construct(
        private HCaptchaVerifier $hcaptcha,
        private RateLimiterFactory $chatPerIpLimiter,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private EmbeddingService $embeddings,
        private ChatChunkRepository $chunkRepo,
        private PromptBuilder $promptBuilder,
        private GeminiClient $gemini,
        private ChatLogger $logger,
        #[Autowire(value: '%env(int:CHAT_RETRIEVAL_K)%')]
        private int $retrievalK,
        #[Autowire(value: '%env(float:CHAT_RETRIEVAL_THRESHOLD)%')]
        private float $retrievalThreshold,
    ) {
    }

    /**
     * Handles a chat request and returns either a JSON error or the SSE stream.
     *
     * @throws AccessDeniedHttpException     When hCaptcha verification fails
     * @throws TooManyRequestsHttpException  When the per-IP rate limit is exceeded
     */
    public function __invoke(Request $request): Response
    {
        if (!$this->hcaptcha->verify($request->headers->get('X-HCaptcha-Token'))) {
            $this->logger->log('', '', 0.0, null, ChatOutcome::ValidationError);
            throw new AccessDeniedHttpException('hCaptcha verification failed');
        }

        $limiter = $this->chatPerIpLimiter->create($request->getClientIp() ?? 'unknown');
        $hit = $limiter->consume();
        if (!$hit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                (int) ($hit->getRetryAfter()->getTimestamp() - time()),
                'Rate limit exceeded',
            );
        }

        try {
            $dto = $this->serializer->deserialize(
                $request->getContent(),
                ChatRequest::class,
                'json',
            );
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $this->logger->log($dto->question, '', 0.0, null, ChatOutcome::ValidationError);
            return new JsonResponse(['error' => 'validation failed'], Response::HTTP_BAD_REQUEST);
        }

        return $this->stream($dto);
    }

    /**
     * Builds the SSE response that embeds the question, runs retrieval, then
     * streams Gemini tokens. Quota/Gemini failures are caught inside the
     * callback and reported as `error` SSE events — the response itself does
     * not throw.
     */
    private function stream(ChatRequest $dto): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        $response->setCallback(function () use ($dto): void {
            try {
                $embedding = $this->embeddings->embed($dto->question);
            } catch (QuotaExceededException) {
                $this->emitError('quota_exceeded');
                $this->logger->log($dto->question, '', 0.0, null, ChatOutcome::QuotaExceeded);
                return;
            } catch (GeminiException) {
                $this->emitError('internal_error');
                $this->logger->log($dto->question, '', 0.0, null, ChatOutcome::InternalError);
                return;
            }

            $scored = $this->chunkRepo->findTopK($embedding, $this->retrievalK);
            $topScore = $scored[0]->score ?? 0.0;

            if ($topScore < $this->retrievalThreshold) {
                $msg = "Désolée, je ne sais pas répondre à ça. Je suis l'assistant de Caroline Chapeau.";
                $this->emitChunk($msg);
                $this->emitDone(ChatOutcome::OffScope);
                $this->logger->log($dto->question, $msg, $topScore, null, ChatOutcome::OffScope);
                return;
            }

            $chunks = array_map(static fn ($s) => $s->chunk, $scored);
            $keys = array_map(static fn ($c) => $c->getSourceKey(), $chunks);
            $history = array_map(
                static fn (array $m): ChatMessage => new ChatMessage($m['role'], $m['content']),
                $dto->history,
            );

            $prompt = $this->promptBuilder->build($chunks, $history, $dto->question);

            $full = '';
            try {
                foreach ($this->gemini->streamGenerate($prompt) as $token) {
                    $full .= $token;
                    $this->emitChunk($token);
                    if (str_contains($full, self::SYSTEM_PROMPT_LEAK_MARKER)) {
                        $this->logger->log($dto->question, $full, $topScore, $keys, ChatOutcome::InternalError);
                        $this->emitDone(ChatOutcome::Answered);
                        return;
                    }
                }
            } catch (QuotaExceededException) {
                $this->emitError('quota_exceeded');
                $this->logger->log($dto->question, $full, $topScore, $keys, ChatOutcome::QuotaExceeded);
                return;
            } catch (GeminiException) {
                $this->emitError('internal_error');
                $this->logger->log($dto->question, $full, $topScore, $keys, ChatOutcome::InternalError);
                return;
            }

            $this->emitDone(ChatOutcome::Answered);
            $this->logger->log($dto->question, $full, $topScore, $keys, ChatOutcome::Answered);
        });

        return $response;
    }

    /**
     * Emits a single SSE `chunk` event carrying one token of the assistant response.
     */
    private function emitChunk(string $token): void
    {
        echo "event: chunk\n";
        echo 'data: ' . json_encode(['token' => $token], JSON_THROW_ON_ERROR) . "\n\n";
        $this->flush();
    }

    /**
     * Emits the terminal SSE `done` event with the final {@see ChatOutcome}.
     */
    private function emitDone(ChatOutcome $outcome): void
    {
        echo "event: done\n";
        echo 'data: ' . json_encode(['outcome' => $outcome->value], JSON_THROW_ON_ERROR) . "\n\n";
        $this->flush();
    }

    /**
     * Emits an SSE `error` event with a machine-readable reason and a
     * user-friendly French message picked from a small known set.
     */
    private function emitError(string $reason): void
    {
        $messages = [
            'quota_exceeded' => "L'assistant est très sollicité aujourd'hui et n'est plus disponible. Réessaie demain ou utilise le formulaire de contact.",
            'internal_error' => 'Une erreur est survenue, réessaie dans un instant.',
        ];
        echo "event: error\n";
        echo 'data: ' . json_encode(
            ['reason' => $reason, 'message' => $messages[$reason] ?? 'Une erreur est survenue.'],
            JSON_THROW_ON_ERROR,
        ) . "\n\n";
        $this->flush();
    }

    /**
     * Flushes the output buffer so SSE events reach the client immediately.
     */
    private function flush(): void
    {
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
