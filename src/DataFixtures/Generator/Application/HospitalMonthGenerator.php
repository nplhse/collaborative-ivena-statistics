<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\State;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Infrastructure\Dbal\AllocationRowWriter;
use App\DataFixtures\Generator\Infrastructure\Dbal\ClosureIntervalWriter;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * Generates one hospital and one month: an allocation import with its allocations and MCI cases, and a
 * closure import with its intervals. Closures are planned first, so an allocation to a closed department
 * during an interval gets `department_was_closed`.
 *
 * The month draws from its own random streams (ADR 022), so it gets the same rows whether it is
 * generated alone, later, or inside a full fixture load. Export period and data coverage are set here;
 * projections are rebuilt by the caller through SyntheticDataPipeline.
 */
final readonly class HospitalMonthGenerator
{
    public function __construct(
        private ImportBatchPlanner $planner,
        private HospitalMonthSampler $sampler,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private RandomStreams $streams,
        private ClosureIntervalWriter $closureWriter,
        private SyntheticDataPipeline $pipeline,
    ) {
    }

    /**
     * The daily counts the month would get, without writing anything.
     *
     * @param string $month month as Y-m
     *
     * @throws \DomainException when the month cannot be generated for this hospital
     */
    public function plan(FixtureVolume $volume, Hospital $hospital, string $month, \DateTimeImmutable $lastDay): ImportBatch
    {
        $first = ImportBatchPlanner::firstDay($month);
        if ($first > $lastDay) {
            throw new \DomainException(sprintf('%s lies after the last day of the period (%s).', $month, $lastDay->format('Y-m-d')));
        }
        if ($this->hasImportFor($hospital, $month, $first)) {
            throw new \DomainException(sprintf('%s already has allocations, closures or a synthetic import for %s.', (string) $hospital->getName(), $month));
        }

        return $this->planner->planMonth($volume, $hospital, $month, $lastDay);
    }

    /**
     * @param string $month month as Y-m
     *
     * @throws \DomainException when the month cannot be generated for this hospital
     */
    public function generate(FixtureVolume $volume, Hospital $hospital, string $month, \DateTimeImmutable $lastDay): GeneratedMonth
    {
        $batch = $this->plan($volume, $hospital, $month, $lastDay);

        $owner = $hospital->getOwner();
        $dispatchArea = $hospital->getDispatchArea();
        $state = $hospital->getState();
        if (!$owner instanceof User || !$dispatchArea instanceof DispatchArea || !$state instanceof State) {
            throw new \DomainException(sprintf('%s needs an owner, a dispatch area and a state for synthetic allocations.', (string) $hospital->getName()));
        }

        $firstDay = array_key_first($batch->dailyCounts);
        $lastGeneratedDay = array_key_last($batch->dailyCounts);
        if (null === $firstDay || null === $lastGeneratedDay) {
            throw new \LogicException('A planned month has at least one day.');
        }

        $hospitalKey = $hospital->getPublicIdString();
        $uploadedAt = UploadTime::after($lastGeneratedDay, $lastDay, $this->streams->stream($volume->seed, $hospitalKey, $month, RandomStreams::UPLOAD));
        $closures = $this->sampler->closures($volume, $hospital, $batch, $lastDay);
        $closureImport = $this->createImport(ImportType::CLOSURE, sprintf('IVENA closures %s', $month), $hospital, \count($closures), SyntheticImportMarker::closures($volume->seed, $hospitalKey, $month), $owner, $uploadedAt);
        $this->closureWriter->write($closures, (int) $hospital->getId(), (int) $closureImport->getId());

        $import = $this->createImport(ImportType::ALLOCATION, $batch->importName, $batch->hospital, $batch->allocationCount(), SyntheticImportMarker::allocations($volume->seed, $hospitalKey, $month), $owner, $uploadedAt);
        $fixed = [
            'hospital_id' => (int) $hospital->getId(),
            'dispatch_area_id' => (int) $dispatchArea->getId(),
            'state_id' => (int) $state->getId(),
            'import_id' => (int) $import->getId(),
        ];

        $writer = new AllocationRowWriter($this->connection);
        foreach ($this->sampler->allocations($volume, $hospital, $batch, $closures) as $allocation) {
            $writer->add($allocation->attributes, $fixed, $allocation->departmentWasClosed);
        }
        $writer->flush();

        $mciCases = $this->generateMciCases($volume, $batch, $import, $hospital, $dispatchArea, $state);

        // Closure periods depend on the allocations of the hospital, so both imports are processed now.
        $windowStart = new \DateTimeImmutable($firstDay.' 00:00:00');
        $windowEnd = new \DateTimeImmutable($lastGeneratedDay.' 23:59:59');
        $this->pipeline->processImport($import, $windowStart, $windowEnd);
        $this->pipeline->processImport($closureImport, $windowStart, $windowEnd);
        $this->entityManager->flush();

        return new GeneratedMonth((int) $import->getId(), $batch->allocationCount(), $mciCases, (int) $closureImport->getId(), \count($closures));
    }

    /**
     * A synthetic import of any seed and type for the month, or allocations or closures of any import inside it.
     */
    private function hasImportFor(Hospital $hospital, string $month, \DateTimeImmutable $first): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM import i
             WHERE i.hospital_id = :hospital
               AND (i.file_path LIKE :allocations
                    OR i.file_path LIKE :closures
                    OR EXISTS (SELECT 1 FROM allocation a WHERE a.import_id = i.id AND a.created_at >= :from AND a.created_at < :to)
                    OR EXISTS (SELECT 1 FROM closure_interval c WHERE c.import_id = i.id AND c.starts_at >= :from AND c.starts_at < :to))
             LIMIT 1',
            [
                'hospital' => (int) $hospital->getId(),
                'allocations' => SyntheticImportMarker::likeAnySeed(SyntheticImportMarker::ALLOCATIONS, $hospital->getPublicIdString(), $month),
                'closures' => SyntheticImportMarker::likeAnySeed(SyntheticImportMarker::CLOSURES, $hospital->getPublicIdString(), $month),
                'from' => $first->format('Y-m-d H:i:s'),
                'to' => $first->modify('+1 month')->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * MCI cases inside the days of the month, from their own stream.
     *
     * The number of cases is Poisson distributed around the yearly rate, scaled to the days of the
     * month, so a partial month gets proportionally fewer cases.
     */
    private function generateMciCases(FixtureVolume $volume, ImportBatch $batch, Import $import, Hospital $hospital, DispatchArea $dispatchArea, State $state): int
    {
        $days = array_keys($batch->dailyCounts);
        if ([] === $days || $volume->mciCasesPerHospitalAndYear <= 0.0) {
            return 0;
        }

        $hospitalKey = $hospital->getPublicIdString();
        $random = $this->streams->stream($volume->seed, $hospitalKey, $batch->month, RandomStreams::MCI);
        $count = $this->poisson($volume->mciCasesPerHospitalAndYear * (float) \count($days) / 365.0, $random);

        for ($i = 1; $i <= $count; ++$i) {
            $day = $days[$random->getInt(0, \count($days) - 1)];
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

        return $count;
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

    private function createImport(ImportType $type, string $name, Hospital $hospital, int $rowCount, SyntheticImportMarker $marker, User $createdBy, \DateTimeImmutable $uploadedAt): Import
    {
        $import = new Import()
            ->setName($name)
            ->setCreatedAt($uploadedAt)
            ->setHospital($hospital)
            ->setStatus(ImportStatus::COMPLETED)
            ->setType($type)
            ->setFilePath($marker->path())
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
}
