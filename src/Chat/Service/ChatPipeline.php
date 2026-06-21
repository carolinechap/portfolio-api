<?php

declare(strict_types=1);

namespace App\Chat\Service;

use App\Chat\Dto\ChatRequest;
use App\Chat\Entity\ChatOutcome;
use App\Chat\Exception\GeminiException;
use App\Chat\Exception\QuotaExceededException;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Stream\ChatEvent;
use App\Chat\Stream\ChunkEvent;
use App\Chat\Stream\DoneEvent;
use App\Chat\Stream\ErrorEvent;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ChatPipeline
{
    private const string SYSTEM_PROMPT_LEAK_MARKER = 'Lead développeuse back spécialisée Symfony et Drupal. Tu réponds UNIQUEMENT';
    private const string OFF_SCOPE_MESSAGE = "Désolée, je ne sais pas répondre à ça. Je suis l'assistant de Caroline.";
    private const string GREETING_BODY = " ! J'espère que tu vas bien. Je suis l'assistant de Caroline et je réponds à tes questions sur son parcours, ses projets et ses compétences.";

    /** Local time (Europe/Paris) before which the greeting uses "Bon matin" instead of "Bonjour". */
    private const int MORNING_UNTIL_HOUR = 13;

    /**
     * Words that, on their own or combined only with {@see self::GREETING_FILLERS},
     * make a message a pure greeting handled before retrieval.
     *
     * @var list<string>
     */
    private const array GREETING_WORDS = ['bonjour', 'bonsoir', 'salut', 'coucou', 'hello', 'hi', 'hey', 'yo', 'hola', 'bjr', 'bsr', 'slt', 'cc', 're', 'rebonjour', 'hallo', 'wesh'];

    /**
     * Small-talk words tolerated alongside a greeting word ("salut ça va",
     * "hello comment vas-tu") without turning the message into a real question.
     *
     * @var list<string>
     */
    private const array GREETING_FILLERS = ['ca', 'va', 'vas', 'comment', 'tu', 'vous', 'allez', 'toi', 'et', 'bonne', 'journee', 'soiree', 'matinee', 'matin'];

    public function __construct(
        private EmbeddingService $embeddings,
        private ChatChunkRepository $chunkRepository,
        private PromptBuilder $promptBuilder,
        private GeminiClient $gemini,
        private ChatLogger $logger,
        private ChatQueryCache $queryCache,
        private ClockInterface $clock,
        #[Autowire(value: '%env(int:CHAT_RETRIEVAL_K)%')]
        private int $retrievalK,
        #[Autowire(value: '%env(float:CHAT_RETRIEVAL_THRESHOLD)%')]
        private float $retrievalThreshold,
    ) {
    }

    /**
     * Runs the retrieval-augmented generation pipeline and yields the response
     * as a stream of chat events. Quota/Gemini failures are reported as an
     * `error` event; nothing is thrown to the caller.
     *
     * @param ChatRequest $request Validated chat request
     *
     * @return \Generator<int, ChatEvent>
     */
    public function run(ChatRequest $request): \Generator
    {
        if ($this->isGreeting($request->question)) {
            $message = $this->greetingWord() . self::GREETING_BODY;
            yield new ChunkEvent($message);
            yield new DoneEvent(ChatOutcome::Answered);
            $this->logger->log($request->question, $message, 0.0, null, ChatOutcome::Answered);

            return;
        }

        $cacheable = $request->history === [];

        if ($cacheable) {
            $cached = $this->queryCache->getAnswer($request->question);
            if ($cached !== null) {
                yield new ChunkEvent($cached->answer);
                yield new DoneEvent($cached->outcome);
                $this->logger->log($request->question, $cached->answer, $cached->topScore, $cached->chunksUsed, $cached->outcome);

                return;
            }
        }

        $embedding = $this->queryCache->getEmbedding($request->question);
        if ($embedding === null) {
            try {
                $embedding = $this->embeddings->embed($request->question, EmbeddingTaskType::RetrievalQuery);
            } catch (QuotaExceededException) {
                yield new ErrorEvent('quota_exceeded');
                $this->logger->log($request->question, '', 0.0, null, ChatOutcome::QuotaExceeded);

                return;
            } catch (GeminiException) {
                yield new ErrorEvent('internal_error');
                $this->logger->log($request->question, '', 0.0, null, ChatOutcome::InternalError);

                return;
            }
            $this->queryCache->storeEmbedding($request->question, $embedding);
        }

        $scoredChunks = $this->chunkRepository->findTopK($embedding, $this->retrievalK);
        $topScore = $scoredChunks[0]->score ?? 0.0;

        if ($topScore < $this->retrievalThreshold) {
            yield new ChunkEvent(self::OFF_SCOPE_MESSAGE);
            yield new DoneEvent(ChatOutcome::OffScope);
            $this->logger->log($request->question, self::OFF_SCOPE_MESSAGE, $topScore, null, ChatOutcome::OffScope);
            if ($cacheable) {
                $this->queryCache->storeAnswer($request->question, new CachedChatAnswer(self::OFF_SCOPE_MESSAGE, ChatOutcome::OffScope, $topScore, null));
            }

            return;
        }

        $chunks = array_map(static fn ($scoredChunk) => $scoredChunk->chunk, $scoredChunks);
        $sourceKeys = array_values(array_map(static fn ($chunk) => $chunk->getSourceKey(), $chunks));
        $prompt = $this->promptBuilder->build($chunks, $request->history, $request->question, $this->greetingWord());

        $answer = '';
        try {
            foreach ($this->gemini->streamGenerate($prompt) as $token) {
                $answer .= $token;
                yield new ChunkEvent($token);
                // Cut off the stream if the response returns the system prompt (to prevent leaks).
                if (str_contains($answer, self::SYSTEM_PROMPT_LEAK_MARKER)) {
                    $this->logger->log($request->question, $answer, $topScore, $sourceKeys, ChatOutcome::InternalError);
                    yield new DoneEvent(ChatOutcome::Answered);

                    return;
                }
            }
        } catch (QuotaExceededException) {
            yield new ErrorEvent('quota_exceeded');
            $this->logger->log($request->question, $answer, $topScore, $sourceKeys, ChatOutcome::QuotaExceeded);

            return;
        } catch (GeminiException) {
            yield new ErrorEvent('internal_error');
            $this->logger->log($request->question, $answer, $topScore, $sourceKeys, ChatOutcome::InternalError);

            return;
        }

        yield new DoneEvent(ChatOutcome::Answered);
        $this->logger->log($request->question, $answer, $topScore, $sourceKeys, ChatOutcome::Answered);
        if ($cacheable) {
            $this->queryCache->storeAnswer($request->question, new CachedChatAnswer($answer, ChatOutcome::Answered, $topScore, $sourceKeys));
        }
    }

    /**
     * True when the message is only a greeting (optionally with small talk like
     * "ça va"), so it can be answered warmly before retrieval — a bare greeting
     * is semantically too far from the knowledge base to clear the retrieval
     * threshold and would otherwise hit the off-scope branch. A greeting
     * followed by a real question ("Bonjour, tu fais du Symfony ?") returns
     * false and flows through normal retrieval + generation.
     */
    private function isGreeting(string $question): bool
    {
        $normalized = strtr(mb_strtolower(trim($question)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $normalized = preg_replace('/[^a-z]+/', ' ', $normalized) ?? '';
        $words = array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));

        if ($words === []) {
            return false;
        }

        $hasGreeting = false;
        foreach ($words as $word) {
            if (in_array($word, self::GREETING_WORDS, true)) {
                $hasGreeting = true;

                continue;
            }
            if (!in_array($word, self::GREETING_FILLERS, true)) {
                return false;
            }
        }

        return $hasGreeting;
    }

    /**
     * Time-of-day greeting word in Caroline's timezone: "Bon matin" before
     * {@see self::MORNING_UNTIL_HOUR}h (Europe/Paris), "Bonjour" afterwards.
     */
    private function greetingWord(): string
    {
        $hour = (int) $this->clock->now()
            ->setTimezone(new \DateTimeZone('Europe/Paris'))
            ->format('G');

        return $hour < self::MORNING_UNTIL_HOUR ? 'Bon matin' : 'Bonjour';
    }
}
