<?php

declare(strict_types=1);

namespace App\Chat\Stream;

final readonly class ChunkEvent implements ChatEvent
{
    public function __construct(public string $token)
    {
    }

    public function eventName(): string
    {
        return 'chunk';
    }

    public function data(): array
    {
        return ['token' => $this->token];
    }
}
