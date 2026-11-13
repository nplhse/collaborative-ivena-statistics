<?php

declare(strict_types=1);

namespace App\Encounter\UI\Glossary;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use App\Encounter\Application\Contract\MtsTriageCountReaderInterface;
use App\Encounter\Application\Glossary\EncounterCountScopeResolver;
use App\Encounter\Application\Glossary\EncounterHospitalScope;
use App\Encounter\Application\Glossary\MtsCount;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCategory;

/**
 * View data of the MTS glossary pages (#1056). Definitions come from the MTS catalog and the
 * MtsCategory enum and are the same for every participant. Counts come from the initial triage of
 * the encounters the current user may see (EncounterCountScopeResolver) and are null for everyone else.
 *
 * Encounters keep the code as sent; counts are summed per entry over its code and every alias code that resolves
 * to it (ADR 032). Codes that resolve to nothing are listed as they are.
 */
final readonly class MtsGlossaryFactory
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private MtsCatalog $catalog,
        private MtsNames $names,
        private MtsTriageCountReaderInterface $counts,
        private EncounterCountScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @return array{
     *     scope: EncounterHospitalScope,
     *     has_counts: bool,
     *     categories: list<array{category: MtsCategory, count: ?MtsCount}>,
     *     diagrams: list<MtsGlossaryRow>,
     *     indicators: list<MtsGlossaryRow>,
     * }
     */
    public function overview(): array
    {
        $scope = $this->scopeResolver->forCurrentUser();
        $hasCounts = !$scope->isEmpty();
        $categoryCounts = $hasCounts ? $this->counts->countByCategory($scope) : [];
        $diagramCounts = $hasCounts ? $this->counts->countByDiagram($scope) : [];
        $indicatorCounts = $hasCounts ? $this->counts->countByIndicator($scope) : [];

        $categories = [];
        foreach (MtsCategory::cases() as $category) {
            $categories[] = [
                'category' => $category,
                'count' => $hasCounts ? new MtsCount($categoryCounts[$category->value] ?? 0) : null,
            ];
        }

        $diagrams = [];
        foreach ($this->catalog->diagrams() as $code => $entry) {
            $count = $hasCounts ? $this->sum($diagramCounts, $this->catalog->codesResolvingTo(MtsCatalogKind::Diagram, $code)) : null;
            $diagrams[] = $this->withCount($this->names->diagram($entry), $count);
        }

        $indicators = [];
        foreach ($this->catalog->indicators() as $code => $entry) {
            $count = $hasCounts ? $this->sum($indicatorCounts, $this->catalog->codesResolvingTo(MtsCatalogKind::Indicator, $code)) : null;
            $indicators[] = $this->withCount($this->names->indicator($entry), $count);
        }

        return [
            'scope' => $scope,
            'has_counts' => $hasCounts,
            'categories' => $categories,
            'diagrams' => $diagrams,
            'indicators' => $indicators,
        ];
    }

    /**
     * Null when the code resolves to no diagram. An alias code shows its target diagram; the page code is the
     * target's code (MtsCatalogEntry::$code).
     *
     * @return array{
     *     scope: EncounterHospitalScope,
     *     has_counts: bool,
     *     diagram: MtsGlossaryRow,
     *     indicators: list<MtsGlossaryRow>,
     *     seen_indicators: int,
     *     without_indicator: ?MtsCount,
     *     filter: string,
     * }|null
     */
    public function diagram(string $code, string $filter): ?array
    {
        $entry = $this->catalog->diagram($code);
        if (!$entry instanceof MtsCatalogEntry) {
            return null;
        }

        $scope = $this->scopeResolver->forCurrentUser();
        $hasCounts = !$scope->isEmpty();
        $counts = $hasCounts ? $this->counts->countIndicatorsOfDiagram($scope, $this->catalog->codesResolvingTo(MtsCatalogKind::Diagram, $entry->code)) : [];
        $total = array_sum($counts);
        $withoutIndicator = $counts[''] ?? 0;
        unset($counts['']);

        /** @var array<string, array{row: MtsGlossaryRow, count: int}> $resolved keyed by resolved code */
        $resolved = [];
        foreach ($counts as $indicatorCode => $count) {
            $indicatorCode = (string) $indicatorCode;
            $indicatorEntry = $this->catalog->indicator($indicatorCode);
            $row = $indicatorEntry instanceof MtsCatalogEntry
                ? $this->names->indicator($indicatorEntry)
                : new MtsGlossaryRow($indicatorCode, $indicatorCode, null, inCatalog: false);
            $resolved[$row->code] = ['row' => $row, 'count' => ($resolved[$row->code]['count'] ?? 0) + $count];
        }

        $indicators = [];
        foreach ($resolved as ['row' => $row, 'count' => $count]) {
            $indicators[] = $this->withCount($row, $count);
        }

        $filter = trim($filter);
        $seen = \count($indicators);
        if ('' !== $filter) {
            $indicators = array_values(array_filter(
                $indicators,
                static fn (MtsGlossaryRow $row): bool => false !== mb_stripos($row->name, $filter)
                    || false !== mb_stripos($row->code, $filter)
                    || (null !== $row->germanName && false !== mb_stripos($row->germanName, $filter)),
            ));
        }

        usort($indicators, static fn (MtsGlossaryRow $a, MtsGlossaryRow $b): int => strcmp($a->code, $b->code));

        return [
            'scope' => $scope,
            'has_counts' => $hasCounts,
            'diagram' => $this->withCount($this->names->diagram($entry), $hasCounts ? $total : null),
            'indicators' => $indicators,
            'seen_indicators' => $seen,
            'without_indicator' => $hasCounts && $withoutIndicator > 0 ? new MtsCount($withoutIndicator) : null,
            'filter' => $filter,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @param list<string>       $codes
     */
    private function sum(array $counts, array $codes): int
    {
        return array_sum(array_map(static fn (string $code): int => $counts[$code] ?? 0, $codes));
    }

    private function withCount(MtsGlossaryRow $row, ?int $count): MtsGlossaryRow
    {
        return new MtsGlossaryRow($row->code, $row->name, $row->germanName, null === $count ? null : new MtsCount($count), $row->inCatalog);
    }
}
