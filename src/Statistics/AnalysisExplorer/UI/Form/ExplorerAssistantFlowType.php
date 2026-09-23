<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\FormFlowCursor;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\ButtonFlowType;
use Symfony\Component\Form\Flow\Type\FinishFlowType;
use Symfony\Component\Form\Flow\Type\NextFlowType;
use Symfony\Component\Form\Flow\Type\PreviousFlowType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ExplorerAssistantFlowType extends AbstractFlowType
{
    #[\Override]
    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        $draft = $builder->getData();
        if (!$draft instanceof ExplorerAssistantDraft) {
            $draft = new ExplorerAssistantDraft();
        }
        $locale = $options['locale'];
        if (!\is_string($locale) || '' === $locale) {
            $locale = 'en';
        }

        $skipForHospitals = static fn (mixed $data): bool => $data instanceof ExplorerAssistantDraft && AnalysisDataSourceKey::Hospitals === $data->dataSource;

        $builder
            ->addStep('goal', ExplorerAssistantGoalType::class, [
                'inherit_data' => true,
                'label' => false,
            ])
            ->addStep('source', ExplorerAssistantSourceType::class, [
                'inherit_data' => true,
                'label' => false,
            ])
            ->addStep('context', ExplorerAssistantContextType::class, [
                'inherit_data' => true,
                'label' => false,
                'locale' => $locale,
            ], $skipForHospitals)
            ->addStep('questions', ExplorerAssistantQuestionsType::class, [
                'inherit_data' => true,
                'label' => false,
                'draft' => $draft,
                'locale' => $locale,
            ])
            ->addStep('filters', ExplorerAssistantFiltersType::class, [
                'inherit_data' => true,
                'label' => false,
                'draft' => $draft,
            ], $skipForHospitals)
            ->addStep('summary', ExplorerAssistantSummaryType::class, [
                'inherit_data' => true,
                'label' => false,
                'draft' => $draft,
            ])
            ->add('previous', PreviousFlowType::class, [
                'label' => 'stats.analysis_explorer.assistant.back',
                'attr' => [
                    'class' => 'btn',
                    'data-testid' => 'stats-analysis-explorer-assistant-back',
                ],
            ])
            ->add('next', NextFlowType::class, [
                'label' => 'stats.analysis_explorer.assistant.next',
                'include_if' => static fn (FormFlowCursor $cursor): bool => $cursor->canMoveNext() && !\in_array($cursor->getCurrentStep(), ['goal', 'source'], true),
                'attr' => [
                    'class' => 'btn btn-primary',
                    'data-testid' => 'stats-analysis-explorer-assistant-next',
                ],
            ])
            ->add('finish', FinishFlowType::class, [
                'label' => 'stats.analysis_explorer.assistant.open',
                'attr' => [
                    'class' => 'btn btn-primary',
                    'data-testid' => 'stats-analysis-explorer-assistant-open',
                ],
            ])
        ;

        foreach (['goal', 'source', 'context', 'questions', 'filters'] as $step) {
            $builder->add('jump_'.$step, ButtonFlowType::class, [
                'label' => 'stats.analysis_explorer.assistant.step_name.'.$step,
                'validation_groups' => false,
                'include_if' => static function (FormFlowCursor $cursor) use ($step, $draft): bool {
                    if (\in_array($step, ['context', 'filters'], true) && AnalysisDataSourceKey::Hospitals === $draft->dataSource) {
                        return false;
                    }

                    $target = array_search($step, $cursor->getSteps(), true);

                    return \is_int($target) && $target < $cursor->getStepIndex();
                },
                'attr' => [
                    'class' => 'btn btn-link p-0 text-reset text-decoration-none',
                    'data-testid' => 'stats-analysis-explorer-assistant-jump-'.$step,
                ],
                'handler' => static function (mixed $data, ButtonFlowInterface $button, FormFlowInterface $flow) use ($step): void {
                    $flow->movePrevious($step);
                },
            ]);
        }

        $builder
            ->add('refresh', ButtonFlowType::class, [
                'label' => 'stats.analysis_explorer.assistant.refresh',
                'validation_groups' => false,
                'attr' => [
                    'class' => 'd-none',
                    'tabindex' => '-1',
                    'formnovalidate' => 'formnovalidate',
                    'aria-hidden' => 'true',
                    'value' => 'refresh',
                    'data-assistant-context-target' => 'refresh',
                ],
                'handler' => static function (mixed $data, ButtonFlowInterface $button, FormFlowInterface $flow): void {
                    $flow->getConfig()->getDataStorage()->save($data);
                },
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExplorerAssistantDraft::class,
            'step_property_path' => 'currentStep',
            'translation_domain' => 'statistics',
            'locale' => 'en',
        ]);
        $resolver->setAllowedTypes('locale', 'string');
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'explorer_assistant';
    }
}
