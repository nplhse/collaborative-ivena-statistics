<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

final readonly class PatternPackageChecksum
{
    public function __construct(
        public string $algorithm,
        public string $value,
    ) {
    }

    /**
     * @return array{algorithm: string, value: string}
     */
    public function toArray(): array
    {
        return ['algorithm' => $this->algorithm, 'value' => $this->value];
    }
}
