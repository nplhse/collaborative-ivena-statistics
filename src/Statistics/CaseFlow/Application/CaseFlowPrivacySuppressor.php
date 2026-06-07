<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

use App\Statistics\CaseFlow\Application\DTO\CaseFlowDestinationPoolSlice;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowFlowMatrixRow;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowMapFeature;
use App\Statistics\CaseFlow\Application\DTO\CaseFlowOriginSlice;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowDestinationPoolRow;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowFlowMatrixCell;
use App\Statistics\CaseFlow\Infrastructure\Query\Dto\CaseFlowOriginRow;

final class CaseFlowPrivacySuppressor
{
    /**
     * @param list<CaseFlowOriginRow> $rows
     *
     * @return list<CaseFlowOriginSlice>
     */
    public function suppressOriginDistribution(array $rows, int $totalCases): array
    {
        if ($totalCases <= 0) {
            return [];
        }

        $visible = [];
        $otherCount = 0;
        $otherEmergency = 0;
        $otherId = 0;

        foreach ($rows as $row) {
            if ($row->caseCount < CaseFlowPrivacyPolicy::MIN_CASES_PER_ORIGIN_BAR) {
                $otherCount += $row->caseCount;
                $otherEmergency += $row->emergencyCount;

                continue;
            }

            if (\count($visible) >= CaseFlowPrivacyPolicy::MAX_VISIBLE_ORIGINS) {
                $otherCount += $row->caseCount;
                $otherEmergency += $row->emergencyCount;

                continue;
            }

            $visible[] = new CaseFlowOriginSlice(
                $row->dispatchAreaId,
                $row->originName,
                $row->caseCount,
                $row->emergencyCount,
                false,
            );
        }

        if ($otherCount > 0) {
            $visible[] = new CaseFlowOriginSlice(
                $otherId,
                CaseFlowPrivacyPolicy::OTHER_ORIGIN_KEY,
                $otherCount,
                $otherEmergency,
                false,
            );
        }

        return $visible;
    }

    /**
     * @param list<CaseFlowOriginRow> $rows
     *
     * @return list<CaseFlowMapFeature>
     */
    public function buildMapFeatures(array $rows, int $totalCases, callable $geoKeyResolver): array
    {
        if ($totalCases <= 0) {
            return [];
        }

        $features = [];
        foreach ($rows as $row) {
            $suppressed = $row->caseCount < CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL;
            $features[] = new CaseFlowMapFeature(
                $row->dispatchAreaId,
                $row->originName,
                $geoKeyResolver($row->dispatchAreaId, $row->originName),
                $suppressed ? 0 : $row->caseCount,
                $suppressed ? 0.0 : round(((float) $row->caseCount / (float) $totalCases) * 100.0, 1),
                $suppressed,
            );
        }

        return $features;
    }

    /**
     * @param list<CaseFlowFlowMatrixCell> $cells
     *
     * @return list<CaseFlowFlowMatrixRow>
     */
    public function suppressFlowMatrix(array $cells): array
    {
        /** @var array<int, array{originName: string, cells: list<CaseFlowFlowMatrixCell>}> $byOrigin */
        $byOrigin = [];
        foreach ($cells as $cell) {
            $byOrigin[$cell->dispatchAreaId]['originName'] = $cell->originName;
            $byOrigin[$cell->dispatchAreaId]['cells'][] = $cell;
        }

        $rows = [];
        foreach ($byOrigin as $dispatchAreaId => $group) {
            $originTotal = 0;
            $destinationCounts = [];
            $hasVisibleCell = false;

            foreach ($group['cells'] as $cell) {
                $poolKey = null === $cell->destinationPoolCode
                    ? 'unknown'
                    : (string) $cell->destinationPoolCode;

                $visible = $cell->caseCount >= CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL
                    && $cell->hospitalCount >= CaseFlowPrivacyPolicy::MIN_HOSPITALS_PER_DESTINATION_POOL;

                if (!$visible) {
                    $poolKey = CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY;
                } else {
                    $hasVisibleCell = true;
                }

                $destinationCounts[$poolKey] = ($destinationCounts[$poolKey] ?? 0) + $cell->caseCount;
                $originTotal += $cell->caseCount;
            }

            if ($originTotal < CaseFlowPrivacyPolicy::MIN_CASES_PER_ORIGIN_BAR) {
                continue;
            }

            $rows[] = new CaseFlowFlowMatrixRow(
                $dispatchAreaId,
                $group['originName'],
                $originTotal,
                $destinationCounts,
                !$hasVisibleCell,
            );
        }

        usort($rows, static fn (CaseFlowFlowMatrixRow $a, CaseFlowFlowMatrixRow $b): int => $b->totalCases <=> $a->totalCases);

        return \array_slice($rows, 0, CaseFlowPrivacyPolicy::MAX_VISIBLE_ORIGINS);
    }

    /**
     * @param list<CaseFlowDestinationPoolRow> $rows
     * @param array<string, string>            $labelKeysByPoolKey
     *
     * @return list<CaseFlowDestinationPoolSlice>
     */
    public function suppressDestinationPools(array $rows, array $labelKeysByPoolKey): array
    {
        $slices = [];
        $suppressedCount = 0;
        $suppressedHospitals = 0;

        foreach ($rows as $row) {
            $poolKey = '' === $row->poolKey ? 'unknown' : $row->poolKey;
            $visible = $row->caseCount >= CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL
                && $row->hospitalCount >= CaseFlowPrivacyPolicy::MIN_HOSPITALS_PER_DESTINATION_POOL;

            if (!$visible) {
                $suppressedCount += $row->caseCount;
                $suppressedHospitals = max($suppressedHospitals, $row->hospitalCount);

                continue;
            }

            $slices[] = new CaseFlowDestinationPoolSlice(
                $poolKey,
                $labelKeysByPoolKey[$poolKey] ?? 'stats.case_flow.pool.unknown',
                $row->caseCount,
                $row->hospitalCount,
                false,
            );
        }

        if ($suppressedCount > 0) {
            $slices[] = new CaseFlowDestinationPoolSlice(
                CaseFlowPrivacyPolicy::SUPPRESSED_POOL_KEY,
                'stats.case_flow.pool.suppressed',
                $suppressedCount,
                $suppressedHospitals,
                true,
            );
        }

        return $slices;
    }
}
