<?php

declare(strict_types=1);

namespace App\Chat\Repository;

use App\Chat\Entity\ChatChunk;

final readonly class ScoredChunk
{
    public function __construct(
        public ChatChunk $chunk,
        public float $score,
    ) {
    }
}