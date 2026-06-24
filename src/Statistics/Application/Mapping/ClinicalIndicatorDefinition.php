<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

final readonly class ClinicalIndicatorDefinition
{
    public function __construct(
        public string $bucketKey,
        public string $labelTranslationKey,
        public string $matchSqlCondition,
        public string $overviewCountKey,
    ) {
    }
}
