<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisPagePresenter
{
    public function __construct(
        private AnalysisDefinitionOptionsBuilder $optionsBuilder,
        private AnalysisPivotChoicesFactory $pivotChoicesFactory,
        private AnalysisToolbarViewModelFactory $toolbarViewModelFactory,
    ) {
    }

    /**
     * @param list<AnalysisDefinitionInterface> $allDefinitions
     */
    public function present(
        Request $request,
        AnalysisRequestModel $analysisRequest,
        string $analysisKey,
        StatisticWidget $analysisWidget,
        array $allDefinitions,
    ): AnalysisPageViewModel {
        $activeDefinition = $this->findDefinition($allDefinitions, $analysisKey);
        $options = $this->optionsBuilder->build($request, $allDefinitions);
        $pivotChoices = $this->pivotChoicesFactory->build($request, $analysisKey);
        if (StatisticWidgetType::PivotTable === $analysisWidget->type) {
            $analysisWidget = new StatisticWidget(
                StatisticWidgetType::PivotTable,
                $analysisWidget->id,
                array_merge($analysisWidget->payload, [
                    'pivotRowChoices' => $pivotChoices['rows'],
                    'pivotColChoices' => $pivotChoices['cols'],
                    'pivotMeasureChoices' => $pivotChoices['measures'],
                ]),
                $analysisWidget->title,
                $analysisWidget->actions,
            );
        }

        return new AnalysisPageViewModel(
            $analysisWidget,
            $options['definitions'],
            $analysisKey,
            $options['urls'],
            $this->toolbarViewModelFactory->create($request, $activeDefinition, $analysisRequest),
            $pivotChoices['rows'],
            $pivotChoices['cols'],
            $pivotChoices['measures'],
        );
    }

    /**
     * @param list<AnalysisDefinitionInterface> $definitions
     */
    private function findDefinition(array $definitions, string $analysisKey): AnalysisDefinitionInterface
    {
        foreach ($definitions as $definition) {
            if ($definition->key() === $analysisKey) {
                return $definition;
            }
        }

        return $definitions[0];
    }
}
