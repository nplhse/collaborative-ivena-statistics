<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ScheduleScope;
use App\MessageHandler\ScheduleScopesHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:import:process',
    description: 'Trigger asynchronous recalculation of all statistics for a given import_id'
)]
final class ProcessImportCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ScheduleScopesHandler $handler,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('import-id', null, InputOption::VALUE_REQUIRED, 'Import ID to process')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Run scheduling synchronously (direct call) instead of dispatching to Messenger');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $importOpt = $input->getOption('import-id');

        if (null === $importOpt || !ctype_digit($importOpt)) {
            $io->error('--import is required and must be a numeric id');

            return Command::FAILURE;
        }

        $importId = (int) $importOpt;
        $msg = new ScheduleScope($importId);

        if ($input->getOption('sync')) {
            $this->handler->__invoke($msg);
            $io->success(sprintf('Scheduled slice recomputation synchronously for import_id=%d.', $importId));
        } else {
            $this->bus->dispatch($msg);
            $io->success(sprintf('Dispatched import_id=%d for asynchronous processing.', $importId));
        }

        return Command::SUCCESS;
    }
}
