<?php

declare(strict_types=1);

namespace App\Chat\Entity;

/**
 * Final state of a chat request, persisted on every ChatLog row and emitted
 * to the client as the SSE "done" event payload.
 */
enum ChatOutcome: string
{
    case Answered = 'answered';
    case OffScope = 'off_scope';
    case QuotaExceeded = 'quota_exceeded';
    case ValidationError = 'validation_error';
    case InternalError = 'internal_error';
}