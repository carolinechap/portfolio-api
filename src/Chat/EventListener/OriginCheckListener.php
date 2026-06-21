<?php

declare(strict_types=1);

namespace App\Chat\EventListener;

use App\Chat\Http\ProblemResponseFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Returns a 403 problem+json for `chat` / `chat_session` requests whose `Origin`
 * does not match `FRONT_URL`.
 */
#[AsEventListener(priority: 16)]
final readonly class OriginCheckListener
{
    public function __construct(
        #[Autowire(value: '%env(string:FRONT_URL)%')]
        private string $frontUrl,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if ($route !== 'chat' && $route !== 'chat_session') {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || rtrim($origin, '/') !== rtrim($this->frontUrl, '/')) {
            $event->setResponse(ProblemResponseFactory::error(Response::HTTP_FORBIDDEN, 'Origin not allowed'));
        }
    }
}
