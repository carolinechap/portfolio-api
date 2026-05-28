<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Dto\ChatMessage;
use App\Chat\Entity\ChatChunk;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assembles the final Gemini prompt by concatenating the system prompt, the
 * retrieved context chunks, the (trimmed) conversation history and the
 * current user question.
 */
final readonly class PromptBuilder
{
    private const int ANSWER_TRUNCATE_CHARS = 200;

    public function __construct(
        #[Autowire(value: '%env(file:resolve:CHAT_SYSTEM_PROMPT_FILE)%')]
        private string $systemPrompt,
        #[Autowire(value: '%env(int:CHAT_HISTORY_MAX_PAIRS)%')]
        private int $historyMaxPairs = 3,
    ) {
    }

    /**
     * Builds the full prompt sent to the generation model.
     *
     * Assistant turns in the history are truncated to keep the prompt short;
     * the history itself is trimmed to the last $historyMaxPairs exchanges.
     *
     * @param ChatChunk[]   $chunks   Retrieved context chunks (in display order)
     * @param ChatMessage[] $history  Conversation history, oldest-first
     * @param string        $question Current user question
     */
    public function build(array $chunks, array $history, string $question): string
    {
        $parts = [rtrim($this->systemPrompt), '', 'CONTEXTE :'];
        foreach ($chunks as $i => $chunk) {
            $parts[] = \sprintf('[%d] %s', $i + 1, $chunk->getContent());
        }

        $trimmed = $this->trimHistory($history);
        if ($trimmed !== []) {
            $parts[] = '';
            $parts[] = 'HISTORIQUE (pour comprendre le fil) :';
            foreach ($trimmed as $m) {
                $content = $m->role === 'assistant'
                    ? self::truncate($m->content, self::ANSWER_TRUNCATE_CHARS)
                    : $m->content;
                $parts[] = \sprintf('%s: %s', $m->role, $content);
            }
        }

        $parts[] = '';
        $parts[] = 'QUESTION : ' . $question;

        return implode("\n", $parts);
    }

    /**
     * Keeps only the last $historyMaxPairs user/assistant pairs (i.e. 2× messages).
     *
     * @param  ChatMessage[] $history
     * @return ChatMessage[]
     */
    private function trimHistory(array $history): array
    {
        $maxMessages = $this->historyMaxPairs * 2;

        return array_slice($history, -$maxMessages);
    }

    /**
     * Multibyte-safe truncation that appends an ellipsis when the string is cut.
     */
    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1) . '…';
    }
}
