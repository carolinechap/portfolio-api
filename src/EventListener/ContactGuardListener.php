<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Front-line guard for the public contact endpoint (`POST /api/contacts`),
 * mirroring the protections the chat endpoint already enforces inline.
 */
#[AsEventListener(priority: 16)]
final readonly class ContactGuardListener
{
    public function __construct(
        #[Autowire(value: '%env(string:FRONT_URL)%')]
        private string $frontUrl,
        private RateLimiterFactory $contactPerIpLimiter,
    ) {
    }

    /**
     * @throws AccessDeniedHttpException     When the Origin header is missing or does not match FRONT_URL
     * @throws TooManyRequestsHttpException  When the per-IP rate limit is exceeded
     */
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST') || !str_starts_with($request->getPathInfo(), '/api/contacts')) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || rtrim($origin, '/') !== rtrim($this->frontUrl, '/')) {
            throw new AccessDeniedHttpException('Origin not allowed');
        }

        $limiter = $this->contactPerIpLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(0, $limit->getRetryAfter()->getTimestamp() - time()),
                'Rate limit exceeded',
            );
        }
    }
}
