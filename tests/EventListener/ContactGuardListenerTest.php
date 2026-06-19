<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ContactGuardListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ContactGuardListenerTest extends TestCase
{
    private const string FRONT = 'https://example.com';

    public function testIgnoresNonContactPaths(): void
    {
        $listener = new ContactGuardListener(self::FRONT, self::limiter());
        $request = Request::create('/api/chat', 'POST', server: ['HTTP_ORIGIN' => 'https://evil.test']);

        $listener($this->event($request));
        $this->expectNotToPerformAssertions();
    }

    public function testIgnoresNonPostMethods(): void
    {
        $listener = new ContactGuardListener(self::FRONT, self::limiter());
        $request = Request::create('/api/contacts/1', 'GET', server: ['HTTP_ORIGIN' => 'https://evil.test']);

        $listener($this->event($request));
        $this->expectNotToPerformAssertions();
    }

    public function testDeniesMissingOrigin(): void
    {
        $listener = new ContactGuardListener(self::FRONT, self::limiter());
        $request = Request::create('/api/contacts', 'POST');

        $this->expectException(AccessDeniedHttpException::class);
        $listener($this->event($request));
    }

    public function testDeniesMismatchedOrigin(): void
    {
        $listener = new ContactGuardListener(self::FRONT, self::limiter());
        $request = Request::create('/api/contacts', 'POST', server: ['HTTP_ORIGIN' => 'https://evil.test']);

        $this->expectException(AccessDeniedHttpException::class);
        $listener($this->event($request));
    }

    public function testAllowsMatchingOriginWithinLimit(): void
    {
        $listener = new ContactGuardListener(self::FRONT, self::limiter());
        $request = Request::create('/api/contacts', 'POST', server: ['HTTP_ORIGIN' => self::FRONT]);

        $listener($this->event($request));
        $this->expectNotToPerformAssertions();
    }

    public function testThrottlesAfterLimit(): void
    {
        $limiter = self::limiter();
        $listener = new ContactGuardListener(self::FRONT, $limiter);

        $send = function () use ($listener): void {
            $request = Request::create('/api/contacts', 'POST', server: [
                'HTTP_ORIGIN' => self::FRONT,
                'REMOTE_ADDR' => '203.0.113.7',
            ]);
            $listener($this->event($request));
        };

        for ($i = 0; $i < 3; $i++) {
            $send();
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $send();
    }

    private static function limiter(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'contact_per_ip', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
