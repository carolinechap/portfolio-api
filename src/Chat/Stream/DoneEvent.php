<?php

declare(strict_types=1);

namespace App\Chat\Stream;

use App\Chat\Entity\ChatOutcome;

final readonly class DoneEvent implements ChatEvent
{
    public function __construct(public ChatOutcome $outcome)
    {
    }

    public function eventName(): string
    {
        return 'done';
    }

    public function data(): array
    {
        return ['outcome' => $this->outcome->value];
    }
}
