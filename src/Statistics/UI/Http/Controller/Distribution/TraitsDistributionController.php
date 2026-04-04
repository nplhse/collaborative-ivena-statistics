<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Distribution;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/statistics/distribution/traits', name: 'app_stats_distribution_traits', methods: ['GET'])]
final class TraitsDistributionController extends AbstractController
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
     * @return array{
     *     route_name: string,
     *     section_key: string,
     *     panels: list<array<string, mixed>>,
     *     default_panel_key?: string|null,
     * }
     */
    private function pageOptions(): array
    {
        $panelBase = [
            'type' => 'distribution',
            'dimension_kind' => DimensionKind::Column->value,
            'group_by_field' => 'hospital_tier_code',
            'group_by_label' => 'statistics.distribution.dim.hospital_tier',
            'filters' => ['date_range', 'hospital_tier', 'hospital_location'],
            'options' => ['default_view' => 'grouped', 'show_percent' => true],
            'controls' => [
                'allow_view_mode_toggle' => true,
                'allow_group_by' => true,
                'allow_bar_basis_average' => true,
            ],
            'average_metric' => 'age',
            'filter_defaults' => [
                'date_range' => 'all_cases',
                'hospital_tier' => [],
                'hospital_location' => [],
            ],
        ];

        return [
            'route_name' => 'app_stats_distribution_traits',
            'section_key' => 'traits',
            'default_panel_key' => 'is_cpr',
            'panels' => [
                array_replace($panelBase, [
                    'key' => 'is_cpr',
                    'dimension_field' => '(CASE WHEN is_cpr IS NULL THEN 0 WHEN is_cpr = false THEN 1 ELSE 2 END)',
                    'dimension_label' => 'statistics.distribution.dim.is_cpr',
                ]),
                array_replace($panelBase, [
                    'key' => 'is_ventilated',
                    'dimension_field' => '(CASE WHEN is_ventilated IS NULL THEN 0 WHEN is_ventilated = false THEN 1 ELSE 2 END)',
                    'dimension_label' => 'statistics.distribution.dim.is_ventilated',
                ]),
                array_replace($panelBase, [
                    'key' => 'is_with_physician',
                    'dimension_field' => '(CASE WHEN is_with_physician IS NULL THEN 0 WHEN is_with_physician = false THEN 1 ELSE 2 END)',
                    'dimension_label' => 'statistics.distribution.dim.is_with_physician',
                ]),
            ],
        ];
    }
}
