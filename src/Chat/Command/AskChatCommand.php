<?php

declare(strict_types=1);

namespace App\Chat\Command;

use App\Chat\Exception\GeminiException;
use App\Chat\Exception\QuotaExceededException;
use App\Chat\Repository\ChatChunkRepository;
use App\Chat\Service\EmbeddingService;
use App\Chat\Service\EmbeddingTaskType;
use App\Chat\Service\GeminiClient;
use App\Chat\Service\PromptBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:chat:ask',
    description: 'Run a single question through the chat pipeline and print the answer with retrieval diagnostics.',
)]
final class AskChatCommand extends Command
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly ChatChunkRepository $chunkRepository,
        private readonly PromptBuilder $promptBuilder,
        private readonly GeminiClient $gemini,
        #[Autowire(value: '%env(int:CHAT_RETRIEVAL_K)%')]
        private readonly int $defaultRetrievalK,
        #[Autowire(value: '%env(float:CHAT_RETRIEVAL_THRESHOLD)%')]
        private readonly float $defaultRetrievalThreshold,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'Question to send to the chatbot')
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Override the number of retrieved chunks')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Override the off-scope similarity threshold')
            ->addOption('show-prompt', null, InputOption::VALUE_NONE, 'Print the full prompt sent to Gemini');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $question = $input->getArgument('question');
        if (!is_string($question) || trim($question) === '') {
            $io->error('The question must be a non-empty string.');

            return Command::FAILURE;
        }

        $kOption = $input->getOption('k');
        $retrievalK = is_string($kOption) ? max(1, (int) $kOption) : $this->defaultRetrievalK;

        $thresholdOption = $input->getOption('threshold');
        $retrievalThreshold = is_string($thresholdOption) ? (float) $thresholdOption : $this->defaultRetrievalThreshold;

        try {
            $embedding = $this->embeddings->embed($question, EmbeddingTaskType::RetrievalQuery);
        } catch (QuotaExceededException) {
            $io->error('Gemini quota exhausted while embedding the question (outcome: quota_exceeded).');

            return Command::FAILURE;
        } catch (GeminiException $exception) {
            $io->error('Gemini error while embedding the question: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        $scoredChunks = $this->chunkRepository->findTopK($embedding, $retrievalK);
        $topScore = $scoredChunks[0]->score ?? 0.0;

        $io->section('Retrieval');
        $io->writeln(sprintf('Top score: %.4f  (threshold %.4f, k %d)', $topScore, $retrievalThreshold, $retrievalK));

        if ($scoredChunks === []) {
            $io->warning('No chunks found. Has the knowledge base been ingested (app:chat:ingest)?');
        } else {
            $io->table(
                ['score', 'source_key'],
                array_map(
                    static fn ($scoredChunk): array => [
                        sprintf('%.4f', $scoredChunk->score),
                        $scoredChunk->chunk->getSourceKey(),
                    ],
                    $scoredChunks,
                ),
            );
        }

        if ($topScore < $retrievalThreshold) {
            $io->section('Outcome');
            $io->writeln('off_scope (below threshold, no generation call)');

            return Command::SUCCESS;
        }

        $chunks = array_map(static fn ($scoredChunk) => $scoredChunk->chunk, $scoredChunks);
        $prompt = $this->promptBuilder->build($chunks, [], $question);

        if ($input->getOption('show-prompt')) {
            $io->section('Prompt');
            $io->writeln($prompt);
        }

        $answer = '';
        try {
            foreach ($this->gemini->streamGenerate($prompt) as $token) {
                $answer .= $token;
            }
        } catch (QuotaExceededException) {
            $io->error('Gemini quota exhausted while generating the answer (outcome: quota_exceeded).');

            return Command::FAILURE;
        } catch (GeminiException $exception) {
            $io->error('Gemini error while generating the answer: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        $io->section('Answer');
        $io->writeln($answer === '' ? '(empty answer)' : $answer);

        $io->section('Outcome');
        $io->writeln('answered');

        return Command::SUCCESS;
    }
}
