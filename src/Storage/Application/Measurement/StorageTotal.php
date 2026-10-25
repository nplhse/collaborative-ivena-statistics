<?php

declare(strict_types=1);

namespace App\Storage\Application\Measurement;

use App\Storage\Domain\Enum\MeasurementItemFlag;

final readonly class StorageTotal
{
    /**
     * @param string                    $key   category or location value
     * @param list<MeasurementItemFlag> $flags
     */
    public function __construct(
        public string $key,
        public ?string $detail,
        public int $bytes,
        public bool $complete,
        public array $flags,
    ) {
    }
}
