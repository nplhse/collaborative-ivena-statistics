<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

/**
 * Renderable statistics unit for the overview and other pages.
 *
 * Payload shape depends on {@see StatisticWidgetType}:
 * - kpi: icon, value (int), labelTranslationKey, formatThousands (bool, optional),
 *        secondaryLabelTranslationKey (string, optional), secondaryValue (int, optional)
 * - chart_pair: allocationChart, importChart (same shape as before for templates)
 * - table: headerTranslationKeys, rows, optional footerRow, optional summaryStats (meanDisplay, stdDevDisplay),
 *          optional numericColumnStartIndex (1-based column index from which numbers are right-aligned; default 2 as before)
 *          optional limitFooter for report tables: urls (array<int, string>), current (int), same idea as allocations pagination
 *          optional monthRowTargets (analysis): list<StatisticWidgetNavigationTarget|null> parallel to rows — first column as link
 * - simple_chart: chartType (line|bar), labels, counts, optional summaryStats (same as table)
 * - section: titleTranslationKey
 * - distribution: titleTranslationKey, rows (labelTranslationKey, count, percent); optional widget.actions (cross-nav in card header)
 * - summary_deck: hospital overview deck (KPI + gender + urgency), see HospitalSummaryProvider;
 *               optional kpi.actions, gender.actions, urgency.actions (each list<StatisticWidgetNavigationTarget>)
 */
final readonly class StatisticWidget
{
    /**
     * @param array<string, mixed>                  $payload
     * @param list<StatisticWidgetNavigationTarget> $actions Header links (per card / chart card)
     */
    public function __construct(
        public StatisticWidgetType $type,
        public string $id,
        public array $payload = [],
        public ?string $title = null,
        public array $actions = [],
    ) {
    }
}
