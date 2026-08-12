<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\Monthly;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\SummarizedReport\ReportBuildResult;
use App\Statistics\Application\SummarizedReport\ReportTypeInterface;

final readonly class MonthlyReportType implements ReportTypeInterface
{
    public function __construct(
        private MonthlyReportBuilder $monthlyReportBuilder,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'monthly';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.reports.types.monthly.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.types.monthly.description';
    }

    #[\Override]
    public function supports(StatisticsFilter $filter): bool
    {
        return true;
    }

    #[\Override]
    public function build(StatisticsContext $context, string $locale, array $options = []): ReportBuildResult
    {
        $year = isset($options['year']) ? filter_var($options['year'], FILTER_VALIDATE_INT) : null;
        $month = isset($options['month']) ? filter_var($options['month'], FILTER_VALIDATE_INT) : null;

        return new ReportBuildResult(
            '@Statistics/reports/types/monthly.html.twig',
            $this->monthlyReportBuilder->build(
                $context,
                $locale,
                year: false !== $year ? $year : null,
                month: false !== $month ? $month : null,
            ),
        );
    }
}
