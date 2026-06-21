<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Entity\ChatOutcome;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Service\CachedChatAnswer;
use App\Chat\Service\ChatQueryCache;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\EmbeddingTaskType;
use App\Chat\Service\KnowledgeIngester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KnowledgeIngesterTest extends KernelTestCase
{
    private KnowledgeIngester $ingester;
    private ChatChunkRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get('doctrine.orm.entity_manager');
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $c->set(EmbeddingService::class, new class extends EmbeddingService {
            public function __construct() {}
            public function embed(string $text, EmbeddingTaskType $taskType): array { return [1.0, 0.0]; }
            public function embedBatch(array $texts, EmbeddingTaskType $taskType): array
            {
                return array_map(static fn () => [1.0, 0.0], $texts);
            }
        });

        $this->ingester = $c->get(KnowledgeIngester::class);
        $this->repo = $c->get(ChatChunkRepository::class);
    }

    public function testIngestionInvalidatesCachedAnswers(): void
    {
        $cache = self::getContainer()->get(ChatQueryCache::class);
        self::assertInstanceOf(ChatQueryCache::class, $cache);
        $cache->storeAnswer('Sa stack ?', new CachedChatAnswer('Symfony.', ChatOutcome::Answered, 0.8, null));
        self::assertNotNull($cache->getAnswer('Sa stack ?'));

        $this->ingester->ingest(['entries' => [['key' => 'k1', 'type' => 'skill', 'content' => 'A']]]);

        self::assertNull($cache->getAnswer('Sa stack ?'));
    }

    public function testInsertsNewEntries(): void
    {
        $payload = [
            'entries' => [
                ['key' => 'k1', 'type' => 'skill', 'content' => 'A'],
                ['key' => 'k2', 'type' => 'skill', 'content' => 'B'],
            ],
        ];

        $report = $this->ingester->ingest($payload);

        self::assertSame(2, $report->added);
        self::assertSame(0, $report->updated);
        self::assertSame(0, $report->skipped);
        self::assertSame(0, $report->deleted);
        self::assertCount(2, $this->repo->findAll());
    }

    public function testSkipsUnchangedAndUpdatesChangedAndDeletesMissing(): void
    {
        $this->ingester->ingest([
            'entries' => [
                ['key' => 'k1', 'type' => 's', 'content' => 'A'],
                ['key' => 'k2', 'type' => 's', 'content' => 'B'],
            ],
        ]);

        $report = $this->ingester->ingest([
            'entries' => [
                ['key' => 'k1', 'type' => 's', 'content' => 'A'],         // unchanged
                ['key' => 'k2', 'type' => 's', 'content' => 'B-updated'], // changed
            ],
        ]);

        self::assertSame(0, $report->added);
        self::assertSame(1, $report->updated);
        self::assertSame(1, $report->skipped);
        self::assertSame(0, $report->deleted);

        $report2 = $this->ingester->ingest([
            'entries' => [
                ['key' => 'k1', 'type' => 's', 'content' => 'A'],
            ],
        ]);
        self::assertSame(1, $report2->deleted);
    }
}
