<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<list<string>>
 */
final class ExplorerAdditionalTableMetricsType extends AbstractType
{
    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'explorer_metric_groups' => [],
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ]);

        $resolver->setAllowedTypes('explorer_metric_groups', 'array');
    }

    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['explorer_metric_groups'] = $options['explorer_metric_groups'];
    }

    #[\Override]
    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
