<?php

declare(strict_types=1);

namespace App\Chat\Repository;

use App\Chat\Entity\ChatChunk;

/**
 * Pairs a {@see ChatChunk} with its cosine-similarity score against a query embedding.
 */
final readonly class ScoredChunk
{
    public function __construct(
        public ChatChunk $chunk,
        public float $score,
    ) {
    }
}