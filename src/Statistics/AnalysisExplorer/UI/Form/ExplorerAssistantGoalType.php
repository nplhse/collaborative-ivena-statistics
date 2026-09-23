<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
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
final class ExplorerAssistantGoalType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (ExplorerAssistantGoal::cases() as $goal) {
            $builder->add($goal->value, NextFlowType::class, [
                'label' => 'stats.analysis_explorer.assistant.goal.'.$goal->value.'.title',
                'handler' => static function (mixed $data, ButtonFlowInterface $button, FormFlowInterface $flow) use ($goal): void {
                    if (!$data instanceof ExplorerAssistantDraft) {
                        return;
                    }

                    $data->goal = $goal;
                    $data->row = null;
                    $data->column = null;
                    $data->columnGrain = null;
                    $data->grain = null;
                    if (ExplorerAssistantGoal::TimeSeries === $goal) {
                        $data->row = AnalysisDimensionKey::Time;
                        $data->grain = AnalysisDimensionGrain::Year;
                    }
                    $flow->moveNext();
                },
                'attr' => [
                    'class' => 'card card-body card-link w-100 text-start analytics-assistant-goal',
                    'data-testid' => 'stats-analysis-explorer-assistant-goal-'.$goal->value,
                ],
            ]);
        }
    }
}
