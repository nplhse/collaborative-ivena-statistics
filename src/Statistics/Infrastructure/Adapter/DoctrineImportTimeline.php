<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Adapter;

use App\Import\Infrastructure\Repository\ImportRepository;
use App\Statistics\Application\Contract\ImportTimelineInterface;

final readonly class DoctrineImportTimeline implements ImportTimelineInterface
{
    public function __construct(
        private ImportRepository $importRepository,
    ) {
    }

    #[\Override]
    public function countByMonthLast12Months(): array
    {
        return $this->importRepository->countByMonthLast12Months();
    }

    #[\Override]
    public function countByMonthInRange(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): array
    {
        return $this->importRepository->countImportsByMonthInRange($from, $toExclusive);
    }

    #[\Override]
    public function countByYearInRange(\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive): array
    {
        return $this->importRepository->countImportsByYearInRange($from, $toExclusive);
    }
}
