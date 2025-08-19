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
    public function mapAssoc(array $row): AllocationRowDTO
    {
        $dto = new AllocationRowDTO();

        // Direct string fields
        $dto->dispatchArea = self::getStringOrNull($row, 'versorgungsbereich');
        $dto->state = self::getStringOrNull($row, 'khs_versorgungsgebiet');
        $dto->hospital = self::getStringOrNull($row, 'krankenhaus_kurzname');

        // Date fields
        $datum = self::getStringOrNull($row, 'datum');
        $uhrzeit = self::getStringOrNull($row, 'uhrzeit');
        $combined = self::combineDateAndTime($datum, $uhrzeit);

        $erstellungsdatum = self::getStringOrNull($row, 'erstellungsdatum'); // NEW
        $dto->createdAt = self::chooseCreatedAt($combined, $erstellungsdatum);

        // Normalized fields
        $dto->gender = self::normalizeGender(self::getStringOrNull($row, 'geschlecht'));
        $dto->age = self::normalizeAge(self::getStringOrNull($row, 'alter'));
        $dto->requiresResus = self::normalizeBoolean(self::getStringOrNull($row, 'schockraum'));
        $dto->requiresCathlab = self::normalizeBoolean(self::getStringOrNull($row, 'herzkatheter'));
        $dto->isCPR = self::normalizeBoolean(self::getStringOrNull($row, 'reanimation'));
        $dto->isVentilated = self::normalizeBoolean(self::getStringOrNull($row, 'beatmet'));
        $dto->isShock = self::normalizeBoolean(self::getStringOrNull($row, 'schock'));
        $dto->isPregnant = self::normalizeBoolean(self::getStringOrNull($row, 'schwanger'));
        $dto->isWithPhysician = self::normalizeBoolean(self::getStringOrNull($row, 'arztbegleitet'));
        $dto->transportType = self::normalizeTransportType(self::getStringOrNull($row, 'transportmittel'));

        return $dto;
    }
}
