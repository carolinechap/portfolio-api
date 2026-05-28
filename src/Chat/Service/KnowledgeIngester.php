<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Entity\ChatChunk;
use App\Chat\Repository\ChatChunkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronizes the chat_chunk table with the canonical knowledge JSON file.
 *
 * For each entry: inserts when the source key is unknown, updates when the
 * content hash differs (or --force is passed), skips otherwise. Entries that
 * disappeared from the payload are deleted from the table. Embedding calls
 * are batched to minimize Gemini API usage.
 *
 * Before any embedding work, every entry's `content` is screened against
 * {@see self::FORBIDDEN_PATTERNS}. Any match — instruction-injection
 * phrasing, `$ENV_VAR` leaks, or API-key-shaped tokens — aborts the whole
 * ingestion via {@see \InvalidArgumentException}, so a poisoned knowledge
 * file can never reach the chat_chunk table.
 */
final readonly class KnowledgeIngester
{
    private const int BATCH_SIZE = 100;

    /**
     * Patterns whose presence in a chunk's content disqualifies the whole
     * ingestion batch.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_PATTERNS = [
        '/\$[A-Z_]{3,}/',                                                    // $ENV_VAR
        '/\b(ignore|disregard|reveal)\b.{0,20}(instruction|prompt|system)/i', // instruction injection
        '/AIza[A-Za-z0-9_-]{30,}/',                                          // Google key
        '/sk-[A-Za-z0-9]{30,}/',                                              // OpenAI style
        '/ghp_[A-Za-z0-9]{30,}/',                                             // GitHub PAT
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ChatChunkRepository $repo,
        private EmbeddingService $embeddings,
    ) {
    }

    /**
     * Ingests the knowledge payload and returns counters for the operations performed.
     *
     * @param array{entries: list<array{key: string, type?: string, content: string, tags?: list<string>}>} $payload Decoded knowledge JSON
     * @param bool                                                                                          $force   When true, re-embeds entries even if their content hash matches
     *
     * @throws \InvalidArgumentException                  When a chunk's content matches one of {@see self::FORBIDDEN_PATTERNS}
     * @throws \App\Chat\Exception\QuotaExceededException When the Gemini daily quota is exhausted during embedding
     * @throws \App\Chat\Exception\GeminiException        On transport errors or unexpected Gemini response shape
     */
    public function ingest(array $payload, bool $force = false): IngestionReport
    {
        $entries = $payload['entries'];

        foreach ($entries as $entry) {
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, $entry['content']) === 1) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Chunk "%s" rejected: content matches forbidden pattern',
                        $entry['key'],
                    ));
                }
            }
        }

        $keys = array_map(static fn (array $e): string => $e['key'], $entries);

        $added = $updated = $skipped = $apiCalls = 0;

        $toEmbed = []; // batched items: ['entry' => ..., 'hash' => ..., 'mode' => 'insert'|'update', 'existing' => ChatChunk|null]
        foreach ($entries as $entry) {
            $hash = hash('sha256', $entry['content']);
            $existing = $this->repo->findOneBySourceKey($entry['key']);
            if ($existing === null) {
                $toEmbed[] = ['entry' => $entry, 'hash' => $hash, 'mode' => 'insert', 'existing' => null];
                continue;
            }
            if (!$force && $existing->getContentHash() === $hash) {
                $skipped++;
                continue;
            }
            $toEmbed[] = ['entry' => $entry, 'hash' => $hash, 'mode' => 'update', 'existing' => $existing];
        }

        foreach (array_chunk($toEmbed, self::BATCH_SIZE) as $batch) {
            $texts = array_map(static fn (array $i): string => $i['entry']['content'], $batch);
            $vectors = $this->embeddings->embedBatch($texts);
            $apiCalls++;
            foreach ($batch as $i => $item) {
                $vector = $vectors[$i];
                $meta = ['type' => $item['entry']['type'] ?? null, 'tags' => $item['entry']['tags'] ?? []];
                if ($item['mode'] === 'insert') {
                    $chunk = new ChatChunk(
                        $item['entry']['key'],
                        $item['entry']['content'],
                        $item['hash'],
                        $vector,
                        $meta,
                    );
                    $this->em->persist($chunk);
                    $added++;
                } else {
                    $existing = $item['existing'];
                    $existing->update($item['entry']['content'], $item['hash'], $vector, $meta);
                    $updated++;
                }
            }
            $this->em->flush();
        }

        $deleted = $this->repo->deleteNotIn($keys);
        $this->repo->invalidateCache();

        return new IngestionReport($added, $updated, $skipped, $deleted, $apiCalls);
    }
}
