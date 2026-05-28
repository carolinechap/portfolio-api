<?php

declare(strict_types=1);

namespace App\Chat\Repository;

use App\Chat\Entity\ChatChunk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @extends ServiceEntityRepository<ChatChunk>
 */
class ChatChunkRepository extends ServiceEntityRepository
{
    private const string CHUNKS_CACHE_KEY = 'chat.chunks';
    private const int CHUNKS_CACHE_TTL = 300;

    public function __construct(
        ManagerRegistry $registry,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $chunksCache,
    ) {
        parent::__construct($registry, ChatChunk::class);
    }

    /**
     * Invalidates the cached list of chunks used by {@see self::findTopK}.
     *
     * Must be called by writers (typically the ingester) after mutating the
     * `chat_chunk` table so subsequent retrieval queries see fresh data.
     */
    public function invalidateCache(): void
    {
        $this->chunksCache->delete(self::CHUNKS_CACHE_KEY);
    }

    /**
     * Returns the chunk whose unique source key matches, or null if none exists.
     */
    public function findOneBySourceKey(string $sourceKey): ?ChatChunk
    {
        return $this->findOneBy(['sourceKey' => $sourceKey]);
    }

    /** @return ChatChunk[] All persisted chunks, in no particular order */
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * Deletes every chunk whose source key is NOT in the given list.
     *
     * When the list is empty all chunks are deleted (table-wide reset).
     *
     * @param string[] $sourceKeys Source keys to keep
     *
     * @return int Number of rows deleted
     */
    public function deleteNotIn(array $sourceKeys): int
    {
        $qb = $this->createQueryBuilder('c')->delete();

        if ($sourceKeys !== []) {
            $qb
                ->where('c.sourceKey NOT IN (:keys)')
                ->setParameter('keys', $sourceKeys);
        }

        $affected = $qb->getQuery()->execute();

        return is_int($affected) ? $affected : 0;
    }

    /**
     * Returns the top-K most similar chunks to the given query embedding.
     *
     * Loads all chunks from the database, computes cosine similarity in PHP,
     * sorts by descending score, and returns the K best. Chunks with a
     * zero-norm embedding are skipped, and an empty result is returned when
     * the query itself has a zero norm.
     *
     * @param float[] $queryEmbedding Vector to compare against (typically 768 dimensions)
     *
     * @return ScoredChunk[] Sorted by score descending, length ≤ $k
     */
    public function findTopK(array $queryEmbedding, int $k): array
    {
        /** @var ChatChunk[] $chunks */
        $chunks = $this->chunksCache->get(self::CHUNKS_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CHUNKS_CACHE_TTL);

            return $this->findAll();
        });
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
     * Computes the dot product of two vectors, truncating to the shorter length.
     *
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

    /**
     * Computes the Euclidean (L2) norm of a vector.
     *
     * @param float[] $v
     */
    private static function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }

        return \sqrt($sum);
    }
}