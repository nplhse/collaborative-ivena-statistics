<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Infrastructure;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Encounter\Application\EncounterShares;
use App\DataFixtures\Encounter\Application\GeneratedEncounters;
use App\DataFixtures\Encounter\Application\PersistedVisit;
use App\DataFixtures\Encounter\Application\SyntheticEncounterGenerator;
use App\User\Domain\Entity\User;

/**
 * Generates the visits of one hospital and month from the allocations in the database (#1052):
 * `generate()` only samples, `load()` also writes them as one synthetic encounter import.
 */
final readonly class SyntheticEncounterLoader
{
    public function __construct(
        private AllocationSnapshotQuery $allocations,
        private SyntheticEncounterGenerator $generator,
        private SyntheticEncounterWriter $writer,
    ) {
    }

    /**
     * @param string $month `Y-m`
     */
    public function generate(Hospital $hospital, int $seed, string $month, \DateTimeImmutable $lastDay, EncounterShares $shares = new EncounterShares()): GeneratedEncounters
    {
        $monthStart = new \DateTimeImmutable($month.'-01 00:00:00');
        $until = min($monthStart->modify('first day of next month'), $lastDay->setTime(0, 0)->modify('+1 day'));
        $snapshots = $this->allocations->forHospital((int) $hospital->getId(), $monthStart, $until);

        return $this->generator->generate($seed, (string) $hospital->getPublicId(), $monthStart, $lastDay, $snapshots, $shares);
    }

    /**
     * @param string $month `Y-m`
     *
     * @return list<PersistedVisit>
     *
     * @psalm-api The fixture ignores the truth; DB tests (matcher, #1053) compare against it.
     */
    public function load(Hospital $hospital, int $seed, string $month, \DateTimeImmutable $lastDay, User $createdBy, \DateTimeImmutable $uploadedAt, EncounterShares $shares = new EncounterShares()): array
    {
        return $this->writer->write($hospital, $seed, $month, $this->generate($hospital, $seed, $month, $lastDay, $shares), $createdBy, $uploadedAt);
    }
}
