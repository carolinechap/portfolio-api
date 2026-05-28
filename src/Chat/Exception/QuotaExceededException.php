<?php

declare(strict_types=1);

namespace App\Chat\Exception;

/**
 * Thrown when the daily Gemini quota is exhausted or the API returns HTTP 429.
 */
final class QuotaExceededException extends \RuntimeException
{
}
