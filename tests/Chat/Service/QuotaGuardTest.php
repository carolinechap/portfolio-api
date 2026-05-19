<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Service\QuotaGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class QuotaGuardTest extends TestCase
{
    public function testAllowsCallsUnderLimit(): void
    {
        $guard = new QuotaGuard(new ArrayAdapter(), limit: 3);

        self::assertTrue($guard->canCall());
        $guard->recordCall();
        self::assertTrue($guard->canCall());
        $guard->recordCall();
        self::assertTrue($guard->canCall());
    }

    public function testBlocksWhenLimitReached(): void
    {
        $guard = new QuotaGuard(new ArrayAdapter(), limit: 2);

        $guard->recordCall();
        $guard->recordCall();

        self::assertFalse($guard->canCall());
    }

    public function testExhaustImmediatelyBlocks(): void
    {
        $guard = new QuotaGuard(new ArrayAdapter(), limit: 100);

        $guard->exhaust();

        self::assertFalse($guard->canCall());
    }
}
