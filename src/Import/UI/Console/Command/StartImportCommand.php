<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\Exception\UnsupportedImportTypeException;
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Application\Service\ImportStartDispatcher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:start',
    description: 'Dispatch an import job via Messenger using the import creator as audit user. The import type is resolved from the Import. Exit codes: 0=success, 1=import/creator not found, missing import type, or dispatch failed, 2=invalid arguments.',
)]
final readonly class StartImportCommand
{
    public function __construct(
        private ImportStartDispatcher $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'ID of the Import entity (required)', name: 'importId')]
        int $importId,
    ): int {
        try {
            $type = $this->dispatcher->dispatch($importId);
        } catch (ImportNotFoundException|ImportCreatorMissingException|ImportTypeMismatchException|UnsupportedImportTypeException|DispatchException $e) {
            $io->error($e->getMessage());

            return ImportDispatchExitCode::FAILURE;
        }

        $io->success(sprintf('Dispatched %s import job for Import #%d', $type->value, $importId));

        return ImportDispatchExitCode::SUCCESS;
    }
}
