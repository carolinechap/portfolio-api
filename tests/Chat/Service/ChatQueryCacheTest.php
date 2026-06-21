<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Entity\ChatOutcome;
use App\Chat\Service\CachedChatAnswer;
use App\Chat\Service\ChatQueryCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ChatQueryCacheTest extends TestCase
{
    private function cache(): ChatQueryCache
    {
        return new ChatQueryCache(new ArrayAdapter(), 'text-embedding-004');
    }

    public function testAnswerRoundTrip(): void
    {
        $cache = $this->cache();
        $cache->storeAnswer('Sa stack ?', new CachedChatAnswer('Symfony et Drupal.', ChatOutcome::Answered, 0.8, ['skill.symfony']));

        $cached = $cache->getAnswer('Sa stack ?');
        self::assertNotNull($cached);
        self::assertSame('Symfony et Drupal.', $cached->answer);
        self::assertSame(ChatOutcome::Answered, $cached->outcome);
        self::assertSame(['skill.symfony'], $cached->chunksUsed);
    }

    public function testAnswerMissReturnsNull(): void
    {
        self::assertNull($this->cache()->getAnswer('question jamais vue'));
    }

    public function testNormalizationIgnoresCaseAndWhitespace(): void
    {
        $cache = $this->cache();
        $cache->storeAnswer('Quelle  Est   Sa  Stack ?', new CachedChatAnswer('x', ChatOutcome::Answered, 0.7, null));

        self::assertNotNull($cache->getAnswer('quelle est sa stack ?'));
    }

    public function testEmbeddingRoundTrip(): void
    {
        $cache = $this->cache();
        $cache->storeEmbedding('Sa stack ?', [0.1, 0.2, 0.3]);

        self::assertSame([0.1, 0.2, 0.3], $cache->getEmbedding('Sa stack ?'));
    }

    public function testInvalidateAnswersDropsAnswersButKeepsEmbeddings(): void
    {
        $cache = $this->cache();
        $cache->storeAnswer('Sa stack ?', new CachedChatAnswer('x', ChatOutcome::Answered, 0.7, null));
        $cache->storeEmbedding('Sa stack ?', [0.1, 0.2]);

        $cache->invalidateAnswers();

        self::assertNull($cache->getAnswer('Sa stack ?'));
        self::assertSame([0.1, 0.2], $cache->getEmbedding('Sa stack ?'));
    }
}
