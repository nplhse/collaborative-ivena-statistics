<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\State;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;
use App\DataFixtures\Pattern\Application\PatternSampler;
use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

/**
 * Generates synthetic allocations for the participating demo hospitals.
 *
 * Every active hospital gets one import per month and a realistic number of allocations per day
 * (by size, weekday and season), sampled with the default pattern of its segment. Allocations are
 * written in batches through DBAL, so a year of realistic volume loads in minutes.
 *
 * Every hospital and month draws from its own random streams (ADR 022), so its rows do not depend
 * on which other hospitals or months are generated.
 */
final readonly class SyntheticAllocationGenerator
{
    private const int INSERT_BATCH_SIZE = 1000;

    private const array COLUMNS = [
        'created_at', 'arrival_at', 'gender', 'age', 'urgency', 'transport_type',
        'requires_resus', 'requires_cathlab', 'is_cpr', 'is_ventilated', 'is_shock', 'is_pregnant',
        'is_with_physician', 'is_work_accident', 'department_was_closed',
        'hospital_id', 'dispatch_area_id', 'state_id', 'import_id',
        'speciality_id', 'department_id', 'assignment_id', 'occasion_id', 'infection_id',
        'secondary_transport_id', 'indication_raw_id', 'indication_normalized_id',
    ];

    public function __construct(
        private FixtureHospitals $hospitals,
        private FixtureHospitalSelection $selection,
        private FixtureVolumeResolver $volumeResolver,
        private ImportBatchPlanner $batchPlanner,
        private DefaultDistributionPatterns $patterns,
        private PatternSampler $patternSampler,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private AllocationStatsProjectionRebuildInterface $projectionRebuilder,
        private RandomStreams $streams,
        private ClockInterface $clock,
    ) {
    }

    public function generate(): void
    {
        $volume = $this->volumeResolver->resolve();

        [$periodStart, $periodEnd] = $this->period($volume);
        $hospitals = $this->selection->active($volume, $this->hospitals->participatingHospitals());
        $fooHospital = $this->requireDemoHospitalOwnedBy('foo');
        if (!\in_array((int) $fooHospital->getId(), array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $hospitals), true)) {
            throw new \RuntimeException(sprintf('The demo hospital of "foo" is not among the active hospitals of profile "%s".', $volume->profile));
        }

        foreach ($this->batchPlanner->plan($volume, $hospitals, $periodStart, $periodEnd) as $batch) {
            $this->generateBatch($volume, $batch);
        }

        if ($volume->rebuildProjection) {
            $this->rebuildProjection();
        }
    }

    /**
     * From the first day of the month `months` before the last day's month until the end of the last
     * day. The last day is `until`, or yesterday when no date is configured.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function period(FixtureVolume $volume): array
    {
        $lastDay = $volume->until ?? $this->clock->now()->setTime(0, 0)->modify('-1 day');
        $lastDay = \DateTimeImmutable::createFromInterface($lastDay)->setTime(0, 0);
        $start = $lastDay->modify('first day of this month')->modify(sprintf('-%d months', $volume->months));

        return [$start, $lastDay->setTime(23, 59, 59)];
    }

    private function generateBatch(FixtureVolume $volume, ImportBatch $batch): void
    {
        $hospital = $this->entityManager->find(Hospital::class, (int) $batch->hospital->getId());
        $owner = $hospital?->getOwner();
        $dispatchArea = $hospital?->getDispatchArea();
        $state = $hospital?->getState();
        if (!$hospital instanceof Hospital || !$owner instanceof User || !$dispatchArea instanceof DispatchArea || !$state instanceof State) {
            throw new \RuntimeException(sprintf('Hospital "%s" needs an owner, a dispatch area and a state for synthetic allocations.', (string) $batch->hospital->getName()));
        }

        $import = $this->createImport($batch->importName, $hospital, $owner, $batch->allocationCount());
        $pattern = $this->patterns->forHospital($hospital);
        $fixed = [
            'hospital_id' => (int) $hospital->getId(),
            'dispatch_area_id' => (int) $dispatchArea->getId(),
            'state_id' => (int) $state->getId(),
            'import_id' => (int) $import->getId(),
        ];

        $hospitalKey = $hospital->getPublicIdString();
        $random = $this->streams->stream($volume->seed, $hospitalKey, $batch->month, RandomStreams::ALLOCATIONS);
        $rows = [];
        foreach ($batch->dailyCounts as $day => $count) {
            $dayStart = new \DateTimeImmutable($day.' 00:00:00');
            $dayEnd = new \DateTimeImmutable($day.' 23:59:59');
            for ($i = 0; $i < $count; ++$i) {
                $rows[] = $this->row($this->patternSampler->sample($pattern, $dayStart, $dayEnd, $random), $fixed);
                if (\count($rows) >= self::INSERT_BATCH_SIZE) {
                    $this->insert($rows);
                    $rows = [];
                }
            }
        }
        $this->insert($rows);

        $this->generateMciCases($volume, $batch, $import, $hospital, $dispatchArea, $state);
        $this->entityManager->clear();
    }

    /**
     * @param array<string, int> $fixed
     *
     * @return list<bool|int|string|null>
     */
    private function row(SampledAllocationAttributes $sampled, array $fixed): array
    {
        $values = [
            'created_at' => $sampled->createdAt->format('Y-m-d H:i:s'),
            'arrival_at' => $sampled->arrivalAt->format('Y-m-d H:i:s'),
            'gender' => $sampled->gender->value,
            'age' => $sampled->age,
            'urgency' => $sampled->urgency->value,
            'transport_type' => $sampled->transportType?->value,
            'requires_resus' => $sampled->requiresResus,
            'requires_cathlab' => $sampled->requiresCathlab,
            'is_cpr' => $sampled->isCpr,
            'is_ventilated' => $sampled->isVentilated,
            'is_shock' => $sampled->isShock,
            'is_pregnant' => $sampled->isPregnant,
            'is_with_physician' => $sampled->isWithPhysician,
            'is_work_accident' => $sampled->isWorkAccident,
            'department_was_closed' => $sampled->departmentWasClosed,
            'speciality_id' => $sampled->speciality->getId(),
            'department_id' => $sampled->department->getId(),
            'assignment_id' => $sampled->assignment->getId(),
            'occasion_id' => $sampled->occasion->getId(),
            'infection_id' => $sampled->infection?->getId(),
            'secondary_transport_id' => $sampled->secondaryTransport?->getId(),
            'indication_raw_id' => $sampled->indicationRaw->getId(),
            'indication_normalized_id' => $sampled->indicationNormalized?->getId(),
        ] + $fixed;

        return array_map(static fn (string $column): bool|int|string|null => $values[$column], self::COLUMNS);
    }

    /**
     * @param list<list<bool|int|string|null>> $rows
     */
    private function insert(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $placeholders = '(nextval(\'allocation_id_seq\'), gen_random_uuid()::text, '.implode(', ', array_fill(0, \count(self::COLUMNS), '?')).')';
        $parameters = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $parameters[] = \is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO allocation (id, public_id, %s) VALUES %s',
                implode(', ', self::COLUMNS),
                implode(', ', array_fill(0, \count($rows), $placeholders)),
            ),
            $parameters,
        );
    }

    /**
     * MCI cases of one hospital and month, inside the days of the batch and from their own stream.
     *
     * The number of cases is Poisson distributed around the yearly rate, scaled to the days of the
     * batch, so a partial month gets proportionally fewer cases.
     */
    private function generateMciCases(FixtureVolume $volume, ImportBatch $batch, Import $import, Hospital $hospital, DispatchArea $dispatchArea, State $state): void
    {
        $days = array_keys($batch->dailyCounts);
        if ([] === $days || $volume->mciCasesPerHospitalAndYear <= 0.0) {
            return;
        }

        $hospitalKey = $hospital->getPublicIdString();
        $random = $this->streams->stream($volume->seed, $hospitalKey, $batch->month, RandomStreams::MCI);
        $count = $this->poisson($volume->mciCasesPerHospitalAndYear * (float) \count($days) / 365.0, $random);

        for ($i = 1; $i <= $count; ++$i) {
            $day = (string) $days[$random->getInt(0, \count($days) - 1)];
            $createdAt = new \DateTimeImmutable($day.' 00:00:00')->setTime($random->getInt(0, 23), $random->getInt(0, 59));
            $this->entityManager->persist(
                new MciCase()
                    ->setHospital($hospital)
                    ->setDispatchArea($dispatchArea)
                    ->setState($state)
                    ->setImport($import)
                    ->setCreatedAt($createdAt)
                    ->setArrivalAt($createdAt->modify(sprintf('+%d minutes', $random->getInt(10, 45))))
                    ->setMciId(sprintf('mci-%d-%s-%s-%d', $volume->seed, substr($hospitalKey, 0, 8), $batch->month, $i))
                    ->setMciTitle('Synthetic MCI case'),
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Knuth's method; fine for the small rates of MCI cases.
     */
    private function poisson(float $lambda, Randomizer $random): int
    {
        if ($lambda <= 0.0) {
            return 0;
        }

        $limit = exp(-$lambda);
        $count = 0;
        $product = $random->nextFloat();
        while ($product > $limit) {
            ++$count;
            $product *= $random->nextFloat();
        }

        return $count;
    }

    private function requireDemoHospitalOwnedBy(string $username): Hospital
    {
        $hospital = $this->hospitals->findHospitalOwnedByUsername($username);
        if (!$hospital instanceof Hospital) {
            throw new \RuntimeException(sprintf('Demo user "%s" must own a participating hospital.', $username));
        }
        if (!$hospital->isParticipating()) {
            throw new \RuntimeException(sprintf('Demo hospital for user "%s" must be participating.', $username));
        }

        return $hospital;
    }

    private function createImport(string $name, Hospital $hospital, User $createdBy, int $rowCount): Import
    {
        $import = new Import()
            ->setName($name)
            ->setHospital($hospital)
            ->setStatus(ImportStatus::COMPLETED)
            ->setType(ImportType::ALLOCATION)
            ->setFilePath('fixtures/synthetic.csv')
            ->setFileExtension('.csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(1)
            ->setRowCount($rowCount)
            ->setRowsPassed($rowCount)
            ->setRowsRejected(0)
            ->setRunCount(1)
            ->setRunTime(1)
            ->setCreatedBy($createdBy);

        $this->entityManager->persist($import);
        $this->entityManager->flush();

        return $import;
    }

    private function rebuildProjection(): void
    {
        $this->connection->executeStatement('TRUNCATE allocation_stats_projection RESTART IDENTITY');

        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn('SELECT DISTINCT import_id FROM allocation ORDER BY import_id ASC');

        foreach ($rows as $importId) {
            $this->projectionRebuilder->rebuildForImport((int) $importId);
        }
    }
}
