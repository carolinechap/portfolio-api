<?php

declare(strict_types=1);

namespace App\Chat\Command;

use App\Chat\Repository\ChatLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Console command that deletes chat_log rows older than the configured
 * retention window (`CHAT_LOG_RETENTION_DAYS`). Intended to be run on a
 * recurring schedule.
 */
#[AsCommand(name: 'app:chat:purge-logs', description: 'Purge chat_log entries older than CHAT_LOG_RETENTION_DAYS.')]
final class PurgeChatLogsCommand extends Command
{
    public function __construct(
        private readonly ChatLogRepository $repo,
        #[Autowire(value: '%env(int:CHAT_LOG_RETENTION_DAYS)%')]
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $threshold = (new \DateTimeImmutable())->modify(\sprintf('-%d days', $this->retentionDays));
        $deleted = $this->repo->deleteOlderThan($threshold);
        $io->success(\sprintf('Deleted: %d', $deleted));

        return Command::SUCCESS;
    }
}
