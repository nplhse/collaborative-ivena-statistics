<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidget;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Curated statistics report.
 *
 * {@see build()} receives {@see StatisticsContext} so hospital scopes such as "My hospitals"
 * can respect the signed-in user (same idea as analysis).
 */
#[AutoconfigureTag('app.statistics.report_definition')]
interface ReportDefinitionInterface
{
    public function key(): string;

    /** XLIFF resname for the dropdown and headings. */
    public function labelTranslationKey(): string;

    /** XLIFF resname for the short description under the selector. */
    public function descriptionTranslationKey(): string;

    public function supports(StatisticsFilter $filter): bool;

    public function build(StatisticsContext $context, int $limit): StatisticWidget;

    /**
     * @return list<int>
     */
    public function allowedLimits(): array;
}
