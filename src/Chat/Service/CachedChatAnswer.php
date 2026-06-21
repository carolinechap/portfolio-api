<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Entity\ChatOutcome;

final readonly class CachedChatAnswer
{
    /** @param array<int, string>|null $chunksUsed */
    public function __construct(
        public string $answer,
        public ChatOutcome $outcome,
        public float $topScore,
        public ?array $chunksUsed,
    ) {
    }
}
