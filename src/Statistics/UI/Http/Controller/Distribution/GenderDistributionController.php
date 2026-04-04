<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Distribution;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution/gender', name: 'app_stats_distribution_gender', methods: ['GET'])]
final class GenderDistributionController extends AbstractController
{
    public function __construct(
        private readonly DistributionPageConfigResolver $distributionPageConfigResolver,
    ) {
    }

    public function __invoke(): Response
    {
        $options = $this->pageOptions();
        $this->distributionPageConfigResolver->resolve($options);

        return $this->render('@Statistics/distribution/index.html.twig', [
            'distributionPageOptions' => $options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOptions(): array
    {
        return [
            'route_name' => 'app_stats_distribution_gender',
            'section_key' => 'gender',
            'panels' => [
                [
                    'key' => 'gender',
                    'type' => 'distribution',
                    'dimension_kind' => DimensionKind::Column->value,
                    'dimension_field' => 'gender_code',
                    'dimension_label' => 'statistics.distribution.dim.gender',
                    'group_by_field' => 'hospital_tier_code',
                    'group_by_label' => 'statistics.distribution.dim.hospital_tier',
                    'filters' => ['date_range', 'hospital_tier', 'hospital_location'],
                    'options' => ['default_view' => 'grouped', 'show_percent' => true],
                    'controls' => ['allow_view_mode_toggle' => true, 'allow_group_by' => true],
                    'filter_defaults' => [
                        'date_range' => 'all_cases',
                        'hospital_tier' => [],
                        'hospital_location' => [],
                    ],
                ],
            ],
        ];
    }
}
