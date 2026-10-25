<?php

declare(strict_types=1);

namespace App\Storage\Application\Scan;

use App\Storage\Domain\Enum\StorageCategory;

final readonly class PathClassification
{
    public function __construct(
        public StorageCategory $category,
        public string $contributor,
    ) {
    }
}
