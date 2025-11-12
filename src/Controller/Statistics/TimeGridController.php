<?php

declare(strict_types=1);

namespace App\Controller\Statistics;

use App\DataTransferObjects\TimeGridRequest;
use App\Enum\TimeGridMode;
use App\Model\Scope;
use App\Service\Statistics\TimeGridBuilder;
use App\Service\Statistics\TimeGridMetricPresets;
use App\Service\Statistics\Util\Period;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class TimeGridController extends AbstractController
{
    #[Route('/stats/timegrid', name: 'app_stats_timegrid')]
    public function __invoke(
        Request $request,
        TimeGridBuilder $builder,
    ): Response {
        // 1) Map query -> DTO (you’ve implemented TimeGridRequest already)
        $dto = TimeGridRequest::fromRequest($request);

        // 2) Validate/fix granularity & periodKey via Period helpers (defensive)
        $granularity = \in_array($dto->granularity, Period::allGranularities(), true)
            ? $dto->granularity
            : Period::YEAR;

        $periodKey = Period::normalizePeriodKey($granularity, $dto->periodKey);

        // 3) Build primary/base Scopes
        $primary = new Scope(
            scopeType: $dto->primaryType,
            scopeId: $dto->primaryId,
            granularity: $granularity,
            periodKey: $periodKey
        );

        if ($dto->baseType && $dto->baseId) {
            $base = new Scope(
                scopeType: $dto->baseType,
                scopeId: $dto->baseId,
                granularity: $granularity,
                periodKey: $periodKey
            );
        } else {
            $base = null;
        }

        // 4) Resolve metric list from preset (simple string like "total", "gender", …)
        $metrics = TimeGridMetricPresets::rowsFor($dto->metricsPreset ?? 'default');

        $view = strtolower((string) $request->query->get('view', 'counts'));
        $formatFilter = 'pct' === $view ? 'pct' : 'int';

        // 5) Mode (enum)
        $mode = $dto->mode ?? TimeGridMode::RAW;

        // 6) Build grid (columns + rows)
        $data = $builder->build(
            primary: $primary,
            metrics: $metrics,
            mode: $mode,
            base: $base
        );

        // 7) Render
        return $this->render('stats/time_grid.html.twig', [
            'primary' => $primary,
            'base' => $base,
            'mode' => $mode,
            'preset' => $dto->metricsPreset ?? 'default',
            'columns' => $data['columns'],
            'rows' => $data['rows'],
            'presets' => TimeGridMetricPresets::all(),
            'currentPreset' => $dto->metricsPreset ?? 'default',
            'view' => $view,
        ]);
    }
}
