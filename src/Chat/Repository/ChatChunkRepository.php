<?php

declare(strict_types=1);

namespace App\Chat\Repository;

use App\Chat\Entity\ChatChunk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatChunk>
 */
class ChatChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatChunk::class);
    }

    public function findOneBySourceKey(string $sourceKey): ?ChatChunk
    {
        return $this->findOneBy(['sourceKey' => $sourceKey]);
    }

    /** @return ChatChunk[] */
    public function findAll(): array
    {
        return parent::findAll();
    }

    /** @param string[] $sourceKeys */
    public function deleteNotIn(array $sourceKeys): int
    {
        if ($sourceKeys === []) {
            return (int) $this->createQueryBuilder('c')->delete()->getQuery()->execute();
        }

        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.sourceKey NOT IN (:keys)')
            ->setParameter('keys', $sourceKeys)
            ->getQuery()
            ->execute();
    }

    /**
     * @param float[] $queryEmbedding
     * @return ScoredChunk[]
     */
    public function findTopK(array $queryEmbedding, int $k): array
    {
        $chunks = $this->findAll();
        if ($chunks === []) {
            return [];
        }

        $queryNorm = self::norm($queryEmbedding);
        if ($queryNorm === 0.0) {
            return [];
        }

        $scored = [];
        foreach ($chunks as $chunk) {
            $embedding = $chunk->getEmbedding();
            $norm = self::norm($embedding);
            if ($norm === 0.0) {
                continue;
            }

            $score = self::dot($queryEmbedding, $embedding) / ($queryNorm * $norm);
            $scored[] = new ScoredChunk($chunk, $score);
        }

        usort($scored, static fn (ScoredChunk $a, ScoredChunk $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, $k);
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /** @param float[] $v */
    private static function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }

        return \sqrt($sum);
    }
}