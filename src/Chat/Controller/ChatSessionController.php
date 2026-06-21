<?php

declare(strict_types=1);

namespace App\Chat\Controller;

use App\Chat\Http\ProblemResponseFactory;
use App\Chat\Service\ChatSessionTokenManager;
use App\Chat\Service\HCaptchaVerifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class ChatSessionController
{
    public function __construct(
        private HCaptchaVerifier $hcaptcha,
        private ChatSessionTokenManager $sessionTokens,
        private RateLimiterFactory $chatSessionPerIpLimiter,
    ) {
    }

    /**
     * Issues a short-lived chat session token after verifying the hCaptcha.
     *
     * @param Request $request Incoming request carrying the X-HCaptcha-Token header
     *
     * @return Response Problem+json on failure, or a JSON session token on success
     */
    public function create(Request $request): Response
    {
        $limit = $this->chatSessionPerIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());

            return ProblemResponseFactory::error(
                Response::HTTP_TOO_MANY_REQUESTS,
                'Rate limit exceeded',
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $token = $request->headers->get('X-HCaptcha-Token');
        if ($token === null || $token === '') {
            return ProblemResponseFactory::violations([
                ['propertyPath' => 'token', 'message' => 'error.captcha.missing'],
            ]);
        }

        if (!$this->hcaptcha->verify($token)) {
            return ProblemResponseFactory::violations([
                ['propertyPath' => 'token', 'message' => 'error.captcha.invalid'],
            ]);
        }

        return new JsonResponse([
            'session' => $this->sessionTokens->issue(),
            'expiresIn' => $this->sessionTokens->ttl(),
        ]);
    }
}
