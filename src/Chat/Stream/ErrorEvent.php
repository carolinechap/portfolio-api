<?php

declare(strict_types=1);

namespace App\Chat\Stream;

final readonly class ErrorEvent implements ChatEvent
{
    private const array MESSAGES = [
        'quota_exceeded' => "L'assistant est très sollicité aujourd'hui et n'est plus disponible. Réessaie demain ou utilise le formulaire de contact.",
        'internal_error' => 'Une erreur est survenue, réessaie dans un instant.',
    ];

    public function __construct(public string $reason)
    {
    }

    public function eventName(): string
    {
        return 'error';
    }

    public function data(): array
    {
        return [
            'reason' => $this->reason,
            'message' => self::MESSAGES[$this->reason] ?? 'Une erreur est survenue.',
        ];
    }
}
