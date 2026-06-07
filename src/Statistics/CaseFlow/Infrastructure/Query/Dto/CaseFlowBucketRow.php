<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query\Dto;

final readonly class CaseFlowBucketRow
{
    public function __construct(
        public string $bucketKey,
        public int $caseCount,
    ) {
    }
}
