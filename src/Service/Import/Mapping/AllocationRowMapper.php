<?php

namespace App\Service\Import\Mapping;

use App\Service\Import\Contracts\RowToDtoMapperInterface;
use App\Service\Import\DTO\AllocationRowDTO;

/**
 * Maps a normalized associative CSV row (snake_case keys produced by
 * SplCsvRowReader::rowsAssoc()) nto an AllocationRowDTO. This class performs tolerant,
 * explicit normalization:
 *
 * - String extraction with trimming
 * - Date + time concatenation to "d.m.Y H:i"
 * - Gender normalization: only "M" or "W" are kept; any other value becomes "X"
 * - Boolean-like normalization for several flags (suffix "+/-", yes/no, 1/0, true/false)
 * - Transport type normalization: only "BODEN" or "LUFT" are returned; known ambulance
 *   codes are mapped to "BODEN"; anything unrecognized becomes null
 *
 * Actual validity (required fields, ranges, choices, cross-field rules) is enforced by
 * the DTO's Symfony constraints.
 */
final class AllocationRowMapper implements RowToDtoMapperInterface
{
    use AllocationRowNormalizationTrait;

    /**
     * @param array<string,string> $row
     */
    #[\Override]
    public function mapAssoc(array $row): AllocationRowDTO
    {
        $dto = new AllocationRowDTO();

        // Direct string fields
        $dto->dispatchArea = self::getStringOrNull($row, 'versorgungsbereich');
        $dto->hospital = self::getStringOrNull($row, 'krankenhaus_kurzname');

        // Date fields
        $date_created = self::getStringOrNull($row, 'datum_erstellungsdatum');
        $time_created = self::getStringOrNull($row, 'uhrzeit_erstellungsdatum');
        $dto->createdAt = self::combineDateAndTime($date_created, $time_created);

        $date_arrival = self::getStringOrNull($row, 'datum_eintreffzeit');
        $time_arrival = self::getStringOrNull($row, 'uhrzeit_eintreffzeit');
        $dto->arrivalAt = self::combineDateAndTime($date_arrival, $time_arrival);

        // Normalized fields
        $dto->gender = self::normalizeGender(self::getStringOrNull($row, 'geschlecht'));
        $dto->age = self::normalizeAge(self::getStringOrNull($row, 'alter'));
        $dto->requiresResus = self::normalizeBoolean(self::getStringOrNull($row, 'schockraum') ?? 'false');
        $dto->requiresCathlab = self::normalizeBoolean(self::getStringOrNull($row, 'herzkatheter') ?? 'false');
        $dto->isCPR = self::normalizeBoolean(self::getStringOrNull($row, 'reanimation') ?? 'false');
        $dto->isVentilated = self::normalizeBoolean(self::getStringOrNull($row, 'beatmet') ?? 'false');
        $dto->isShock = self::normalizeBoolean(self::getStringOrNull($row, 'schock') ?? 'false');
        $dto->isPregnant = self::normalizeBoolean(self::getStringOrNull($row, 'schwanger') ?? 'false');
        $dto->isWithPhysician = self::normalizeBoolean(self::getStringOrNull($row, 'arztbegleitet') ?? 'false');
        $dto->transportType = self::normalizeTransportType(self::getStringOrNull($row, 'transportmittel'));
        $dto->urgency = self::normalizeUrgencyFromPZC(self::getStringOrNull($row, 'pzc'));

        // Specialities
        $dto->speciality = self::getStringOrNull($row, 'fachgebiet');
        $dto->department = self::getStringOrNull($row, 'fachbereich');
        $dto->departmentWasClosed = self::normalizeBoolean(self::getStringOrNull($row, 'fachbereich_war_abgemeldet') ?? 'false');

        return $dto;
    }
}
