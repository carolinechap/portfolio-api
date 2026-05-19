<?php

declare(strict_types=1);

namespace App\Tests\Chat\Repository;

use App\Chat\Entity\ChatChunk;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Repository\ScoredChunk;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ChatChunkRepositoryTest extends KernelTestCase
{
    private ChatChunkRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');

        // create schema
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metas = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metas);
        $tool->createSchema($metas);

        $this->repo = $container->get(ChatChunkRepository::class);

        $em->persist(new ChatChunk('a', 'A', hash('sha256', 'A'), [1.0, 0.0, 0.0]));
        $em->persist(new ChatChunk('b', 'B', hash('sha256', 'B'), [0.0, 1.0, 0.0]));
        $em->persist(new ChatChunk('c', 'C', hash('sha256', 'C'), [0.9, 0.1, 0.0]));
        $em->flush();
        $em->clear();
    }

    public function testFindTopKReturnsHighestCosineFirst(): void
    {
        $query = [1.0, 0.0, 0.0];

        $results = $this->repo->findTopK($query, 2);

        self::assertCount(2, $results);
        self::assertSame('a', $results[0]->chunk->getSourceKey());
        self::assertSame('c', $results[1]->chunk->getSourceKey());
        self::assertEqualsWithDelta(1.0, $results[0]->score, 1e-9);
        self::assertGreaterThan($results[1]->score, $results[0]->score);
    }

    public function testFindTopKReturnsEmptyWhenNoChunks(): void
    {
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        foreach ($em->getRepository(ChatChunk::class)->findAll() as $c) {
            $em->remove($c);
        }
        $em->flush();

        self::assertSame([], $this->repo->findTopK([1.0, 0.0, 0.0], 5));
    }
}