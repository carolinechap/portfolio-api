<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Dto\ChatMessage;
use App\Chat\Entity\ChatChunk;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
     * @param ChatChunk[]   $chunks
     * @param ChatMessage[] $history
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
     * @param  ChatMessage[] $history
     * @return ChatMessage[]
     */
    private function trimHistory(array $history): array
    {
        $maxMessages = $this->historyMaxPairs * 2;

        return array_slice($history, -$maxMessages);
    }

    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1) . '…';
    }
}
