<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Form\StatisticsScopePeriodType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ExplorerAssistantDraft>
 */
final class ExplorerAssistantContextType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale = $options['locale'];
        if (!\is_string($locale) || '' === $locale) {
            $locale = 'en';
        }

        $builder->add('scopePeriod', StatisticsScopePeriodType::class, [
            'label' => false,
            'side' => StatisticsFilterSide::Primary,
            'locale' => $locale,
            'scope_choice_policy' => StatisticsFilterScopeChoicePolicy::AllocationStatistics,
        ]);

        // Back clears the current step. Keep the scope and period already stored on the draft.
        $builder->get('scopePeriod')->addEventListener(
            FormEvents::PRE_SUBMIT,
            static function (FormEvent $event): void {
                $submitted = $event->getData();
                if (\is_array($submitted) && [] !== $submitted) {
                    return;
                }

                $current = $event->getForm()->getData();
                if (!$current instanceof StatisticsScopePeriodFormData) {
                    return;
                }

                $restored = [
                    'scopeGroup' => $current->scopeGroup,
                    'period' => $current->period,
                ];
                if (null !== $current->scopeDetail && '' !== $current->scopeDetail) {
                    $restored['scopeDetail'] = $current->scopeDetail;
                }
                if (null !== $current->periodYear) {
                    $restored['periodYear'] = (string) $current->periodYear;
                }
                if (null !== $current->periodQuarter) {
                    $restored['periodQuarter'] = (string) $current->periodQuarter;
                }
                if (null !== $current->periodMonth) {
                    $restored['periodMonth'] = (string) $current->periodMonth;
                }

                $event->setData($restored);
            },
            100,
        );
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'locale' => 'en',
        ]);
        $resolver->setAllowedTypes('locale', 'string');
    }
}
