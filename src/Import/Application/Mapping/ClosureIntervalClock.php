<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

use App\Allocation\Domain\Service\ClosureIntervalDuration;
use App\Import\Application\Exception\ImportException;

/**
 * Parses closure start/end and source timestamps in Europe/Berlin and checks
 * the declared duration.
 *
 * IVENA reports the duration as the difference of the local clock times, so a closure
 * across the spring-forward night is one hour longer than the elapsed time (and one hour
 * shorter in autumn). Both values are accepted; the stored interval keeps start and end,
 * and its duration is the elapsed time.
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
            $wallClock = $this->wallClockMinutes($startsOn.' '.$startsAtTime, $endsOn.' '.$endsAtTime);
            if ($wallClock !== $durationMinutes) {
                throw new ImportException(message: sprintf('Closure duration %d does not match elapsed minutes %d or clock difference %d', $durationMinutes, $elapsed, $wallClock), field: 'durationMinutes', value: (string) $durationMinutes, codeStr: 'DURATION_MISMATCH');
            }
        }

        return [
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'sourceRecordedAt' => $this->parseTimestamp('sourceRecordedAt', $sourceRecordedAt),
            'sourceChangedAt' => $this->parseTimestamp('sourceChangedAt', $sourceChangedAt),
        ];
    }

    /**
     * Minutes between two local clock readings, ignoring any DST shift between them.
     * Both values were already parsed successfully in Europe/Berlin.
     */
    private function wallClockMinutes(string $start, string $end): int
    {
        $utc = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromFormat('!d.m.Y H:i:s', $start, $utc);
        $to = \DateTimeImmutable::createFromFormat('!d.m.Y H:i:s', $end, $utc);
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \LogicException('Closure times must be parsed before comparing clock readings.');
        }

        return (int) (($to->getTimestamp() - $from->getTimestamp()) / 60);
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
