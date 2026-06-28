<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Application\Export\DTO\OwnHospitalAllocationsExportFilter;
use App\Allocation\UI\Form\Model\OwnHospitalAllocationsExportFormData;

final class OwnHospitalAllocationsExportFilterMapper
{
    public function fromFormData(OwnHospitalAllocationsExportFormData $data): OwnHospitalAllocationsExportFilter
    {
        if (!$data->dateFrom instanceof \DateTimeInterface || !$data->dateTo instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException('Export requires dateFrom and dateTo.');
        }

        return new OwnHospitalAllocationsExportFilter(
            dateFrom: $data->dateFrom,
            dateTo: $data->dateTo,
            timeFrom: $data->timeFrom,
            timeTo: $data->timeTo,
            hospitalIds: $this->normalizeHospitalIds($data->hospitals),
            urgency: $this->emptyToNull($data->urgency),
            requiresResus: $data->requiresResus ? 1 : null,
            requiresCathlab: $data->requiresCathlab ? 1 : null,
            indication: $data->indication,
            secondaryTransport: $data->secondaryTransport,
            isVentilated: $data->isVentilated ? 1 : null,
            isShock: $data->isShock ? 1 : null,
            isCPR: $data->isCPR ? 1 : null,
            isPregnant: $data->isPregnant ? 1 : null,
            isWorkAccident: $data->isWorkAccident ? 1 : null,
            isInfectious: $data->isInfectious ? 1 : null,
            infection: $data->infection,
            department: $data->department,
            speciality: $data->speciality,
            assignment: $data->assignment,
            occasion: $data->occasion,
            departmentWasClosed: $data->departmentWasClosed ? 1 : null,
            transportType: $this->emptyToNull($data->transportType),
            includeIndicationRaw: $data->includeIndicationRaw,
        );
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<int>|null
     */
    private function normalizeHospitalIds(array $hospitalIds): ?array
    {
        if ([] === $hospitalIds) {
            return null;
        }

        return array_values(array_unique(array_map(intval(...), $hospitalIds)));
    }

    private function emptyToNull(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }
}
