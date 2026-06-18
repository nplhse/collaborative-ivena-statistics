<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\State;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Pattern\Application\PatternSampler;
use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;
use App\DataFixtures\Pattern\Infrastructure\PatternYamlSerializer;
use App\DataFixtures\Reference\ReferenceRegistry;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SyntheticAllocationGenerator
{
    private const int FLUSH_BATCH_SIZE = 250;

    public function __construct(
        private ReferenceRegistry $registry,
        private FixtureVolumeResolver $volumeResolver,
        private ImportBatchPlanner $batchPlanner,
        private PatternYamlSerializer $patternSerializer,
        private PatternSampler $patternSampler,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private AllocationStatsProjectionRebuildInterface $projectionRebuilder,
    ) {
    }

    public function generate(): void
    {
        $volume = $this->volumeResolver->resolve();
        $pattern = $this->patternSerializer->load($volume->pattern);

        $segmentHospitals = $this->registry->hospitalsMatching(
            $pattern->segment->hospitalTier,
            $pattern->segment->hospitalLocation,
        );
        $fooHospital = $this->requireDemoHospitalOwnedBy('foo');
        $activeHospitals = $this->registry->selectActiveHospitals($volume->hospitalsActive, [$fooHospital]);
        $hospitals = $this->intersectHospitals($activeHospitals, $segmentHospitals);
        if ([] === $hospitals) {
            $hospitals = array_values(array_filter(
                $activeHospitals,
                static fn (Hospital $hospital): bool => $hospital->isParticipating(),
            ));
        }

        $hospitals = $this->ensureHospitalIncluded($hospitals, $fooHospital);

        if ([] === $hospitals) {
            throw new \RuntimeException('No participating hospitals available for synthetic allocations.');
        }

        $hospitalIds = array_map(
            static fn (Hospital $hospital): int => (int) $hospital->getId(),
            $hospitals,
        );
        $batches = $this->batchPlanner->plan($volume, $hospitals);

        $periodStart = new \DateTimeImmutable($volume->period);
        $periodEnd = new \DateTimeImmutable('now');

        foreach ($batches as $batch) {
            $hospitalId = (int) $batch->hospital->getId();
            $dispatchArea = $batch->hospital->getDispatchArea();
            $state = $batch->hospital->getState();
            if (!$dispatchArea instanceof DispatchArea || !$state instanceof State) {
                throw new \RuntimeException(sprintf('Hospital "%s" is missing dispatch area or state for synthetic allocations.', (string) $batch->hospital->getName()));
            }

            $owner = $batch->hospital->getOwner();
            if (!$owner instanceof User) {
                throw new \RuntimeException(sprintf('Hospital "%s" has no owner for synthetic import.', (string) $batch->hospital->getName()));
            }

            $ownerId = (int) $owner->getId();
            $managedHospital = $this->entityManager->find(Hospital::class, $hospitalId);
            $managedOwner = $this->entityManager->find(User::class, $ownerId);
            if (!$managedHospital instanceof Hospital || !$managedOwner instanceof User) {
                throw new \RuntimeException(sprintf('Hospital or owner not found for synthetic import (hospital #%d, owner #%d).', $hospitalId, $ownerId));
            }

            $import = $this->createImport(
                $batch->importName,
                $managedHospital,
                $managedOwner,
                $batch->allocationCount,
            );
            $importId = (int) $import->getId();

            $hospitalRef = $this->requireReference(Hospital::class, $hospitalId);
            $dispatchAreaRef = $this->requireReference(DispatchArea::class, (int) $dispatchArea->getId());
            $stateRef = $this->requireReference(State::class, (int) $state->getId());
            $importRef = $this->requireReference(Import::class, $importId);

            for ($i = 0; $i < $batch->allocationCount; ++$i) {
                $sampled = $this->patternSampler->sample($pattern, $periodStart, $periodEnd);
                $this->entityManager->persist(
                    $this->buildAllocation($hospitalRef, $dispatchAreaRef, $stateRef, $importRef, $sampled),
                );

                if (0 === ($i + 1) % self::FLUSH_BATCH_SIZE) {
                    $this->entityManager->flush();
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        foreach (range(1, $volume->mciCases) as $_) {
            $hospitalId = $hospitalIds[array_rand($hospitalIds)];
            $hospital = $this->entityManager->find(Hospital::class, $hospitalId);
            if (!$hospital instanceof Hospital) {
                throw new \RuntimeException(sprintf('Hospital #%d not found for MCI case fixture.', $hospitalId));
            }

            $dispatchArea = $hospital->getDispatchArea();
            $state = $hospital->getState();
            if (!$dispatchArea instanceof DispatchArea || !$state instanceof State) {
                throw new \RuntimeException(sprintf('Hospital #%d is missing dispatch area or state for MCI case fixture.', $hospitalId));
            }

            $dispatchAreaId = (int) $dispatchArea->getId();
            $stateId = (int) $state->getId();
            $managedDispatchArea = $this->entityManager->find(DispatchArea::class, $dispatchAreaId);
            $managedState = $this->entityManager->find(State::class, $stateId);
            if (!$managedDispatchArea instanceof DispatchArea || !$managedState instanceof State) {
                throw new \RuntimeException(sprintf('Hospital #%d is missing dispatch area or state for MCI case fixture.', $hospitalId));
            }

            $import = $this->entityManager->createQueryBuilder()
                ->select('i')
                ->from(Import::class, 'i')
                ->where('i.hospital = :hospital')
                ->setParameter('hospital', $hospital, Hospital::class)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$import instanceof Import) {
                throw new \RuntimeException(sprintf('Hospital #%d has no import for MCI case fixture.', $hospitalId));
            }

            $managedImport = $this->entityManager->find(Import::class, (int) $import->getId());
            if (!$managedImport instanceof Import) {
                throw new \RuntimeException(sprintf('Import for hospital #%d not found for MCI case fixture.', $hospitalId));
            }

            $this->entityManager->persist(
                new MciCase()
                    ->setHospital($hospital)
                    ->setDispatchArea($managedDispatchArea)
                    ->setState($managedState)
                    ->setImport($managedImport)
                    ->setCreatedAt(new \DateTimeImmutable('-30 days'))
                    ->setArrivalAt(new \DateTimeImmutable('-29 days'))
                    ->setMciId(uniqid('mci-', true))
                    ->setMciTitle('Synthetic MCI case'),
            );
        }

        $this->entityManager->flush();

        if ($volume->rebuildProjection) {
            $this->rebuildProjection();
        }
    }

    private function buildAllocation(
        Hospital $hospital,
        DispatchArea $dispatchArea,
        State $state,
        Import $import,
        SampledAllocationAttributes $sampled,
    ): Allocation {
        return new Allocation()
            ->setHospital($hospital)
            ->setDispatchArea($dispatchArea)
            ->setState($state)
            ->setImport($import)
            ->setCreatedAt($sampled->createdAt)
            ->setArrivalAt($sampled->arrivalAt)
            ->setGender($sampled->gender)
            ->setAge($sampled->age)
            ->setIsCPR($sampled->isCpr)
            ->setIsPregnant($sampled->isPregnant)
            ->setIsShock($sampled->isShock)
            ->setIsVentilated($sampled->isVentilated)
            ->setIsWithPhysician($sampled->isWithPhysician)
            ->setIsWorkAccident($sampled->isWorkAccident)
            ->setRequiresCathlab($sampled->requiresCathlab)
            ->setRequiresResus($sampled->requiresResus)
            ->setTransportType($sampled->transportType)
            ->setUrgency($sampled->urgency)
            ->setSpeciality($sampled->speciality)
            ->setDepartment($sampled->department)
            ->setDepartmentWasClosed($sampled->departmentWasClosed)
            ->setAssignment($sampled->assignment)
            ->setOccasion($sampled->occasion)
            ->setInfection($sampled->infection)
            ->setSecondaryTransport($sampled->secondaryTransport)
            ->setIndicationRaw($sampled->indicationRaw)
            ->setIndicationNormalized($sampled->indicationNormalized);
    }

    /**
     * @return list<Hospital>
     */
    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<Hospital>
     */
    private function ensureHospitalIncluded(array $hospitals, Hospital $required): array
    {
        foreach ($hospitals as $hospital) {
            if ((int) $hospital->getId() === (int) $required->getId()) {
                return $hospitals;
            }
        }

        array_unshift($hospitals, $required);

        return $hospitals;
    }

    private function requireDemoHospitalOwnedBy(string $username): Hospital
    {
        $hospital = $this->registry->findHospitalOwnedByUsername($username);
        if (!$hospital instanceof Hospital) {
            throw new \RuntimeException(sprintf('Demo user "%s" must own a participating hospital.', $username));
        }
        if (!$hospital->isParticipating()) {
            throw new \RuntimeException(sprintf('Demo hospital for user "%s" must be participating.', $username));
        }

        return $hospital;
    }

    /**
     * @param list<Hospital> $activeHospitals
     * @param list<Hospital> $segmentHospitals
     *
     * @return list<Hospital>
     */
    private function intersectHospitals(array $activeHospitals, array $segmentHospitals): array
    {
        $segmentIds = array_flip(array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $segmentHospitals));

        return array_values(array_filter(
            $activeHospitals,
            static fn (Hospital $hospital): bool => isset($segmentIds[(int) $hospital->getId()]),
        ));
    }

    private function createImport(
        string $name,
        Hospital $hospital,
        User $createdBy,
        int $rowCount,
    ): Import {
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
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT import_id FROM allocation ORDER BY import_id ASC',
        );

        foreach ($rows as $importId) {
            $this->projectionRebuilder->rebuildForImport((int) $importId);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function requireReference(string $class, int $id): object
    {
        $reference = $this->entityManager->getReference($class, $id);
        if (!$reference instanceof $class) {
            throw new \RuntimeException(sprintf('Missing entity reference %s#%d.', $class, $id));
        }

        return $reference;
    }
}
