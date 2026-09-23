<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\NextFlowType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<ExplorerAssistantDraft>
 */
final class ExplorerAssistantSourceType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (AnalysisDataSourceKey::cases() as $source) {
            $builder->add($source->value, NextFlowType::class, [
                'label' => $source->labelTranslationKey(),
                'handler' => static function (mixed $data, ButtonFlowInterface $button, FormFlowInterface $flow) use ($source): void {
                    if (!$data instanceof ExplorerAssistantDraft) {
                        return;
                    }

                    if ($data->dataSource !== $source) {
                        $data->dataSource = $source;
                        $data->row = null;
                        $data->column = null;
                        $data->grain = null;
                        $data->columnGrain = null;
                        $data->metric = AnalysisMetricKey::defaultFor($source);
                        $data->filterDepartmentId = null;
                        $data->filterSpecialityId = null;
                        $data->filterUrgency = null;
                        $data->filterTransportType = null;
                        $data->filterGender = null;
                        $data->filterAgeGroup = null;
                        $data->filterResus = null;
                        $data->filterCpr = null;
                        $data->filterVentilation = null;
                        $data->filterAssignmentId = null;
                        $data->filterIndicationId = null;
                        $data->filterSecondaryIndicationId = null;
                        $data->filterIndicationGroupId = null;
                        if (AnalysisDataSourceKey::Allocations === $source && ExplorerAssistantGoal::TimeSeries === $data->goal) {
                            $data->row = AnalysisDimensionKey::Time;
                            $data->grain = AnalysisDimensionGrain::Year;
                        }
                    }

                    $flow->moveNext();
                },
                'attr' => [
                    'class' => 'card card-body card-link w-100 text-start analytics-assistant-goal',
                    'data-testid' => 'stats-analysis-explorer-assistant-source-'.$source->value,
                ],
            ]);
        }
    }
}
