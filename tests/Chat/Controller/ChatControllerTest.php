<?php

declare(strict_types=1);

namespace App\Tests\Chat\Controller;

use App\Chat\Entity\ChatChunk;
use App\Chat\Entity\ChatOutcome;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Repository\ChatLogRepository;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\GeminiClient;
use App\Chat\Service\HCaptchaVerifier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChatControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Embedding aligned with the stubbed EmbeddingService output below so
        // cosine similarity is 1.0 (well above CHAT_RETRIEVAL_THRESHOLD).
        $em->persist(new ChatChunk(
            'skill.symfony',
            'Caroline maîtrise Symfony 5/6/7 et API Platform.',
            hash('sha256', 'x'),
            array_fill(0, 768, 1.0),
        ));
        $em->flush();

        self::getContainer()->get(ChatChunkRepository::class)->invalidateCache();

        // Replace external services with stubs that don't hit the network.
        self::getContainer()->set(HCaptchaVerifier::class, new class extends HCaptchaVerifier {
            public function __construct() {}
            public function verify(?string $token): bool { return $token === 'good'; }
        });

        self::getContainer()->set(EmbeddingService::class, new class extends EmbeddingService {
            public function __construct() {}
            public function embed(string $text): array { return array_fill(0, 768, 1.0); }
            public function embedBatch(array $texts): array { return array_map(static fn () => array_fill(0, 768, 1.0), $texts); }
        });

        self::getContainer()->set(GeminiClient::class, new class extends GeminiClient {
            public function __construct() {}
            public function streamGenerate(string $prompt): \Generator
            {
                yield 'Oui';
                yield ', ';
                yield 'avec Symfony.';
            }
        });
    }

    public function testRejectsMissingOrigin(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Test', 'history' => []], JSON_THROW_ON_ERROR));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectsInvalidHCaptcha(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_HCAPTCHA_TOKEN' => 'bad',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Test', 'history' => []], JSON_THROW_ON_ERROR));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectsValidationErrors(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_HCAPTCHA_TOKEN' => 'good',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => '', 'history' => []], JSON_THROW_ON_ERROR));

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testHappyPathStreamsAnswerAndLogs(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_HCAPTCHA_TOKEN' => 'good',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Tu fais du Symfony ?', 'history' => []], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        // StreamedResponse::getContent() always returns false. The BrowserKit
        // internal response captures the streamed body via ob_start.
        $body = $this->client->getInternalResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('event: chunk', $body);
        self::assertStringContainsString('"Oui"', $body);
        self::assertStringContainsString('event: done', $body);

        /** @var ChatLogRepository $repo */
        $repo = self::getContainer()->get(ChatLogRepository::class);
        $logs = $repo->findAll();
        self::assertCount(1, $logs);
        self::assertSame(ChatOutcome::Answered, $logs[0]->getOutcome());
    }
}
