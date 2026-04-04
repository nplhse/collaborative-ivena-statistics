<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use App\Statistics\Application\Panel\Distribution\TransportTimeBucketExpression;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TransportTimeBucketValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        $key = match ($value) {
            TransportTimeBucketExpression::UNDER_10 => 'statistics.distribution.transport_time_bucket.under_10',
            TransportTimeBucketExpression::MIN_10_TO_20 => 'statistics.distribution.transport_time_bucket.10_20',
            TransportTimeBucketExpression::MIN_20_TO_30 => 'statistics.distribution.transport_time_bucket.20_30',
            TransportTimeBucketExpression::MIN_30_TO_40 => 'statistics.distribution.transport_time_bucket.30_40',
            TransportTimeBucketExpression::MIN_40_TO_50 => 'statistics.distribution.transport_time_bucket.40_50',
            TransportTimeBucketExpression::MIN_50_TO_60 => 'statistics.distribution.transport_time_bucket.50_60',
            TransportTimeBucketExpression::OVER_60 => 'statistics.distribution.transport_time_bucket.over_60',
            TransportTimeBucketExpression::UNKNOWN => 'statistics.distribution.transport_time_bucket.unknown',
            default => 'statistics.distribution.unknown_code',
        };

        return $this->translator->trans($key);
    }
}
