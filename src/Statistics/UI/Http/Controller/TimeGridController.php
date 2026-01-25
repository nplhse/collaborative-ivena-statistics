<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\TimeGridRequest;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Builder\TimeGridBuilder;
use App\Statistics\Infrastructure\Presets\TimeGridMetricPresets;
use App\Statistics\Infrastructure\Util\Period;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TimeGridController extends AbstractController
{
    #[Route('/statistics/timegrid', name: 'app_stats_timegrid')]
    public function __invoke(
        Request $request,
        TimeGridBuilder $builder,
    ): Response {
        $dto = TimeGridRequest::fromRequest($request);

        $granularity = \in_array($dto->granularity, Period::allGranularities(), true)
            ? $dto->granularity
            : Period::YEAR;

        $periodKey = Period::normalizePeriodKey($granularity, $dto->periodKey);

        $primary = new Scope(
            scopeType: $dto->primaryType,
            scopeId: $dto->primaryId,
            granularity: $granularity,
            periodKey: $periodKey
        );

        if (null !== $dto->baseType && null !== $dto->baseId) {
            $base = new Scope(
                scopeType: $dto->baseType,
                scopeId: $dto->baseId,
                granularity: $granularity,
                periodKey: $periodKey
            );
        } else {
            $base = null;
        }

        $data = $builder->build(
            primary: $primary,
            metrics: TimeGridMetricPresets::rowsFor($dto->metricsPreset),
            mode: $dto->mode,
            base: $base
        );

        return $this->render('@Statistics/time_grid.html.twig', [
            'primary' => $primary,
            'base' => $base,
            'mode' => $dto->mode,
            'preset' => $dto->metricsPreset,
            'columns' => $data['columns'],
            'rows' => $data['rows'],
            'presets' => TimeGridMetricPresets::all(),
            'currentPreset' => $dto->metricsPreset,
            'view' => $dto->view,
        ]);
    }
}
