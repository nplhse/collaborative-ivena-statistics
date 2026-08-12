<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Predefined summarized report type (e.g. monthly overview).
 */
#[AutoconfigureTag('app.statistics.report_type')]
interface ReportTypeInterface
{
    public function key(): string;

    /** XLIFF resname for the type selector and headings. */
    public function labelTranslationKey(): string;

    /** XLIFF resname for the short description under the selector. */
    public function descriptionTranslationKey(): string;

    public function supports(StatisticsFilter $filter): bool;

    /**
     * @param array<string, scalar|null> $options
     */
    public function build(StatisticsContext $context, string $locale, array $options = []): ReportBuildResult;
}
