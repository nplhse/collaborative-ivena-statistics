<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Domain\Model\DistributionPanelView;
use App\Statistics\Infrastructure\Query\AllocationStatsDistributionQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DistributionPanelBuilder
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private AllocationStatsDistributionQuery $query,
        private DistributionTransformer $transformer,
        private UrgencyCodeLabelMapper $urgencyMapper,
        private GenderCodeLabelMapper $genderMapper,
        private HospitalTierCodeLabelMapper $hospitalTierMapper,
        private HospitalLocationCodeLabelMapper $hospitalLocationMapper,
        private TranslatorInterface $translator,
    ) {
    }

    public function build(
        string $primaryDimension,
        ?string $groupDimension,
    ): DistributionPanelView {
        $rows = $this->query->fetchAggregated($primaryDimension, $groupDimension);

        $primaryMapper = $this->primaryMapperFor($primaryDimension);
        $groupMapper = null;
        if (null !== $groupDimension && '' !== $groupDimension) {
            $groupMapper = $this->groupMapperFor($groupDimension);
        }
        $grouped = $groupMapper instanceof CodeLabelMapperInterface;

        $simpleName = $this->translator->trans('statistics.distribution.count_series');

        return $this->transformer->transform(
            $rows,
            $primaryMapper,
            $groupMapper,
            $grouped,
            $simpleName,
        );
    }

    private function primaryMapperFor(string $primaryDimension): CodeLabelMapperInterface
    {
        return match ($primaryDimension) {
            'urgency' => $this->urgencyMapper,
            'gender' => $this->genderMapper,
            default => throw new \InvalidArgumentException('Unsupported primary dimension: '.$primaryDimension),
        };
    }

    private function groupMapperFor(string $groupDimension): CodeLabelMapperInterface
    {
        return match ($groupDimension) {
            'hospital_tier' => $this->hospitalTierMapper,
            'hospital_location' => $this->hospitalLocationMapper,
            default => throw new \InvalidArgumentException('Unsupported group dimension: '.$groupDimension),
        };
    }
}
