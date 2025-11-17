<?php

declare(strict_types=1);

namespace App\Service\Statistics\Compute;

use App\Contract\CalculatorInterface;
use App\Model\Scope;
use App\Service\Statistics\TransportTime\TransportTimeAggregator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** @psalm-suppress UnusedClass */
#[AutoconfigureTag(name: 'app.stats.calculator', attributes: ['priority' => 60])]
final class TransportTimeCalculator implements CalculatorInterface
{
    public function __construct(
        private readonly TransportTimeAggregator $aggregator,
    ) {
    }

    #[\Override]
    public function supports(Scope $scope): bool
    {
        $allowedGranularities = ['all', 'month', 'quarter', 'year'];

        if (!\in_array($scope->granularity, $allowedGranularities, true)) {
            return false;
        }

        return \in_array(
            $scope->scopeType,
            [
                'public',
                'all',
                'hospital',
                'dispatch_area',
                'state',
                'hospital_tier',
                'hospital_size',
                'hospital_location',
                'hospital_cohort',
            ],
            true
        );
    }

    #[\Override]
    public function calculate(Scope $scope): void
    {
        $this->aggregator
            ->forScope($scope)
            ->withCoreBuckets()
            ->withDimensions(['occasion', 'assignment', 'speciality', 'indication', 'dispatch_area', 'state'])
            ->withDimensionLimit(25)
            ->execute();
    }
}
