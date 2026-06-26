<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form;

use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\UI\Form\Transformer\IndicationToIdTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class IndicationRawReviewType extends AbstractType
{
    public function __construct(
        private readonly IndicationNormalizedRepository $indicationNormalizedRepository,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', IntegerType::class, [
                'label' => 'label.indication.code',
                'disabled' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'label.indication.raw',
                'disabled' => true,
            ])
            ->add('target_label', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'label.indication.normalized',
                'attr' => [
                    'data-controller' => 'datalist-chooser',
                ],
            ])
            ->add('target', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-datalist-chooser-id-target' => '',
                ],
            ])
            ->add('reviewComment', TextareaType::class, [
                'required' => false,
                'label' => 'label.indication.review_comment',
            ])
            ->add('propose', SubmitType::class, [
                'label' => 'btn.indication.propose_match',
            ])
            ->add('matchAndApprove', SubmitType::class, [
                'label' => 'btn.indication.match_and_approve',
            ])
            ->add('approve', SubmitType::class, [
                'label' => 'btn.indication.approve_match',
            ])
            ->add('reject', SubmitType::class, [
                'label' => 'btn.indication.reject_match',
            ])
            ->add('notMatchable', SubmitType::class, [
                'label' => 'btn.indication.not_matchable',
            ])
            ->add('ignore', SubmitType::class, [
                'label' => 'btn.indication.ignore',
            ])
            ->add('saveComment', SubmitType::class, [
                'label' => 'btn.save.comment',
            ])
            ->add('reopen', SubmitType::class, [
                'label' => 'btn.indication.reopen',
            ])
        ;

        $builder->get('target')->addModelTransformer(
            new IndicationToIdTransformer($this->indicationNormalizedRepository)
        );
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'indication_raw_review';
    }
}
