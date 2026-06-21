<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Entity\ChatLog;
use App\Chat\Entity\ChatOutcome;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists a {@see ChatLog} row for every chat request, regardless of outcome.
 */
final readonly class ChatLogger
{
    private const int ANSWER_MAX_CHARS = 5000;

    /**
     * Hard cap on the stored question, matching the `chat_log.question`
     * column length ({@see ChatLog} `length: 500`).
     */
    private const int QUESTION_MAX_CHARS = 500;

    /**
     * Personal-data patterns redacted from both `question` and `answer` before storage.
     *
     * @var list<string>
     */
    private const array PERSONAL_DATA_PATTERNS = [
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',    // email
        '/\b(?:\+?\d[\s.-]?){8,}\d\b/',                            // phone (broad — must have 9+ digits)
        '/\b(?:\d{4}[\s-]?){3}\d{4}\b/',                           // 16-digit card
    ];

    /**
     * API-key-shaped tokens redacted from the stored `answer` (not the
     * question — questions shouldn't carry our own keys).
     *
     * @var list<string>
     */
    private const array API_KEY_PATTERNS = [
        '/AIza[A-Za-z0-9_-]{30,}/',     // Google / Gemini
        '/sk-[A-Za-z0-9]{30,}/',         // OpenAI / Anthropic style
        '/ghp_[A-Za-z0-9]{30,}/',        // GitHub PAT
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Persists an audit log row for a single chat request.
     *
     * Defensive sanitization is applied to both `question` and `answer`
     * before they hit the database (defense for log dumps / backups):
     *
     *   1. Personal-data redaction (email, phone, credit card) via {@see self::PERSONAL_DATA_PATTERNS}.
     *   2. `strip_tags()` so a future HTML log viewer cannot be tricked into
     *      executing markup originating from user input or model output.
     *   3. Control-character stripping (`\x00-\x1F` plus DEL `\x7F`) which
     *      defends against log injection — i.e. forged newlines that fake
     *      additional log entries.
     *   4. Answer-only redaction of API-key-looking tokens via
     *      {@see self::API_KEY_PATTERNS}, in case the model echoes back a
     *      key that leaked into a prompt.
     *   5. The persisted answer is truncated to {@see self::ANSWER_MAX_CHARS}
     *      characters to keep the audit table bounded even when the model
     *      produces an unexpectedly long response.
     *   6. The persisted question is truncated to {@see self::QUESTION_MAX_CHARS}
     *      characters so logging an over-length question (e.g. one that just
     *      failed validation) cannot overflow the column.
     *
     * @param string[]|null $chunksUsed Source keys of the retrieved chunks, or null when retrieval was not run
     */
    public function log(
        string $question,
        string $answer,
        float $topScore,
        ?array $chunksUsed,
        ChatOutcome $outcome,
    ): void {
        $question = self::redactPersonalData($question);
        $answer = self::redactPersonalData($answer);

        $question = strip_tags($question);
        $answer = strip_tags(mb_substr($answer, 0, self::ANSWER_MAX_CHARS));

        $question = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $question);
        $answer = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $answer);

        $answer = (string) preg_replace(self::API_KEY_PATTERNS, '[REDACTED]', $answer);

        // Final safety net: keep the question within the column length, after
        // all redaction/stripping (none of which lengthen the string).
        $question = mb_substr($question, 0, self::QUESTION_MAX_CHARS);

        $log = new ChatLog($question, $answer, $topScore, $chunksUsed, $outcome);
        $this->em->persist($log);
        $this->em->flush();
    }

    /**
     * Replaces every personal-data match in a string with the `[PERSONAL_DATA]` placeholder.
     *
     * @param string $s Text to redact
     *
     * @return string
     */
    private static function redactPersonalData(string $s): string
    {
        return (string) preg_replace(self::PERSONAL_DATA_PATTERNS, '[PERSONAL_DATA]', $s);
    }
}
