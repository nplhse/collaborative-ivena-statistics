<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface ImportTimelineInterface
{
    /**
     * @return array<int,array{year:int,month:int,count:int}>
     */
    public function countByMonthLast12Months(): array;

    /**
     * @return array<int,array{year:int,month:int,count:int}>
     */
    public function countByMonthInRange(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): array;

    /**
     * @return array<int,array{year:int,count:int}>
     */
    public function countByYearInRange(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): array;
}
