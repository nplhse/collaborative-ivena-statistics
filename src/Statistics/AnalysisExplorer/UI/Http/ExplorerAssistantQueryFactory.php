<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http;

use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantFilters;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantQuery;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final class ExplorerAssistantQueryFactory
{
    public function fromRequest(Request $request): ExplorerAssistantQuery
    {
        $malformed = false;

        $goal = $this->optionalEnum(ExplorerAssistantGoal::class, $request, ExplorerQueryKeys::GUIDE, $malformed);
        $row = $this->optionalEnum(AnalysisDimensionKey::class, $request, ExplorerQueryKeys::ROW, $malformed);
        $column = $this->optionalEnum(AnalysisDimensionKey::class, $request, ExplorerQueryKeys::COLUMN, $malformed);
        $grain = $this->optionalEnum(AnalysisDimensionGrain::class, $request, ExplorerQueryKeys::GRAIN, $malformed);
        $columnGrain = $this->optionalEnum(AnalysisDimensionGrain::class, $request, ExplorerQueryKeys::COLUMN_GRAIN, $malformed);
        $metric = $this->optionalEnum(AnalysisMetricKey::class, $request, ExplorerQueryKeys::METRIC, $malformed)
            ?? AnalysisMetricKey::AllocationCount;
        $dataSource = $this->optionalEnum(AnalysisDataSourceKey::class, $request, ExplorerQueryKeys::DATA_SOURCE, $malformed)
            ?? AnalysisDataSourceKey::Allocations;

        return new ExplorerAssistantQuery(
            $goal instanceof ExplorerAssistantGoal ? $goal : null,
            $row instanceof AnalysisDimensionKey ? $row : null,
            $column instanceof AnalysisDimensionKey ? $column : null,
            $grain instanceof AnalysisDimensionGrain ? $grain : null,
            $columnGrain instanceof AnalysisDimensionGrain ? $columnGrain : null,
            $metric instanceof AnalysisMetricKey ? $metric : AnalysisMetricKey::AllocationCount,
            $dataSource instanceof AnalysisDataSourceKey ? $dataSource : AnalysisDataSourceKey::Allocations,
            $this->filtersFromRequest($request, $malformed),
            $malformed,
        );
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    private function optionalEnum(string $enumClass, Request $request, string $key, bool &$malformed): ?\BackedEnum
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $raw = trim($request->query->getString($key));
        if ('' === $raw) {
            return null;
        }

        $value = $enumClass::tryFrom($raw);
        if (!$value instanceof \BackedEnum) {
            $malformed = true;

            return null;
        }

        return $value;
    }

    private function filtersFromRequest(Request $request, bool &$malformed): ExplorerAssistantFilters
    {
        return new ExplorerAssistantFilters(
            departmentId: $this->optionalInt($request, 'department', $malformed),
            specialityId: $this->optionalInt($request, 'speciality', $malformed),
            urgency: $this->optionalInt($request, 'urgency', $malformed),
            transportType: $this->optionalInt($request, 'transport_type', $malformed),
            gender: $this->optionalInt($request, 'gender', $malformed),
            ageGroup: $this->optionalString($request, 'age_group'),
            resus: $this->optionalBool($request, 'resus', $malformed),
            cpr: $this->optionalBool($request, 'cpr', $malformed),
            ventilation: $this->optionalBool($request, 'ventilation', $malformed),
            assignmentId: $this->optionalInt($request, 'assignment', $malformed),
            indicationId: $this->optionalInt($request, 'indication', $malformed),
            secondaryIndicationId: $this->optionalInt($request, 'secondary_indication', $malformed),
            indicationGroupId: $this->optionalInt($request, 'indication_group', $malformed),
        );
    }

    private function optionalInt(Request $request, string $key, bool &$malformed): ?int
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $raw = trim($request->query->getString($key));
        if ('' === $raw) {
            return null;
        }

        if (!ctype_digit($raw)) {
            $malformed = true;

            return null;
        }

        return (int) $raw;
    }

    private function optionalString(Request $request, string $key): ?string
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $raw = trim($request->query->getString($key));

        return '' === $raw ? null : $raw;
    }

    private function optionalBool(Request $request, string $key, bool &$malformed): ?bool
    {
        if (!$request->query->has($key)) {
            return null;
        }

        $raw = trim($request->query->getString($key));
        if ('' === $raw) {
            return null;
        }

        if (!\in_array($raw, ['0', '1'], true)) {
            $malformed = true;

            return null;
        }

        return '1' === $raw;
    }
}
