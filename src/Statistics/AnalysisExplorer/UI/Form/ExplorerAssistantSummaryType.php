<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\ButtonFlowType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ExplorerAssistantDraft>
 */
final class ExplorerAssistantSummaryType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $draft = $options['draft'];
        foreach (['goal', 'source', 'context', 'questions', 'filters'] as $step) {
            if (\in_array($step, ['context', 'filters'], true) && $draft instanceof ExplorerAssistantDraft && AnalysisDataSourceKey::Hospitals === $draft->dataSource) {
                continue;
            }

            $builder->add('edit_'.$step, ButtonFlowType::class, [
                'label' => 'stats.analysis_explorer.assistant.summary.edit',
                'validation_groups' => false,
                'attr' => [
                    'class' => 'btn btn-sm btn-ghost-primary',
                    'data-testid' => 'stats-analysis-explorer-assistant-edit-'.$step,
                ],
                'handler' => static function (mixed $data, mixed $button, FormFlowInterface $flow) use ($step): void {
                    $flow->movePrevious($step);
                },
            ]);
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'draft' => null,
        ]);
        $resolver->setAllowedTypes('draft', ['null', ExplorerAssistantDraft::class]);
    }
}
