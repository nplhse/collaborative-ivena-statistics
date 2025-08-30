<?php

namespace App\Command;

use App\Entity\Import;
use App\Message\ImportAllocationsMessage;
use App\Repository\ImportRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:import:allocations',
    description: 'Dispatch an allocation import job via Messenger',
)]
final class RunAllocationImportCommand extends Command
{
    public function __construct(
        private readonly ImportRepository $importRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('importId', InputArgument::REQUIRED, 'ID of the Import entity')
            ->addArgument('filePath', InputArgument::REQUIRED, 'Path to the CSV file (relative to imports dir or absolute)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $importId = (int) $input->getArgument('importId');
        $filePath = $input->getArgument('filePath');

        $import = $this->importRepository->find($importId);

        if (!$import instanceof Import) {
            $output->writeln(sprintf('<error>No Import found with ID %d</error>', $importId));

            return Command::FAILURE;
        }

        // create message
        $message = new ImportAllocationsMessage($importId);

        // dispatch via Messenger (sync or async depending on transport config)
        $this->bus->dispatch($message);

        $output->writeln(sprintf(
            '<info>Dispatched import job for Import #%d with file "%s"</info>',
            $importId,
            $filePath
        ));

        return Command::SUCCESS;
    }
}
