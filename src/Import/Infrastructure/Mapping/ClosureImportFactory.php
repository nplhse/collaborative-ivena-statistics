<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Mapping;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\DTO\ClosureRowDTO;
use App\Import\Application\Mapping\ClosureCareLevelCatalog;
use App\Import\Application\Mapping\ClosureFacilityKindCatalog;
use App\Import\Application\Mapping\ClosureHospitalGuard;
use App\Import\Application\Mapping\ClosureHospitalProfile;
use App\Import\Application\Mapping\ClosureIntervalClock;
use App\Import\Application\Mapping\ClosureReasonCatalog;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\ReadOnlyAssociationReferencer;
use App\Import\Infrastructure\Resolver\Strategy\SpecialityDepartmentReferenceStrategy;

/**
 * Assembles a closure interval. Parsing and catalogs stay in their own classes.
 */
final readonly class ClosureImportFactory
{
    public function __construct(
        private ReadOnlyAssociationReferencer $referencer,
        private SpecialityDepartmentReferenceStrategy $references,
        private ClosureHospitalGuard $hospitalGuard,
        private ClosureCareLevelCatalog $careLevels,
        private ClosureReasonCatalog $reasons,
        private ClosureFacilityKindCatalog $facilityKinds,
        private ClosureIntervalClock $clock,
    ) {
    }

    public function warm(): void
    {
        $this->references->warm();
    }

    public function fromDto(ClosureRowDTO $dto, Import $import, ClosureHospitalProfile $profile): ClosureInterval
    {
        $this->hospitalGuard->assertRow($profile, $dto->hospitalShortName);

        $careLevel = $this->careLevels->resolve((string) $dto->careLevelLabel);
        $reason = $this->reasons->resolve((string) $dto->reasonLabel);
        $facilityKind = $this->facilityKinds->resolve((string) $dto->facilityKindLabel);
        $times = $this->clock->resolve(
            (string) $dto->startsOn,
            (string) $dto->startsAtTime,
            (string) $dto->endsOn,
            (string) $dto->endsAtTime,
            (int) $dto->durationMinutes,
            (string) $dto->sourceRecordedAt,
            (string) $dto->sourceChangedAt,
        );
        $pair = $this->references->requirePair((string) $dto->speciality, (string) $dto->department);

        $interval = new ClosureInterval();
        $interval->setHospital($this->refHospital($import, $profile));
        $interval->setImport($this->refImport($import));
        $interval->setSpeciality($pair['speciality']);
        $interval->setDepartment($pair['department']);
        $interval->setStartsAt($times['startsAt']);
        $interval->setEndsAt($times['endsAt']);
        $interval->setCareLevel($careLevel);
        $interval->setReason($reason);
        $interval->setFacilityKind($facilityKind);
        $interval->setClosureUnit($dto->closureUnit);
        $interval->setSourceGroupId($dto->sourceGroupId);
        $interval->setRemark($dto->remark);
        $interval->setInternalRemark($dto->internalRemark);
        $interval->setSourceRecordedAt($times['sourceRecordedAt']);
        $interval->setSourceChangedAt($times['sourceChangedAt']);

        return $interval;
    }

    private function refImport(Import $import): Import
    {
        $importId = $import->getId();
        if (null === $importId) {
            throw new \LogicException('Import has no id assigned');
        }

        return $this->referencer->readOnlyReference(Import::class, $importId);
    }

    private function refHospital(Import $import, ClosureHospitalProfile $profile): Hospital
    {
        $hospitalId = $import->getHospital()?->getId();
        if (null === $hospitalId) {
            throw new \LogicException('Import has no hospital');
        }

        if ($hospitalId !== $profile->selectedHospitalId) {
            throw new \LogicException('Closure hospital profile does not match the import hospital');
        }

        return $this->referencer->readOnlyReference(Hospital::class, $hospitalId);
    }
}
