<?php

declare(strict_types=1);

namespace App\LegacyMigration\Infrastructure\Mapper;

use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Mapping\AllocationRowNormalizationTrait;

final class LegacyAllocationRowMapper
{
    use AllocationRowNormalizationTrait;

    /**
     * @param array<string, mixed> $row
     */
    public function mapAssoc(array $row): AllocationRowDTO
    {
        $dto = new AllocationRowDTO();
        $dto->hospital = self::getStringOrNull($row, 'hospital_name') ?? 'legacy-hospital';
        $dto->dispatchArea = self::normalizeDispatchArea(self::getStringOrNull($row, 'dispatch_area_name'));
        $dto->createdAt = $this->formatDate(self::getStringOrNull($row, 'created_at'));
        $dto->arrivalAt = $this->formatDate(self::getStringOrNull($row, 'arrival_at'));
        $dto->gender = self::normalizeGender(self::getStringOrNull($row, 'gender'));
        $dto->age = self::getIntOrNull($row, 'age');
        $dto->requiresResus = $this->toBool($row['requires_resus'] ?? null);
        $dto->requiresCathlab = $this->toBool($row['requires_cathlab'] ?? null);
        $dto->isCPR = $this->toBool($row['is_cpr'] ?? null);
        $dto->isVentilated = $this->toBool($row['is_ventilated'] ?? null);
        $dto->isShock = $this->toBool($row['is_shock'] ?? null);
        $dto->isPregnant = $this->toBool($row['is_pregnant'] ?? null);
        $dto->isWithPhysician = $this->toBool($row['is_with_physician'] ?? null);
        $dto->transportType = self::normalizeTransportType(self::getStringOrNull($row, 'mode_of_transport'));
        $dto->urgency = self::getIntOrNull($row, 'urgency');
        $dto->speciality = self::getStringOrNull($row, 'speciality');
        $dto->department = self::getStringOrNull($row, 'speciality_detail') ?? self::getStringOrNull($row, 'speciality');
        $dto->departmentWasClosed = $this->toBool($row['speciality_was_closed'] ?? null);
        $dto->assignment = self::getStringOrNull($row, 'assignment');
        $dto->occasion = self::getStringOrNull($row, 'occasion');
        $dto->secondaryTransport = self::getStringOrNull($row, 'secondary_deployment');
        $dto->infection = self::getStringOrNull($row, 'is_infectious');
        $dto->indicationCode = self::getIntOrNull($row, 'indication_code');
        $dto->indication = self::getStringOrNull($row, 'indication');

        return $dto;
    }

    private function formatDate(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $date = new \DateTimeImmutable($value);

        return $date->format('d.m.Y H:i');
    }

    private function toBool(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int) $value === 1;
        }
        if (\is_string($value)) {
            return self::normalizeBoolean($value);
        }

        return null;
    }
}

