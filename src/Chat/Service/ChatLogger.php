<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Entity\ChatLog;
use App\Chat\Entity\ChatOutcome;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ChatLogger
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /** @param string[]|null $chunksUsed */
    public function log(
        string $question,
        string $answer,
        float $topScore,
        ?array $chunksUsed,
        ChatOutcome $outcome,
    ): void {
        $log = new ChatLog($question, $answer, $topScore, $chunksUsed, $outcome);
        $this->em->persist($log);
        $this->em->flush();
    }
}
