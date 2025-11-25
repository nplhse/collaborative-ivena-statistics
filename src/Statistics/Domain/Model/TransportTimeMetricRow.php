<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Model;

final class TransportTimeMetricRow
{
    /**
     * @param array<int,array{bucket:string,count:int,share:float}> $values
     */
    public function __construct(
        private string $id,
        private string $label,
        private array $values,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<int,array{bucket:string,count:int,share:float}>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
