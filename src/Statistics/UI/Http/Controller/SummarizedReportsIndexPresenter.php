<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\SummarizedReport\ReportTypeRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class SummarizedReportsIndexPresenter
{
    public function __construct(
        private ReportTypeRegistry $reportTypeRegistry,
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    public function present(Request $request): SummarizedReportsIndexViewModel
    {
        $cards = [];
        foreach ($this->reportTypeRegistry->all() as $type) {
            $cards[] = new SummarizedReportsIndexCardViewModel(
                $type->key(),
                $type->labelTranslationKey(),
                $type->descriptionTranslationKey(),
                $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_reports_show',
                    ['type' => $type->key()],
                ),
            );
        }

        return new SummarizedReportsIndexViewModel($cards);
    }
}
