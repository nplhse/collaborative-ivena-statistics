<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Application\Service\ImportClosuresDispatcher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:closures',
    description: 'Dispatch a closure import job via Messenger using the import creator as audit user. Exit codes: 0=success, 1=import/creator not found, wrong import type, or dispatch failed, 2=invalid arguments.',
)]
final readonly class ImportClosuresCommand
{
    public function __construct(
        private ImportClosuresDispatcher $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'ID of the Import entity (required)', name: 'importId')]
        int $importId,
    ): int {
        try {
            $this->dispatcher->dispatch($importId);
        } catch (ImportNotFoundException|ImportCreatorMissingException|ImportTypeMismatchException|DispatchException $e) {
            $io->error($e->getMessage());

            return ImportDispatchExitCode::FAILURE;
        }

        $io->success(sprintf('Dispatched closure import job for Import #%d', $importId));

        return ImportDispatchExitCode::SUCCESS;
    }
}
