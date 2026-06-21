<?php

declare(strict_types=1);

namespace App\Chat\Stream;

interface ChatEvent
{
    public function eventName(): string;

    /** @return array<string, mixed> */
    public function data(): array;
}
