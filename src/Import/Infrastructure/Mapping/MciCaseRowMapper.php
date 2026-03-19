<?php

namespace App\Import\Infrastructure\Mapping;

use App\Import\Application\Contracts\MciCaseRowToDtoMapperInterface;
use App\Import\Application\DTO\MciCaseRowDTO;

/**
 * Maps a normalized associative CSV row (snake_case keys produced by
 * SplCsvRowReader::rowsAssoc()) into an MciCaseRowDTO.
 */
final class MciCaseRowMapper implements MciCaseRowToDtoMapperInterface
{
    use AllocationRowNormalizationTrait;

    /**
     * @param array<string,string> $row
     */
    #[\Override]
    public function mapAssoc(array $row): MciCaseRowDTO
    {
        $dto = new MciCaseRowDTO();

        // Required direct string fields
        $dto->hospital = self::getStringOrNull($row, 'krankenhaus_kurzname');

        // Dispatch area source (single-hospital import may still vary)
        $dispatchSource = $row['zuweisung_durch'] ?? null;
        if (null === $dispatchSource || '' === $dispatchSource) {
            $dispatchSource = $row['versorgungsbereich'] ?? null;
        }
        $dto->dispatchArea = self::normalizeDispatchArea($dispatchSource);

        // Date fields (string format "d.m.Y H:i")
        $dto->createdAt = self::combineDateAndTime(
            self::getStringOrNull($row, 'datum_erstellungsdatum'),
            self::getStringOrNull($row, 'uhrzeit_erstellungsdatum')
        );

        $dto->arrivalAt = self::combineDateAndTime(
            self::getStringOrNull($row, 'datum_eintreffzeit'),
            self::getStringOrNull($row, 'uhrzeit_eintreffzeit')
        );

        // MANV/MCI fields
        // - manv => mciTitle
        // - manv_id => mciId
        $dto->mciTitle = self::getStringOrNull($row, 'manv');
        $dto->mciId = self::getStringOrNull($row, 'manv_id');

        // Optional scalars / enums
        $genderRaw = self::getStringOrNull($row, 'geschlecht');
        $dto->gender = null === $genderRaw ? null : self::normalizeGender($genderRaw);

        $dto->age = self::normalizeAge(self::getStringOrNull($row, 'alter'));

        // Optional boolean flags: no default values -> store NULL if absent/empty
        $dto->requiresResus = self::normalizeBoolean(self::getStringOrNull($row, 'schockraum'));
        $dto->requiresCathlab = self::normalizeBoolean(self::getStringOrNull($row, 'herzkatheter'));
        $dto->isCPR = self::normalizeBoolean(self::getStringOrNull($row, 'reanimation'));
        $dto->isVentilated = self::normalizeBoolean(self::getStringOrNull($row, 'beatmet'));
        $dto->isShock = self::normalizeBoolean(self::getStringOrNull($row, 'schock'));
        $dto->isPregnant = self::normalizeBoolean(self::getStringOrNull($row, 'schwanger'));
        $dto->isWithPhysician = self::normalizeBoolean(self::getStringOrNull($row, 'arztbegleitet'));

        $dto->transportType = self::normalizeTransportType(self::getStringOrNull($row, 'transportmittel'));
        $dto->urgency = self::normalizeUrgencyFromPZC(self::getStringOrNull($row, 'pzc'));

        // Optional relations via name
        $dto->speciality = self::getStringOrNull($row, 'fachgebiet');
        $dto->department = self::getStringOrNull($row, 'fachbereich');
        $dto->departmentWasClosed = self::normalizeBoolean(self::getStringOrNull($row, 'fachbereich_war_abgemeldet'));

        $dto->occasion = self::getStringOrNull($row, 'anlass');
        $dto->infection = self::getStringOrNull($row, 'ansteckungsfaehig');

        // Optional indication handling (derived from PZC + PZC text)
        $dto->indicationCode = self::normalizeCodeFromPZC(self::getStringOrNull($row, 'pzc'));

        $indication = self::normalizeIndication(self::getStringOrNull($row, 'pzc_und_text'));
        $dto->indication = null === $indication || '' === $indication ? null : $indication;

        return $dto;
    }
}
