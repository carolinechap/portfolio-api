<?php

declare(strict_types=1);

namespace App\Chat\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single turn of a chat conversation, used to forward client-side history
 * into the prompt builder.
 */
final readonly class ChatMessage
{
    public function __construct(
        #[Assert\Choice(choices: ['user', 'assistant'])]
        public string $role,
        #[Assert\NotBlank]
        #[Assert\Length(max: 1000)]
        public string $content,
    ) {
    }
}
