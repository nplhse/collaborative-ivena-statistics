<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\SummarizedReport\ReportBuildResult;
use App\Statistics\Application\SummarizedReport\ReportTypeInterface;

final readonly class TransportTimeProfileReportType implements ReportTypeInterface
{
    public function __construct(
        private TransportTimeProfileBuilder $builder,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'transport_time_profile';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.reports.types.transport_time_profile.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.types.transport_time_profile.description';
    }

    #[\Override]
    public function supports(StatisticsFilter $filter): bool
    {
        return true;
    }

    #[\Override]
    public function build(StatisticsContext $context, string $locale, array $options = []): ReportBuildResult
    {
        unset($options);

        return new ReportBuildResult(
            '@Statistics/reports/types/transport_time_profile.html.twig',
            $this->builder->build($context, $locale),
        );
    }
}
