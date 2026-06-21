<?php

declare(strict_types=1);

namespace App\Tests\Chat\Controller;

use App\Chat\Service\ChatSessionTokenManager;
use App\Chat\Service\HCaptchaVerifier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChatSessionControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        self::getContainer()->set(HCaptchaVerifier::class, new class extends HCaptchaVerifier {
            public function __construct() {}
            public function verify(?string $token): bool { return $token === 'good'; }
        });
    }

    public function testMintsSessionOnValidCaptcha(): void
    {
        $this->client->request('POST', '/api/chat/session', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_HCAPTCHA_TOKEN' => 'good',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        /** @var array{session: string, expiresIn: int} $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('session', $data);
        self::assertArrayHasKey('expiresIn', $data);

        $manager = self::getContainer()->get(ChatSessionTokenManager::class);
        self::assertInstanceOf(ChatSessionTokenManager::class, $manager);
        self::assertSame('valid', $manager->check($data['session']));
    }

    public function testRejectsInvalidCaptchaWith422(): void
    {
        $this->client->request('POST', '/api/chat/session', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_HCAPTCHA_TOKEN' => 'bad',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));

        /** @var array{violations: list<array{propertyPath: string, message: string}>} $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('token', $data['violations'][0]['propertyPath']);
        self::assertSame('error.captcha.invalid', $data['violations'][0]['message']);
    }

    public function testRejectsMissingCaptchaWith422(): void
    {
        $this->client->request('POST', '/api/chat/session', server: [
            'HTTP_ORIGIN' => 'https://test.local',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectsMissingOriginWith403(): void
    {
        $this->client->request('POST', '/api/chat/session', server: [
            'HTTP_X_HCAPTCHA_TOKEN' => 'good',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
