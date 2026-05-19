<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Dto\ChatMessage;
use App\Chat\Entity\ChatChunk;
use App\Chat\Service\PromptBuilder;
use PHPUnit\Framework\TestCase;

final class PromptBuilderTest extends TestCase
{
    private const string SYSTEM_PROMPT_FILE = __DIR__ . '/../../../config/prompts/chat_system.txt';

    public function testBuildContainsSystemRulesAndChunksAndQuestion(): void
    {
        $b = new PromptBuilder($this->loadSystemPrompt(), historyMaxPairs: 3);
        $chunks = [
            new ChatChunk('a', 'Caroline est dev Symfony.', 'h1', [0.0]),
            new ChatChunk('b', 'Elle a travaillé sur Ergonimes.', 'h2', [0.0]),
        ];

        $prompt = $b->build($chunks, [], 'Tu fais quoi ?');

        self::assertStringContainsString('Caroline Chapeau', $prompt);
        self::assertStringContainsString('Caroline est dev Symfony.', $prompt);
        self::assertStringContainsString('Elle a travaillé sur Ergonimes.', $prompt);
        self::assertStringContainsString('QUESTION : Tu fais quoi ?', $prompt);
        self::assertStringNotContainsString('HISTORIQUE', $prompt);
    }

    public function testBuildTruncatesHistoryAndAnswers(): void
    {
        $b = new PromptBuilder($this->loadSystemPrompt(), historyMaxPairs: 2);
        $longAnswer = str_repeat('x', 500);
        $history = [
            new ChatMessage('user', 'Q1'),
            new ChatMessage('assistant', 'A1'),
            new ChatMessage('user', 'Q2'),
            new ChatMessage('assistant', $longAnswer),
            new ChatMessage('user', 'Q3'),
            new ChatMessage('assistant', 'A3'),
        ];

        $prompt = $b->build([], $history, 'maintenant ?');

        self::assertStringContainsString('Q2', $prompt);
        self::assertStringContainsString('Q3', $prompt);
        self::assertStringNotContainsString('Q1', $prompt);
        self::assertStringContainsString('xxx', $prompt);
        self::assertStringNotContainsString(str_repeat('x', 250), $prompt);
    }

    private function loadSystemPrompt(): string
    {
        $content = file_get_contents(self::SYSTEM_PROMPT_FILE);
        self::assertNotFalse($content, 'System prompt file is missing: ' . self::SYSTEM_PROMPT_FILE);

        return $content;
    }
}
