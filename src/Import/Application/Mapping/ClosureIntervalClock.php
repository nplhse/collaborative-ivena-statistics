<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

use App\Allocation\Domain\Service\ClosureIntervalDuration;
use App\Import\Application\Exception\ImportException;

/**
 * Parses closure start/end and source timestamps in Europe/Berlin and checks
 * the declared duration against the elapsed minutes, including the DST shift.
 */
final class ClosureIntervalClock
{
    /**
     * @return array{
     *     startsAt: \DateTimeImmutable,
     *     endsAt: \DateTimeImmutable,
     *     sourceRecordedAt: \DateTimeImmutable,
     *     sourceChangedAt: \DateTimeImmutable
     * }
     */
    public function resolve(
        string $startsOn,
        string $startsAtTime,
        string $endsOn,
        string $endsAtTime,
        int $durationMinutes,
        string $sourceRecordedAt,
        string $sourceChangedAt,
    ): array {
        $startsAt = $this->parseDateAndTime('startsAt', $startsOn, $startsAtTime);
        $endsAt = $this->parseDateAndTime('endsAt', $endsOn, $endsAtTime);
        $elapsed = ClosureIntervalDuration::minutesBetween($startsAt, $endsAt);

        if ($elapsed <= 0) {
            throw new ImportException(message: 'Closure end must be after start', field: 'endsAt', value: $endsOn.' '.$endsAtTime, codeStr: 'INTERVAL_ORDER');
        }

        if ($elapsed !== $durationMinutes) {
            throw new ImportException(message: sprintf('Closure duration %d does not match elapsed minutes %d', $durationMinutes, $elapsed), field: 'durationMinutes', value: (string) $durationMinutes, codeStr: 'DURATION_MISMATCH');
        }

        return [
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'sourceRecordedAt' => $this->parseTimestamp('sourceRecordedAt', $sourceRecordedAt),
            'sourceChangedAt' => $this->parseTimestamp('sourceChangedAt', $sourceChangedAt),
        ];
    }

    private function parseDateAndTime(string $field, string $date, string $time): \DateTimeImmutable
    {
        return $this->parse($field, $date.' '.$time, '!d.m.Y H:i:s');
    }

    private function parseTimestamp(string $field, string $value): \DateTimeImmutable
    {
        return $this->parse($field, $value, '!d.m.Y H:i:s');
    }

    private function parse(string $field, string $value, string $format): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone(ClosureIntervalDuration::TIMEZONE);
        $parsed = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

        if (!$parsed instanceof \DateTimeImmutable || $hasErrors) {
            throw new ImportException(message: 'Invalid closure date/time', field: $field, value: $value, codeStr: 'INVALID_DATE');
        }

        return $parsed;
    }
}
