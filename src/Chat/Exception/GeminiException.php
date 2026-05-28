<?php

declare(strict_types=1);

namespace App\Chat\Exception;

/**
 * Thrown on transport errors or unexpected response shapes from the Gemini API.
 */
final class GeminiException extends \RuntimeException
{
}
