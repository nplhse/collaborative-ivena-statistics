<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Adapter;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Adapter\DoctrineUserCreatedAtBackfillImportSource;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;

/**
 * Earliest successful import per user and per hospital for `app:user:backfill-created-at`: completed and partial imports
 * count, failed and pending ones do not. Which of the dates the command takes is in `UserCreatedAtBackfillDecisionTest`.
 */
final class DoctrineUserCreatedAtBackfillImportSourceTest extends DatabaseKernelTestCase
{
    public function testEarliestSuccessfulImportPerUserAndPerHospital(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice']);
        $bob = UserFactory::createOne(['username' => 'bob']);
        $hospital = HospitalFactory::createOne(['name' => 'Status Klinik', 'owner' => $alice]);
        $this->import($alice, $hospital, ImportStatus::FAILED, '2020-01-01 00:00:00');
        $this->import($alice, $hospital, ImportStatus::PENDING, '2020-06-01 00:00:00');
        $this->import($alice, $hospital, ImportStatus::PARTIAL, '2022-05-10 09:42:00');
        $this->import($alice, $hospital, ImportStatus::COMPLETED, '2023-01-01 10:00:00');
        $this->import($bob, $hospital, ImportStatus::COMPLETED, '2021-03-04 05:06:00');
        $source = self::getContainer()->get(DoctrineUserCreatedAtBackfillImportSource::class);

        $byUser = $source->firstSuccessfulCreatedAtByUser();
        $byHospital = $source->firstSuccessfulCreatedAtByHospital();

        self::assertSame(
            [(int) $alice->getId() => '2022-05-10 09:42:00', (int) $bob->getId() => '2021-03-04 05:06:00'],
            $this->formatted($byUser),
        );
        self::assertSame([(int) $hospital->getId() => '2021-03-04 05:06:00'], $this->formatted($byHospital));
    }

    public function testUsersAndHospitalsWithoutSuccessfulImportAreMissing(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice']);
        $hospital = HospitalFactory::createOne(['name' => 'Failed Klinik', 'owner' => $alice]);
        $this->import($alice, $hospital, ImportStatus::FAILED, '2020-01-01 00:00:00');
        $source = self::getContainer()->get(DoctrineUserCreatedAtBackfillImportSource::class);

        $found = [$source->firstSuccessfulCreatedAtByUser(), $source->firstSuccessfulCreatedAtByHospital()];

        self::assertSame([[], []], $found);
    }

    private function import(User $createdBy, Hospital $hospital, ImportStatus $status, string $createdAt): void
    {
        ImportFactory::createOne([
            'createdBy' => $createdBy,
            'hospital' => $hospital,
            'status' => $status,
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    /**
     * @param array<int, \DateTimeImmutable> $dates
     *
     * @return array<int, string>
     */
    private function formatted(array $dates): array
    {
        ksort($dates);

        return array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d H:i:s'), $dates);
    }
}
