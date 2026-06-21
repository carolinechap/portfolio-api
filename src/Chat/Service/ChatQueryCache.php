<?php

declare(strict_types=1);

namespace App\Chat\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChatQueryCache
{
    private const int ANSWER_TTL = 604800;
    private const int EMBEDDING_TTL = 2592000;
    private const string KNOWLEDGE_VERSION_KEY = 'chat.knowledge_version';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire(value: '%env(string:GEMINI_EMBEDDING_MODEL)%')]
        private readonly string $embeddingModel,
    ) {
    }

    /**
     * Returns the cached answer for a stateless question, or null on a miss.
     *
     * @param string $question Raw user question
     *
     * @return CachedChatAnswer|null
     */
    public function getAnswer(string $question): ?CachedChatAnswer
    {
        $value = $this->cache->getItem($this->getAnswerKey($question))->get();

        return $value instanceof CachedChatAnswer ? $value : null;
    }

    /**
     * Caches the answer for a stateless question.
     *
     * @param string           $question Raw user question
     * @param CachedChatAnswer $answer   Answer to cache
     *
     * @return void
     */
    public function storeAnswer(string $question, CachedChatAnswer $answer): void
    {
        $item = $this->cache->getItem($this->getAnswerKey($question));
        $item->set($answer)->expiresAfter(self::ANSWER_TTL);
        $this->cache->save($item);
    }

    /**
     * Returns the cached query embedding, or null on a miss.
     *
     * @param string $question Raw user question
     *
     * @return float[]|null
     */
    public function getEmbedding(string $question): ?array
    {
        $value = $this->cache->getItem($this->getEmbeddingKey($question))->get();
        if (!is_array($value)) {
            return null;
        }

        /** @var float[] $value */
        return $value;
    }

    /**
     * Caches the embedding of a query.
     *
     * @param string  $question Raw user question
     * @param float[] $vector   Embedding vector to cache
     *
     * @return void
     */
    public function storeEmbedding(string $question, array $vector): void
    {
        $item = $this->cache->getItem($this->getEmbeddingKey($question));
        $item->set($vector)->expiresAfter(self::EMBEDDING_TTL);
        $this->cache->save($item);
    }

    /**
     * Drops every cached answer by bumping the knowledge-version stamp; cached embeddings are kept.
     *
     * @return void
     */
    public function invalidateAnswers(): void
    {
        $item = $this->cache->getItem(self::KNOWLEDGE_VERSION_KEY);
        $item->set(bin2hex(random_bytes(8)));
        $this->cache->save($item);
    }

    /**
     * Builds the cache key for a question's answer, namespaced by the knowledge version.
     *
     * @param string $question Raw user question
     *
     * @return string
     */
    private function getAnswerKey(string $question): string
    {
        return \sprintf('chat.answer.%s.%s', $this->getKnowledgeVersion(), $this->getFingerprint($question));
    }

    /**
     * Builds the cache key for a question's embedding, namespaced by the embedding model.
     *
     * @param string $question Raw user question
     *
     * @return string
     */
    private function getEmbeddingKey(string $question): string
    {
        return \sprintf('chat.qembed.%s.%s', $this->getFingerprint($this->embeddingModel), $this->getFingerprint($question));
    }

    /**
     * Returns the current knowledge-base version stamp, or "v0" before the first invalidation.
     *
     * @return string
     */
    private function getKnowledgeVersion(): string
    {
        $value = $this->cache->getItem(self::KNOWLEDGE_VERSION_KEY)->get();

        return is_string($value) ? $value : 'v0';
    }

    /**
     * Normalizes (trim, lowercase, collapse whitespace) then sha256-hashes a string for use in cache keys.
     *
     * @param string $value Raw value to fingerprint
     *
     * @return string
     */
    private function getFingerprint(string $value): string
    {
        $normalized = (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($value)));

        return hash('sha256', $normalized);
    }
}
