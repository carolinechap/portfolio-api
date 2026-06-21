<?php

declare(strict_types=1);

namespace App\Tests\Chat\Command;

use App\Chat\Entity\ChatChunk;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\EmbeddingTaskType;
use App\Chat\Service\GeminiClient;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AskChatCommandTest extends KernelTestCase
{
    /** @param array<int, float> $queryEmbedding */
    private function tester(array $queryEmbedding): CommandTester
    {
        self::bootKernel();
        $container = self::getContainer();

        $entityManager = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($entityManager);
        $schemaTool->dropSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new ChatChunk(
            'skill.symfony',
            'Caroline maîtrise Symfony et API Platform.',
            hash('sha256', 'x'),
            array_fill(0, 768, 1.0),
        ));
        $entityManager->flush();
        $container->get(ChatChunkRepository::class)->invalidateCache();

        $container->set(EmbeddingService::class, new class($queryEmbedding) extends EmbeddingService {
            /** @param array<int, float> $queryEmbedding */
            public function __construct(private array $queryEmbedding)
            {
            }

            public function embed(string $text, EmbeddingTaskType $taskType): array
            {
                return $this->queryEmbedding;
            }

            public function embedBatch(array $texts, EmbeddingTaskType $taskType): array
            {
                return array_map(fn (): array => $this->queryEmbedding, $texts);
            }
        });

        $container->set(GeminiClient::class, new class extends GeminiClient {
            public function __construct()
            {
            }

            public function streamGenerate(string $prompt): \Generator
            {
                yield 'Oui, ';
                yield 'avec Symfony.';
            }
        });

        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:chat:ask'));
    }

    public function testAnsweredPrintsAnswerAndChunk(): void
    {
        $tester = $this->tester(array_fill(0, 768, 1.0));
        $tester->execute(['question' => 'Tu fais du Symfony ?']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('skill.symfony', $display);
        self::assertStringContainsString('avec Symfony.', $display);
        self::assertStringContainsString('answered', $display);
    }

    public function testOffScopeSkipsGeneration(): void
    {
        $orthogonal = array_map(static fn (int $index): float => $index % 2 === 0 ? 1.0 : -1.0, range(0, 767));
        $tester = $this->tester($orthogonal);
        $tester->execute(['question' => 'Quelle météo demain ?']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('off_scope', $display);
        self::assertStringNotContainsString('avec Symfony.', $display);
    }
}
