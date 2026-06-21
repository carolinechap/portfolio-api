<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Chat\Service\HCaptchaVerifier;
use App\Entity\Contact;
use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContactResourceTest extends WebTestCase
{
    private const string ORIGIN = 'https://test.local';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        self::getContainer()->set(HCaptchaVerifier::class, new class extends HCaptchaVerifier {
            public function __construct() {}
            public function verify(?string $token): bool { return $token === 'good'; }
        });

        self::getContainer()->set(EmailService::class, new class extends EmailService {
            public function __construct() {}
            public function sendMail(Contact $contact): void {}
        });
    }

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): string
    {
        return json_encode(array_merge([
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
            'email' => 'jean@example.com',
            'message' => 'Bonjour, je vous contacte au sujet d\'une opportunité.',
            'token' => 'good',
        ], $override), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $extraServer */
    private function post(string $body, array $extraServer = []): void
    {
        // Unique source IP per request so the per-IP limiter never accumulates across tests or runs.
        $this->client->request('POST', '/api/contacts', server: array_merge([
            'HTTP_ORIGIN' => self::ORIGIN,
            'CONTENT_TYPE' => 'application/ld+json',
            'REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254)),
        ], $extraServer), content: $body);
    }

    public function testValidSubmissionReturns201(): void
    {
        $this->post($this->payload());

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingOriginReturns403(): void
    {
        $this->client->request('POST', '/api/contacts', server: [
            'CONTENT_TYPE' => 'application/ld+json',
        ], content: $this->payload());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingRequiredFieldReturns422(): void
    {
        $this->post($this->payload(['firstname' => '']));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'application/problem+json',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testInvalidCaptchaReturns422(): void
    {
        $this->post($this->payload(['token' => 'bad']));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingCaptchaReturns422(): void
    {
        $this->post($this->payload(['token' => '']));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testHoneypotFilledReturns422(): void
    {
        $this->post($this->payload(['website' => 'http://spam.test']));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testPhoneWithoutInternationalPrefixReturns422(): void
    {
        $this->post($this->payload(['phone' => '0612345678']));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testValidPhoneWithPrefixReturns201(): void
    {
        $this->post($this->payload(['phone' => '+33612345678']));

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testApplicationJsonContentTypeIsRejected(): void
    {
        $this->post($this->payload(), ['CONTENT_TYPE' => 'application/json']);

        self::assertSame(415, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingCaptchaKeyReturns422(): void
    {
        $noToken = json_encode([
            'firstname' => 'Jean',
            'lastname' => 'Dupont',
            'email' => 'jean@example.com',
            'message' => 'Bonjour, ceci est un message de test.',
        ], JSON_THROW_ON_ERROR);

        $this->post($noToken);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }
}
