<?php

declare(strict_types=1);

namespace App\Chat\Entity;

enum ChatOutcome: string
{
    case Answered = 'answered';
    case OffScope = 'off_scope';
    case QuotaExceeded = 'quota_exceeded';
    case ValidationError = 'validation_error';
    case InternalError = 'internal_error';
}