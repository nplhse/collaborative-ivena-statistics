<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;

final readonly class BenchmarkIndicationMixViewModel
{
    /**
     * @param list<BenchmarkIndicationMixRowViewModel> $overRepresented
     * @param list<BenchmarkIndicationMixRowViewModel> $underRepresented
     */
    public function __construct(
        public array $overRepresented,
        public array $underRepresented,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->overRepresented && [] === $this->underRepresented;
    }

    public function canExpandOver(): bool
    {
        return \count($this->overRepresented) > BenchmarkMetricBuilder::INDICATION_MIX_INITIAL_COUNT;
    }

    public function canExpandUnder(): bool
    {
        return \count($this->underRepresented) > BenchmarkMetricBuilder::INDICATION_MIX_INITIAL_COUNT;
    }
}
