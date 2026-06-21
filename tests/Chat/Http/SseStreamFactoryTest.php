<?php

declare(strict_types=1);

namespace App\Tests\Chat\Http;

use App\Chat\Entity\ChatOutcome;
use App\Chat\Http\SseStreamFactory;
use App\Chat\Stream\ChunkEvent;
use App\Chat\Stream\DoneEvent;
use App\Chat\Stream\ErrorEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SseStreamFactoryTest extends TestCase
{
    public function testSetsServerSentEventHeaders(): void
    {
        $response = (new SseStreamFactory())->stream([new DoneEvent(ChatOutcome::Answered)]);

        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function testWritesChunkAndDoneEvents(): void
    {
        $output = $this->render((new SseStreamFactory())->stream([
            new ChunkEvent('Bonjour'),
            new DoneEvent(ChatOutcome::Answered),
        ]));

        self::assertStringContainsString("event: chunk\n", $output);
        self::assertStringContainsString('data: {"token":"Bonjour"}', $output);
        self::assertStringContainsString("event: done\n", $output);
        self::assertStringContainsString('"outcome":"answered"', $output);
    }

    public function testErrorEventCarriesFrenchMessage(): void
    {
        $output = $this->render((new SseStreamFactory())->stream([new ErrorEvent('quota_exceeded')]));

        self::assertStringContainsString("event: error\n", $output);
        self::assertStringContainsString('"reason":"quota_exceeded"', $output);
        self::assertStringContainsString('sollicit', $output);
    }

    private function render(StreamedResponse $response): string
    {
        ob_start();
        ob_start();
        $response->sendContent();
        ob_end_flush();

        return (string) ob_get_clean();
    }
}
