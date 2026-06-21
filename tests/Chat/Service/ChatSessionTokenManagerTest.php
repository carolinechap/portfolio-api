<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Service\ChatSessionTokenManager;
use PHPUnit\Framework\TestCase;

final class ChatSessionTokenManagerTest extends TestCase
{
    public function testIssuedTokenIsValid(): void
    {
        $manager = new ChatSessionTokenManager('secret', 1800);

        self::assertSame('valid', $manager->check($manager->issue()));
    }

    public function testTtlIsExposed(): void
    {
        self::assertSame(1800, (new ChatSessionTokenManager('secret', 1800))->ttl());
    }

    public function testExpiredTokenIsExpired(): void
    {
        // Negative TTL mints a token whose `exp` is already in the past.
        $manager = new ChatSessionTokenManager('secret', -1);

        self::assertSame('expired', $manager->check($manager->issue()));
    }

    public function testTamperedTokenIsInvalid(): void
    {
        $manager = new ChatSessionTokenManager('secret', 1800);

        self::assertSame('invalid', $manager->check('x' . $manager->issue()));
    }

    public function testTokenSignedWithAnotherSecretIsInvalid(): void
    {
        $issued = (new ChatSessionTokenManager('secret-a', 1800))->issue();

        self::assertSame('invalid', (new ChatSessionTokenManager('secret-b', 1800))->check($issued));
    }

    public function testGarbageAndNullAreInvalid(): void
    {
        $manager = new ChatSessionTokenManager('secret', 1800);

        self::assertSame('invalid', $manager->check(null));
        self::assertSame('invalid', $manager->check(''));
        self::assertSame('invalid', $manager->check('garbage'));
        self::assertSame('invalid', $manager->check('a.b.c'));
    }
}
