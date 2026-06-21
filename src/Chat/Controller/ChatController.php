<?php

declare(strict_types=1);

namespace App\Chat\Controller;

use App\Chat\Dto\ChatRequest;
use App\Chat\Entity\ChatOutcome;
use App\Chat\Http\ProblemResponseFactory;
use App\Chat\Http\SseStreamFactory;
use App\Chat\Service\ChatLogger;
use App\Chat\Service\ChatPipeline;
use App\Chat\Service\ChatSessionTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ChatController
{
    public function __construct(
        private ChatSessionTokenManager $sessionTokens,
        private RateLimiterFactory $chatPerIpLimiter,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private ChatLogger $logger,
        private ChatPipeline $pipeline,
        private SseStreamFactory $sse,
    ) {
    }

    /**
     * Validates the request (session, rate limit, payload), then streams the answer as Server-Sent Events.
     *
     * @param Request $request Incoming chat request
     *
     * @return Response Problem+json on a guard failure, or an SSE StreamedResponse on success
     */
    public function chat(Request $request): Response
    {
        $sessionState = $this->sessionTokens->check($request->headers->get('X-Chat-Session'));
        if ($sessionState !== 'valid') {
            $this->logger->log('', '', 0.0, null, ChatOutcome::ValidationError);

            return ProblemResponseFactory::error(
                Response::HTTP_UNAUTHORIZED,
                'Chat session token missing, invalid or expired.',
                [],
                ['reason' => $sessionState === 'expired' ? 'session_expired' : 'session_invalid'],
            );
        }

        $limit = $this->chatPerIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

            return ProblemResponseFactory::error(
                Response::HTTP_TOO_MANY_REQUESTS,
                'Rate limit exceeded',
                ['Retry-After' => (string) $retryAfter],
            );
        }

        try {
            $chatRequest = $this->serializer->deserialize($request->getContent(), ChatRequest::class, 'json');
        } catch (\Throwable) {
            return ProblemResponseFactory::error(Response::HTTP_BAD_REQUEST, 'Invalid JSON payload');
        }

        $violations = $this->validator->validate($chatRequest);
        if (count($violations) > 0) {
            $this->logger->log($chatRequest->question, '', 0.0, null, ChatOutcome::ValidationError);

            return ProblemResponseFactory::fromViolationList($violations);
        }

        return $this->sse->stream($this->pipeline->run($chatRequest));
    }
}
