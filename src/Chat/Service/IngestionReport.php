<?php

declare(strict_types=1);

namespace App\Chat\Service;

/**
 * Aggregated counters returned by {@see KnowledgeIngester::ingest()}.
 */
final readonly class IngestionReport
{
    public function __construct(
        public int $added,
        public int $updated,
        public int $skipped,
        public int $deleted,
        public int $apiCalls,
    ) {
    }
}
