<?php

declare(strict_types=1);

namespace App\Chat\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Inbound payload for {@see \App\Chat\Controller\ChatController}: the user's
 * question plus the recent conversation history forwarded by the front-end.
 *
 * The DTO carries two defensive validation layers on top of the basic
 * length/Unicode checks:
 *
 *  - a negative `Regex` constraint on {@see self::$question} that rejects the
 *    most common prompt-injection phrasings ("ignore previous instructions",
 *    "reveal your system prompt", ...);
 *  - a honeypot field {@see self::$website} expected to be empty. The
 *    front-end never renders it so naive scraping bots fill it in and are
 *    rejected with a 400, while real users always pass.
 */
final class ChatRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 500)]
        #[Assert\Regex(
            pattern: '/^[\p{L}\p{N}\p{P}\p{Z}\p{S}]+$/u',
            message: 'Caractères non autorisés.',
        )]
        #[Assert\Regex(
            pattern: '/\b(ignore (previous|above|all)|disregard (your|the) (instructions|system prompt)|reveal (your|the) (system prompt|instructions)|you are (now|actually))\b/i',
            match: false,
            message: 'Question contains a forbidden pattern.',
        )]
        public string $question = '',
        /** @var array<int, ChatMessage> */
        #[Assert\Count(max: 6)]
        #[Assert\Valid]
        public array $history = [],
        #[Assert\Blank(message: 'Honeypot field must be empty.')]
        public string $website = '',
    ) {
    }
}
