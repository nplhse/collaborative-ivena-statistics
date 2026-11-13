<?php

declare(strict_types=1);

namespace App\Encounter\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Decision on one allocation of the match review (#1054): the chosen candidate visit and the action.
 * "Confirm" links the chosen visit (a change if the allocation is linked to another one); "reject" rejects
 * its stored pair. Leaving the allocation open is the "skip" link, not a submit.
 *
 * @extends AbstractType<array{encounter: ?int}>
 */
final class MatchReviewDecisionType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<int> $candidates */
        $candidates = $options['candidates'];

        $builder
            ->add('encounter', ChoiceType::class, [
                'choices' => array_combine(array_map(strval(...), $candidates), $candidates),
                'expanded' => true,
                'multiple' => false,
                'label' => false,
                'constraints' => [new NotBlank()],
            ])
            ->add('confirm', SubmitType::class, ['label' => 'encounter.review.action.confirm'])
            ->add('reject', SubmitType::class, ['label' => 'encounter.review.action.reject']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'encounter',
            'csrf_token_id' => 'encounter_match_review',
        ]);
        $resolver->setRequired('candidates');
        $resolver->setAllowedTypes('candidates', 'array');
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'match_review';
    }
}
