<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Mapping;

use App\Import\Application\DTO\ClosureRowDTO;

/**
 * Only this class knows IVENA closure-list column names.
 * Header keys are the snake_case names produced by SplCsvRowReader.
 */
final readonly class ClosureRowMapper
{
    /**
     * @param array<string, string> $row
     */
    public function mapAssoc(array $row): ClosureRowDTO
    {
        $dto = new ClosureRowDTO();
        $dto->hospitalShortName = $this->stringOrNull($row, 'krankenhaus_kurzname');
        $dto->speciality = $this->stringOrNull($row, 'fachgebiet');
        $dto->department = $this->stringOrNull($row, 'fachbereich');
        $dto->careLevelLabel = $this->stringOrNull($row, 'behandlungsdringlichkeit');
        $dto->reasonLabel = $this->stringOrNull($row, 'grund');
        $dto->facilityKindLabel = $this->stringOrNull($row, 'typ');
        $dto->startsOn = $this->stringOrNull($row, 'datum_schliessungs_beginn');
        $dto->startsAtTime = $this->stringOrNull($row, 'uhrzeit_schliessungs_beginn');
        $dto->endsOn = $this->stringOrNull($row, 'datum_schliessungs_ende');
        $dto->endsAtTime = $this->stringOrNull($row, 'uhrzeit_schliessungs_ende');
        $dto->durationMinutes = $this->positiveIntOrNull($row, 'schliessungs_dauer_minuten');
        $dto->closureUnit = $this->stringOrNull($row, 'schliessungseinheit');
        $dto->sourceGroupId = $this->stringOrNull($row, 'gruppen_schliessungs_id');
        $dto->remark = $this->stringOrNull($row, 'bemerkung');
        $dto->internalRemark = $this->stringOrNull($row, 'krankenhausinterne_bemerkung');
        $dto->sourceRecordedAt = $this->stringOrNull($row, 'eingetragen_am');
        $dto->sourceChangedAt = $this->stringOrNull($row, 'geaendert_am');

        return $dto;
    }

    /**
     * @param array<string, string> $row
     */
    private function stringOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, string> $row
     */
    private function positiveIntOrNull(array $row, string $key): ?int
    {
        $value = $this->stringOrNull($row, $key);
        if (null === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
