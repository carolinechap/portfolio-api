<?php

declare(strict_types=1);

namespace App\Tests\Chat\EventListener;

use App\Chat\EventListener\OriginCheckListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class OriginCheckListenerTest extends TestCase
{
    public function testIgnoresNonChatRoutes(): void
    {
        $listener = new OriginCheckListener('https://example.com');
        $request = Request::create('/api/contacts', 'POST');
        $request->attributes->set('_route', 'contact');
        $event = new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);
        $this->expectNotToPerformAssertions();
    }

    public function testDeniesMismatchedOriginWithProblemJson(): void
    {
        $listener = new OriginCheckListener('https://example.com');
        $request = Request::create('/api/chat', 'POST', server: ['HTTP_ORIGIN' => 'https://evil.test']);
        $request->attributes->set('_route', 'chat');
        $event = new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'application/problem+json',
            (string) $response->headers->get('Content-Type'),
        );
    }

    public function testAllowsMatchingOrigin(): void
    {
        $listener = new OriginCheckListener('https://example.com');
        $request = Request::create('/api/chat', 'POST', server: ['HTTP_ORIGIN' => 'https://example.com']);
        $request->attributes->set('_route', 'chat');
        $event = new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);
        $this->expectNotToPerformAssertions();
    }

    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
