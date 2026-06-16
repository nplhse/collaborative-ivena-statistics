<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Allocation\ImportBatchPlanner;
use App\DataFixtures\FixtureVolume;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportBatchPlannerTest extends TestCase
{
    #[Test]
    public function planDistributesImportsAcrossParticipatingHospitalsInRoundRobin(): void
    {
        $planner = new ImportBatchPlanner();
        $hospitals = [
            $this->hospital('Alpha', participating: true),
            $this->hospital('Beta', participating: true),
            $this->hospital('Gamma', participating: false),
        ];
        $volume = new FixtureVolume(
            hospitalsActive: 2,
            imports: 5,
            allocations: 100,
            mciCases: 0,
            period: '-12 months',
            pattern: 'urban-full',
            rebuildProjection: false,
        );

        $batches = $planner->plan($volume, $hospitals);

        self::assertCount(5, $batches);
        self::assertSame(
            ['Alpha', 'Beta', 'Alpha', 'Beta', 'Alpha'],
            array_map(static fn (\App\DataFixtures\Allocation\ImportBatch $batch): ?string => $batch->hospital->getName(), $batches),
        );
    }

    private function hospital(string $name, bool $participating): Hospital
    {
        return new Hospital()
            ->setName($name)
            ->setIsParticipating($participating);
    }
}
