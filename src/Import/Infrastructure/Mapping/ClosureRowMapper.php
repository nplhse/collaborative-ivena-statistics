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
        $dto->startsOn = $this->normalizeDate($this->stringOrNull($row, 'datum_schliessungs_beginn'));
        $dto->startsAtTime = $this->normalizeTime($this->stringOrNull($row, 'uhrzeit_schliessungs_beginn'));
        $dto->endsOn = $this->normalizeDate($this->stringOrNull($row, 'datum_schliessungs_ende'));
        $dto->endsAtTime = $this->normalizeTime($this->stringOrNull($row, 'uhrzeit_schliessungs_ende'));
        $dto->durationMinutes = $this->positiveIntOrNull($row, 'schliessungs_dauer_minuten');
        $dto->closureUnit = $this->stringOrNull($row, 'schliessungseinheit');
        $dto->sourceGroupId = $this->stringOrNull($row, 'gruppen_schliessungs_id');
        $dto->remark = $this->stringOrNull($row, 'bemerkung');
        $dto->internalRemark = $this->stringOrNull($row, 'krankenhausinterne_bemerkung');
        $dto->sourceRecordedAt = $this->normalizeTimestamp($this->stringOrNull($row, 'eingetragen_am'));
        $dto->sourceChangedAt = $this->normalizeTimestamp($this->stringOrNull($row, 'geaendert_am'));

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
     * German day.month.year. A two-digit year uses the same window as PHP: 00–69 are 2000–2069, 70–99 are 1970–1999.
     * Missing leading zeros are filled. Values that are not a real calendar day stay unchanged for the validator.
     */
    private function normalizeDate(?string $value): ?string
    {
        if (null === $value || 1 !== preg_match('/^(?<d>\d{1,2})\.(?<m>\d{1,2})\.(?<y>\d{4}|\d{2})$/', $value, $matches)) {
            return $value;
        }

        $year = $this->expandYear($matches['y']);
        $month = (int) $matches['m'];
        $day = (int) $matches['d'];
        if (!checkdate($month, $day, $year)) {
            return $value;
        }

        return sprintf('%02d.%02d.%04d', $day, $month, $year);
    }

    /**
     * Clock time. Missing seconds become 00. One-digit hours, minutes, and seconds are padded.
     */
    private function normalizeTime(?string $value): ?string
    {
        if (null === $value || 1 !== preg_match('/^(?<h>\d{1,2}):(?<i>\d{1,2})(?::(?<s>\d{1,2}))?$/', $value, $matches)) {
            return $value;
        }

        $hour = (int) $matches['h'];
        $minute = (int) $matches['i'];
        $second = isset($matches['s']) ? (int) $matches['s'] : 0;
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return $value;
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    private function normalizeTimestamp(?string $value): ?string
    {
        if (null === $value || 1 !== preg_match('/^(?<date>\d{1,2}\.\d{1,2}\.(?:\d{4}|\d{2})) (?<time>\d{1,2}:\d{1,2}(?::\d{1,2})?)$/', $value, $matches)) {
            return $value;
        }

        $date = $this->normalizeDate($matches['date']);
        $time = $this->normalizeTime($matches['time']);
        if (!\is_string($date) || 1 !== preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date) || !\is_string($time) || 1 !== preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $value;
        }

        return $date.' '.$time;
    }

    private function expandYear(string $year): int
    {
        if (4 === strlen($year)) {
            return (int) $year;
        }

        $short = (int) $year;

        return $short <= 69 ? 2000 + $short : 1900 + $short;
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
