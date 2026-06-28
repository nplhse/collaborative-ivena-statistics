<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Application\Export\DTO\ExportDateTimeRange;

final class ExportDateTimeRangeResolver
{
    public function resolve(
        \DateTimeInterface $dateFrom,
        \DateTimeInterface $dateTo,
        ?\DateTimeInterface $timeFrom = null,
        ?\DateTimeInterface $timeTo = null,
    ): ExportDateTimeRange {
        $from = \DateTimeImmutable::createFromInterface($dateFrom);
        $to = \DateTimeImmutable::createFromInterface($dateTo);

        if ($timeFrom instanceof \DateTimeInterface) {
            $from = $from->setTime(
                (int) $timeFrom->format('H'),
                (int) $timeFrom->format('i'),
                (int) $timeFrom->format('s'),
            );
        } else {
            $from = $from->setTime(0, 0, 0);
        }

        if ($timeTo instanceof \DateTimeInterface) {
            $to = $to->setTime(
                (int) $timeTo->format('H'),
                (int) $timeTo->format('i'),
                (int) $timeTo->format('s'),
            );
        } else {
            $to = $to->setTime(23, 59, 59);
        }

        if ($from > $to) {
            throw new \InvalidArgumentException('Export date range start must not be after end.');
        }

        return new ExportDateTimeRange($from, $to);
    }
}
