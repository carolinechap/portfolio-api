<?php

declare(strict_types=1);

namespace App\Chat\Command;

use App\Chat\Service\KnowledgeIngester;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Console command that loads the canonical knowledge JSON file and pushes
 * it through {@see KnowledgeIngester} to update the chat_chunk table.
 *
 * Every successful run emits an `info`-level audit log entry with the file
 * path and the per-bucket counters returned by the ingester, so changes to
 * the knowledge base can be traced after the fact in the application logs.
 */
#[AsCommand(name: 'app:chat:ingest', description: 'Ingest the chat knowledge JSON into chat_chunk.')]
final class IngestChatKnowledgeCommand extends Command
{
    public function __construct(
        private readonly KnowledgeIngester $ingester,
        #[Autowire(value: '%env(resolve:CHAT_KNOWLEDGE_FILE)%')]
        private readonly string $defaultFile,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Override knowledge file path')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-embed all entries')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show diff without changes');
    }

    /**
     * Loads the knowledge JSON, decodes it, and forwards it to the ingester.
     *
     * Returns {@see Command::FAILURE} on missing/unreadable file, invalid JSON,
     * invalid payload shape, or when the ingester rejects a chunk via
     * {@see \InvalidArgumentException} (forbidden-pattern match).
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException When an unknown CLI option is requested
     * @throws \App\Chat\Exception\QuotaExceededException                    Propagated from the ingester on Gemini quota exhaustion
     * @throws \App\Chat\Exception\GeminiException                           Propagated from the ingester on Gemini transport errors
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileOpt = $input->getOption('file');
        $file = is_string($fileOpt) ? $fileOpt : $this->defaultFile;

        if (!is_file($file)) {
            $io->error(\sprintf('Knowledge file not found: %s', $file));
            return Command::FAILURE;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $io->error('Could not read file.');
            return Command::FAILURE;
        }

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error('Invalid JSON: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!is_array($payload) || !isset($payload['entries']) || !is_array($payload['entries'])) {
            $io->error('Invalid payload: expected an "entries" array.');
            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry-run not yet implemented in v1 — re-run without --dry-run.');
            return Command::SUCCESS;
        }

        /** @var array{entries: list<array{key: string, type?: string, content: string, tags?: list<string>}>} $payload */
        try {
            $report = $this->ingester->ingest($payload, (bool) $input->getOption('force'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->logger->info('Chat knowledge ingested', [
            'file' => $file,
            'added' => $report->added,
            'updated' => $report->updated,
            'skipped' => $report->skipped,
            'deleted' => $report->deleted,
            'apiCalls' => $report->apiCalls,
        ]);

        $io->success('Ingestion complete');
        $io->listing([
            \sprintf('Added       : %d', $report->added),
            \sprintf('Updated     : %d', $report->updated),
            \sprintf('Skipped     : %d', $report->skipped),
            \sprintf('Deleted     : %d', $report->deleted),
            \sprintf('Gemini calls: %d', $report->apiCalls),
        ]);

        return Command::SUCCESS;
    }
}
