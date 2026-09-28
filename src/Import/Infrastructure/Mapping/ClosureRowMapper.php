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
        $dto->hospitalShortName = self::stringOrNull($row, 'krankenhaus_kurzname');
        $dto->speciality = self::stringOrNull($row, 'fachgebiet');
        $dto->department = self::stringOrNull($row, 'fachbereich');
        $dto->careLevelLabel = self::stringOrNull($row, 'behandlungsdringlichkeit');
        $dto->reasonLabel = self::stringOrNull($row, 'grund');
        $dto->facilityKindLabel = self::stringOrNull($row, 'typ');
        $dto->startsOn = self::stringOrNull($row, 'datum_schliessungs_beginn');
        $dto->startsAtTime = self::stringOrNull($row, 'uhrzeit_schliessungs_beginn');
        $dto->endsOn = self::stringOrNull($row, 'datum_schliessungs_ende');
        $dto->endsAtTime = self::stringOrNull($row, 'uhrzeit_schliessungs_ende');
        $dto->durationMinutes = $this->positiveIntOrNull($row, 'schliessungs_dauer_minuten');
        $dto->closureUnit = self::stringOrNull($row, 'schliessungseinheit');
        $dto->sourceGroupId = self::stringOrNull($row, 'gruppen_schliessungs_id');
        $dto->remark = self::stringOrNull($row, 'bemerkung');
        $dto->internalRemark = self::stringOrNull($row, 'krankenhausinterne_bemerkung');
        $dto->sourceRecordedAt = self::stringOrNull($row, 'eingetragen_am');
        $dto->sourceChangedAt = self::stringOrNull($row, 'geaendert_am');

        return $dto;
    }

    /**
     * @param array<string, string> $row
     */
    private static function stringOrNull(array $row, string $key): ?string
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
        $value = self::stringOrNull($row, $key);
        if (null === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
