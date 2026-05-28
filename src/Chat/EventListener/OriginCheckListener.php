<?php

declare(strict_types=1);

namespace App\Chat\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Rejects requests to the `chat` route whose `Origin` header does not match
 * the configured front-end URL, providing a first line of defense against
 * cross-origin abuse of the streamed chat endpoint.
 */
#[AsEventListener(priority: 16)]
final readonly class OriginCheckListener
{
    public function __construct(
        #[Autowire(value: '%env(string:FRONT_URL)%')]
        private string $frontUrl,
    ) {
    }

    /**
     * Validates the Origin header for chat requests and forwards everything else untouched.
     *
     * @throws AccessDeniedHttpException When the Origin header is missing or does not match FRONT_URL
     */
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'chat') {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || rtrim($origin, '/') !== rtrim($this->frontUrl, '/')) {
            throw new AccessDeniedHttpException('Origin not allowed');
        }
    }
}
