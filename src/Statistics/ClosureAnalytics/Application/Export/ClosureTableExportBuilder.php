<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Export;

use App\Shared\Application\DataTable\DataTablePreferenceState;
use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureEventTableState;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureIntervalTableColumns;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportColumn;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosureTableExportBuilder
{
    public function __construct(
        private ClosureEventQuery $eventQuery,
        private ClosureTableCsvCellFormatter $cellFormatter,
        private TranslatorInterface $translator,
    ) {
    }

    public function build(
        ClosureAnalyticsCriteria $criteria,
        ClosureEventTableState $table,
        DataTablePreferenceState $preferences,
    ): TabularExportDocument {
        $columns = $this->exportColumns($table->view, $preferences);
        $headers = array_map(
            fn (DataTableColumn $column): TabularExportColumn => new TabularExportColumn(
                $column->key,
                $this->translator->trans($column->label, domain: $column->labelDomain),
            ),
            $columns,
        );

        $rows = $this->rows($criteria, $table, $columns);

        return new TabularExportDocument($headers, $rows);
    }

    public function filenameTitle(string $view): string
    {
        $key = 'intervals' === $view
            ? 'stats.closure.export.intervals'
            : 'stats.closure.export.events';

        return $this->translator->trans($key, domain: 'statistics');
    }

    /**
     * @return list<DataTableColumn>
     */
    private function exportColumns(string $view, DataTablePreferenceState $preferences): array
    {
        $definitions = 'intervals' === $view
            ? ClosureIntervalTableColumns::columns()
            : ClosureEventTableColumns::columns();
        $byKey = [];
        foreach ($definitions as $definition) {
            $column = DataTableColumn::fromArray($definition);
            $byKey[$column->key] = $column;
        }

        $columns = [];
        foreach ($preferences->visibleOrderedKeys() as $key) {
            if (isset($byKey[$key])) {
                $columns[] = $byKey[$key];
            }
        }

        return $columns;
    }

    /**
     * @param list<DataTableColumn> $columns
     *
     * @return iterable<int, list<string|int|float|null>>
     */
    private function rows(
        ClosureAnalyticsCriteria $criteria,
        ClosureEventTableState $table,
        array $columns,
    ): iterable {
        $records = 'intervals' === $table->view
            ? $this->eventQuery->iterateIntervals($criteria, $table->sortBy, $table->orderBy)
            : $this->eventQuery->iterateEvents($criteria, $table->sortBy, $table->orderBy);

        foreach ($records as $record) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->cellFormatter->format($record, $column);
            }

            yield $cells;
        }
    }
}
