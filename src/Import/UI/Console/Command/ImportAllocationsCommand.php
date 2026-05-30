<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Application\Service\ImportAllocationsDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:import:allocations',
    description: 'Dispatch an allocation import job via Messenger using the import creator as audit user. Exit codes: 0=success, 1=import/creator not found or dispatch failed, 2=invalid arguments.',
)]
final class ImportAllocationsCommand extends Command
{
    public function __construct(
        private readonly ImportAllocationsDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('importId', InputArgument::REQUIRED, 'ID of the Import entity (required)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $importId = (int) $input->getArgument('importId');

        try {
            $this->dispatcher->dispatch($importId);
        } catch (ImportNotFoundException|ImportCreatorMissingException|DispatchException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return ImportDispatchExitCode::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Dispatched import job for Import #%d"</info>',
            $importId,
        ));

        return ImportDispatchExitCode::SUCCESS;
    }
}
