<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum MetricComputationKind: string
{
    case SqlAggregate = 'sql_aggregate';
    case Relative = 'relative';
    case InferentialStub = 'inferential_stub';
}
