<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto;

final readonly class TransportTimeProfileCell
{
    public function __construct(
        public int $bucketN,
        public bool $smallSample,
        public ?int $count = null,
        public ?float $percent = null,
        public ?float $deltaPp = null,
        public string $heatClass = '',
        public ?int $rank = null,
        public ?int $rankDelta = null,
        public bool $enteredTop = false,
        public ?string $entityLabel = null,
        public ?string $linkUrl = null,
        public ?int $entityId = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->count
            && null === $this->percent
            && null === $this->entityLabel;
    }
}
