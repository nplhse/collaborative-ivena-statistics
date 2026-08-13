<?php

declare(strict_types=1);

namespace App\User\UI\Console\Command;

use App\User\Application\Contract\UserCreatedAtBackfillImportSourceInterface;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\UserCreatedAtBackfillHospitalQuery;
use App\User\Infrastructure\Repository\UserRepository;
use App\User\UI\Console\Input\BackfillUserCreatedAtInput;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:backfill-created-at',
    description: 'Reconstruct User.createdAt from the earliest successful own import, otherwise an owned hospital import (default: dry-run preview).',
)]
final readonly class BackfillUserCreatedAtCommand
{
    public function __construct(
        private UserRepository $userRepository,
        private UserCreatedAtBackfillImportSourceInterface $importSource,
        private UserCreatedAtBackfillHospitalQuery $hospitalQuery,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] BackfillUserCreatedAtInput $input,
    ): int {
        $this->entityManager->clear();

        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }
        $dryRun = !$apply;

        $io->title('Backfill user createdAt from historical imports');

        if ($dryRun) {
            $io->note('Dry run: no rows will be written. Re-run with --apply to persist changes.');
        } else {
            $io->warning('Apply mode: user.created_at will be updated where an earlier historical date is found.');
        }

        $users = $this->userRepository->findBy([], ['id' => 'ASC']);
        $firstOwnByUser = $this->importSource->firstSuccessfulCreatedAtByUser();
        $firstImportByHospital = $this->importSource->firstSuccessfulCreatedAtByHospital();
        $hospitalsByUser = $this->hospitalQuery->ownedHospitalsByUserId();

        $inspected = 0;
        $earlierFromOwnImport = 0;
        $earlierFromHospitalImport = 0;
        $noHistoricalInformation = 0;
        $currentAlreadyEarlier = 0;
        /** @var array<int, \DateTimeImmutable> $updates */
        $updates = [];

        foreach ($users as $user) {
            $userId = $user->getId();
            if (null === $userId) {
                continue;
            }

            ++$inspected;
            $currentCreatedAt = $user->getCreatedAt();
            $decision = $this->decide(
                $userId,
                $currentCreatedAt,
                $firstOwnByUser,
                $firstImportByHospital,
                $hospitalsByUser[$userId] ?? [],
            );

            $this->writeUserReport($io, $user, $userId, $currentCreatedAt, $decision, $dryRun);

            if (
                'own' === $decision['source']
                && $decision['shouldUpdate']
                && $decision['candidate'] instanceof \DateTimeImmutable
            ) {
                ++$earlierFromOwnImport;
                $updates[$userId] = $decision['candidate'];
            } elseif (
                'hospital' === $decision['source']
                && $decision['shouldUpdate']
                && $decision['candidate'] instanceof \DateTimeImmutable
            ) {
                ++$earlierFromHospitalImport;
                $updates[$userId] = $decision['candidate'];
            } elseif (null === $decision['candidate']) {
                ++$noHistoricalInformation;
            } else {
                ++$currentAlreadyEarlier;
            }
        }

        $changed = \count($updates);

        if (!$dryRun && [] !== $updates) {
            $this->applyUpdates($updates);
        }

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Users inspected', (string) $inspected],
                ['Earlier date from own import', (string) $earlierFromOwnImport],
                ['Earlier date from hospital import', (string) $earlierFromHospitalImport],
                ['No historical information found', (string) $noHistoricalInformation],
                ['Current createdAt already earlier', (string) $currentAlreadyEarlier],
                [$dryRun ? 'Users would be changed' : 'Users changed', (string) $changed],
            ],
        );

        if ($dryRun) {
            $io->success('Dry run finished. Re-run with --apply to persist changes.');
        } else {
            $io->success(sprintf('Updated %d user(s).', $changed));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int, \DateTimeImmutable>     $firstOwnByUser
     * @param array<int, \DateTimeImmutable>     $firstImportByHospital
     * @param list<array{id: int, name: string}> $hospitals
     *
     * @return array{
     *     source: 'own'|'hospital'|null,
     *     candidate: \DateTimeImmutable|null,
     *     hospitalName: string|null,
     *     shouldUpdate: bool
     * }
     */
    private function decide(
        int $userId,
        \DateTimeImmutable $currentCreatedAt,
        array $firstOwnByUser,
        array $firstImportByHospital,
        array $hospitals,
    ): array {
        $ownFirst = $firstOwnByUser[$userId] ?? null;
        if ($ownFirst instanceof \DateTimeImmutable) {
            return [
                'source' => 'own',
                'candidate' => $ownFirst,
                'hospitalName' => null,
                'shouldUpdate' => $ownFirst < $currentCreatedAt,
            ];
        }

        $hospitalCandidate = $this->earliestHospitalImport($hospitals, $firstImportByHospital);
        if (null !== $hospitalCandidate) {
            return [
                'source' => 'hospital',
                'candidate' => $hospitalCandidate['createdAt'],
                'hospitalName' => $hospitalCandidate['name'],
                'shouldUpdate' => $hospitalCandidate['createdAt'] < $currentCreatedAt,
            ];
        }

        return [
            'source' => null,
            'candidate' => null,
            'hospitalName' => null,
            'shouldUpdate' => false,
        ];
    }

    /**
     * @param list<array{id: int, name: string}> $hospitals
     * @param array<int, \DateTimeImmutable>     $firstImportByHospital
     *
     * @return array{createdAt: \DateTimeImmutable, name: string}|null
     */
    private function earliestHospitalImport(array $hospitals, array $firstImportByHospital): ?array
    {
        $bestAt = null;
        $bestName = null;
        $bestHospitalId = null;

        foreach ($hospitals as $hospital) {
            $createdAt = $firstImportByHospital[$hospital['id']] ?? null;
            if (!$createdAt instanceof \DateTimeImmutable) {
                continue;
            }

            if (
                !$bestAt instanceof \DateTimeImmutable
                || $createdAt < $bestAt
                || ($createdAt == $bestAt && $hospital['id'] < (int) $bestHospitalId)
            ) {
                $bestAt = $createdAt;
                $bestName = $hospital['name'];
                $bestHospitalId = $hospital['id'];
            }
        }

        if (!$bestAt instanceof \DateTimeImmutable || null === $bestName) {
            return null;
        }

        return [
            'createdAt' => $bestAt,
            'name' => $bestName,
        ];
    }

    /**
     * @param array{
     *     source: 'own'|'hospital'|null,
     *     candidate: \DateTimeImmutable|null,
     *     hospitalName: string|null,
     *     shouldUpdate: bool
     * } $decision
     */
    private function writeUserReport(
        SymfonyStyle $io,
        User $user,
        int $userId,
        \DateTimeImmutable $currentCreatedAt,
        array $decision,
        bool $dryRun,
    ): void {
        $io->writeln(sprintf('User #%d – %s', $userId, $user->getUsername() ?? ''));
        $io->writeln('Current createdAt:');
        $io->writeln($currentCreatedAt->format('Y-m-d H:i'));

        if (null === $decision['candidate']) {
            $io->writeln('Own import:');
            $io->writeln('none');
            $io->writeln('Hospital import:');
            $io->writeln('none');
            $io->writeln('Action:');
            $io->writeln('no change');
            $io->newLine();

            return;
        }

        $io->writeln('Source:');
        $io->writeln('own' === $decision['source'] ? 'own import' : 'hospital import');
        if ('hospital' === $decision['source'] && null !== $decision['hospitalName']) {
            $io->writeln('Hospital:');
            $io->writeln($decision['hospitalName']);
        }
        $io->writeln('Reconstructed createdAt:');
        $io->writeln($decision['candidate']->format('Y-m-d H:i'));
        $io->writeln('Action:');
        if ($decision['shouldUpdate']) {
            $io->writeln($dryRun ? 'would update' : 'updated');
        } else {
            $io->writeln('no change – current value is already earlier');
        }
        $io->newLine();
    }

    /**
     * @param array<int, \DateTimeImmutable> $updates
     */
    private function applyUpdates(array $updates): void
    {
        $this->connection->beginTransaction();

        try {
            foreach ($updates as $userId => $createdAt) {
                $this->connection->executeStatement(
                    'UPDATE "user" SET created_at = :createdAt WHERE id = :id',
                    [
                        'createdAt' => $createdAt,
                        'id' => $userId,
                    ],
                    [
                        'createdAt' => Types::DATETIME_IMMUTABLE,
                        'id' => Types::INTEGER,
                    ],
                );
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }
}
