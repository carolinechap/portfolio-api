<?php

declare(strict_types=1);

namespace App\Chat\Http;

use App\Chat\Stream\ChatEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SseStreamFactory
{
    /**
     * Wraps a stream of chat events into a Server-Sent Events response.
     *
     * @param iterable<ChatEvent> $events Ordered chat events to emit
     *
     * @return StreamedResponse
     */
    public function stream(iterable $events): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        $response->setCallback(static function () use ($events): void {
            foreach ($events as $event) {
                echo 'event: ' . $event->eventName() . "\n";
                echo 'data: ' . json_encode($event->data(), JSON_THROW_ON_ERROR) . "\n\n";

                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        });

        return $response;
    }
}
