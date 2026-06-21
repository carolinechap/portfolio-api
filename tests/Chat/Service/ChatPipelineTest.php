<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Dto\ChatRequest;
use App\Chat\Entity\ChatChunk;
use App\Chat\Entity\ChatOutcome;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Service\ChatPipeline;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\EmbeddingTaskType;
use App\Chat\Service\GeminiClient;
use App\Chat\Stream\ChunkEvent;
use App\Chat\Stream\DoneEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class ChatPipelineTest extends KernelTestCase
{
    /** @param array<int, float> $queryEmbedding */
    private function pipeline(array $queryEmbedding, ?ClockInterface $clock = null): ChatPipeline
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->get('cache.app')->clear();

        if ($clock !== null) {
            $container->set(ClockInterface::class, $clock);
        }

        $entityManager = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($entityManager);
        $schemaTool->dropSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $entityManager->persist(new ChatChunk('skill.symfony', 'Caroline maîtrise Symfony.', hash('sha256', 'x'), array_fill(0, 768, 1.0)));
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

        $pipeline = $container->get(ChatPipeline::class);
        self::assertInstanceOf(ChatPipeline::class, $pipeline);

        return $pipeline;
    }

    public function testAnsweredYieldsChunksThenDone(): void
    {
        $pipeline = $this->pipeline(array_fill(0, 768, 1.0));

        $events = iterator_to_array($pipeline->run(new ChatRequest('Tu fais du Symfony ?', [])), false);

        $answer = '';
        foreach ($events as $event) {
            if ($event instanceof ChunkEvent) {
                $answer .= $event->token;
            }
        }
        self::assertStringContainsString('avec Symfony.', $answer);

        $last = end($events);
        self::assertInstanceOf(DoneEvent::class, $last);
        self::assertSame(ChatOutcome::Answered, $last->outcome);
    }

    public function testOffScopeYieldsCannedMessageAndDone(): void
    {
        $orthogonal = array_map(static fn (int $index): float => $index % 2 === 0 ? 1.0 : -1.0, range(0, 767));
        $pipeline = $this->pipeline($orthogonal);

        $events = iterator_to_array($pipeline->run(new ChatRequest('Quelle est la météo ?', [])), false);

        $last = end($events);
        self::assertInstanceOf(DoneEvent::class, $last);
        self::assertSame(ChatOutcome::OffScope, $last->outcome);
    }

    public function testGreetingYieldsFriendlyMessageWithoutGeneration(): void
    {
        // Orthogonal embedding so retrieval would fall off-scope: proves the
        // greeting is handled before retrieval, not by reaching generation.
        $orthogonal = array_map(static fn (int $index): float => $index % 2 === 0 ? 1.0 : -1.0, range(0, 767));
        $pipeline = $this->pipeline($orthogonal);

        $events = iterator_to_array($pipeline->run(new ChatRequest('Bonjour', [])), false);

        $answer = '';
        foreach ($events as $event) {
            if ($event instanceof ChunkEvent) {
                $answer .= $event->token;
            }
        }
        self::assertStringContainsString('Caroline', $answer);
        self::assertStringContainsString('bien', $answer);
        // The fake GeminiClient would yield "avec Symfony." if generation ran.
        self::assertStringNotContainsString('avec Symfony.', $answer);

        $last = end($events);
        self::assertInstanceOf(DoneEvent::class, $last);
        self::assertSame(ChatOutcome::Answered, $last->outcome);
    }

    public function testGreetingFollowedByRealQuestionStillGenerates(): void
    {
        $pipeline = $this->pipeline(array_fill(0, 768, 1.0));

        $events = iterator_to_array($pipeline->run(new ChatRequest('Bonjour, tu fais du Symfony ?', [])), false);

        $answer = '';
        foreach ($events as $event) {
            if ($event instanceof ChunkEvent) {
                $answer .= $event->token;
            }
        }
        self::assertStringContainsString('avec Symfony.', $answer);
    }

    public function testGreetingBeforeOnePmSaysBonMatin(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-06-21 09:00', new \DateTimeZone('Europe/Paris')));
        $pipeline = $this->pipeline(array_fill(0, 768, 1.0), $clock);

        $events = iterator_to_array($pipeline->run(new ChatRequest('Bonjour', [])), false);

        $answer = '';
        foreach ($events as $event) {
            if ($event instanceof ChunkEvent) {
                $answer .= $event->token;
            }
        }
        self::assertStringContainsString('Bon matin', $answer);
    }

    public function testGreetingFromOnePmSaysBonjour(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-06-21 15:00', new \DateTimeZone('Europe/Paris')));
        $pipeline = $this->pipeline(array_fill(0, 768, 1.0), $clock);

        $events = iterator_to_array($pipeline->run(new ChatRequest('Bonjour', [])), false);

        $answer = '';
        foreach ($events as $event) {
            if ($event instanceof ChunkEvent) {
                $answer .= $event->token;
            }
        }
        self::assertStringContainsString('Bonjour', $answer);
        self::assertStringNotContainsString('Bon matin', $answer);
    }
}
