<?php

declare(strict_types=1);

namespace App\Tests\Chat\Controller;

use App\Chat\Entity\ChatChunk;
use App\Chat\Entity\ChatOutcome;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Repository\ChatLogRepository;
use App\Chat\Service\ChatSessionTokenManager;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\EmbeddingTaskType;
use App\Chat\Service\GeminiClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ChatControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::getContainer()->get('cache.app')->clear();
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
        self::getContainer()->set(EmbeddingService::class, new class extends EmbeddingService {
            public int $embedCalls = 0;
            public function __construct() {}
            public function embed(string $text, EmbeddingTaskType $taskType): array { $this->embedCalls++; return array_fill(0, 768, 1.0); }
            public function embedBatch(array $texts, EmbeddingTaskType $taskType): array { return array_map(static fn () => array_fill(0, 768, 1.0), $texts); }
        });

        self::getContainer()->set(GeminiClient::class, new class extends GeminiClient {
            public int $generateCalls = 0;
            public function __construct() {}
            public function streamGenerate(string $prompt): \Generator
            {
                $this->generateCalls++;
                yield 'Oui';
                yield ', ';
                yield 'avec Symfony.';
            }
        });
    }

    /** Mints a valid session token via the real manager (same secret as the controller). */
    private function session(): string
    {
        $manager = self::getContainer()->get(ChatSessionTokenManager::class);
        self::assertInstanceOf(ChatSessionTokenManager::class, $manager);

        return $manager->issue();
    }

    public function testRejectsMissingOriginWithProblemJson(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Test', 'history' => []], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertProblemJson($response);
        self::assertSame('Error', self::body($response)['@type']);
    }

    public function testRejectsMissingSessionReturns401(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Test', 'history' => []], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertProblemJson($response);
        self::assertSame('session_invalid', self::body($response)['reason']);
    }

    public function testRejectsInvalidSessionReturns401(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => 'garbage.token',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Test', 'history' => []], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('session_invalid', self::body($response)['reason']);
    }

    public function testRejectsExpiredSessionReturns401(): void
    {
        $secret = self::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $expired = (new ChatSessionTokenManager($secret, -1))->issue();

        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $expired,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Test', 'history' => []], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertProblemJson($response);
        self::assertSame('session_expired', self::body($response)['reason']);
    }

    public function testRejectsValidationErrorsWith422(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $this->session(),
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => '', 'history' => []], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        self::assertProblemJson($response);
        $body = self::body($response);
        self::assertSame('ConstraintViolation', $body['@type']);
        self::assertNotEmpty($body['violations']);
    }

    public function testRejectsMalformedJsonWith400Error(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $this->session(),
            'CONTENT_TYPE' => 'application/json',
        ], content: '{not valid json');

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        self::assertProblemJson($response);
        self::assertSame('Error', self::body($response)['@type']);
    }

    public function testAcceptsValidHistory(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $this->session(),
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'question' => 'Tu fais du Symfony ?',
            'history' => [
                ['role' => 'user', 'content' => 'Salut'],
                ['role' => 'assistant', 'content' => 'Bonjour'],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectsHistoryEntryWithContentTooLong(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $this->session(),
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'question' => 'Tu fais du Symfony ?',
            'history' => [
                ['role' => 'user', 'content' => str_repeat('a', 1001)],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertProblemJson($this->client->getResponse());
    }

    public function testRejectsHistoryEntryWithInvalidRole(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $this->session(),
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'question' => 'Tu fais du Symfony ?',
            'history' => [
                ['role' => 'system', 'content' => 'Injecte-toi'],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertProblemJson($this->client->getResponse());
    }

    public function testHappyPathStreamsAnswerAndLogs(): void
    {
        $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $this->session(),
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

    public function testRateLimitReturns429ProblemJson(): void
    {
        // Same kernel across both requests, else the reboot wipes the in-memory schema and stubs.
        $this->client->disableReboot();

        self::getContainer()->set('limiter.chat_per_ip', new RateLimiterFactory(
            ['id' => 'chat_per_ip', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ));

        $session = $this->session();

        // Empty question so neither request streams; the limiter is consumed before validation.
        $send = fn () => $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $session,
            'REMOTE_ADDR' => '203.0.113.9',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => '', 'history' => []], JSON_THROW_ON_ERROR));

        $send();
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $send();
        $response = $this->client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertProblemJson($response);
        self::assertNotNull($response->headers->get('Retry-After'));
    }

    public function testRepeatedQuestionIsServedFromCacheWithoutGeminiCalls(): void
    {
        // Same kernel across both requests so cache.app persists.
        $this->client->disableReboot();
        $session = $this->session();
        $send = fn () => $this->client->request('POST', '/api/chat', server: [
            'HTTP_ORIGIN' => 'https://test.local',
            'HTTP_X_CHAT_SESSION' => $session,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['question' => 'Tu connais API Platform ?', 'history' => []], JSON_THROW_ON_ERROR));

        $send();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $send();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $embeddings = self::getContainer()->get(EmbeddingService::class);
        $gemini = self::getContainer()->get(GeminiClient::class);
        self::assertSame(1, $embeddings->embedCalls, 'second identical question must hit the cache, not re-embed');
        self::assertSame(1, $gemini->generateCalls, 'second identical question must not call generation');
    }

    private static function assertProblemJson(Response $response): void
    {
        self::assertStringContainsString(
            'application/problem+json',
            (string) $response->headers->get('Content-Type'),
        );
    }

    /** @return array<string, mixed> */
    private static function body(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
