<?php

declare(strict_types=1);

namespace App\Feedback\UI\Form;

use App\Feedback\Domain\Enum\FeedbackCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class FeedbackSubmitFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $guestRequired = true === ($options['guest_email_required'] ?? false);

        $builder
            ->add('_redirect_target', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => '/',
            ])
            ->add('_source_route', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => '',
            ])
            ->add('_source_route_params', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => '{}',
            ])
            ->add('category', EnumType::class, [
                'class' => FeedbackCategory::class,
                'choices' => [
                    FeedbackCategory::BUG,
                    FeedbackCategory::IMPROVEMENT,
                    FeedbackCategory::QUESTION,
                    FeedbackCategory::OTHER,
                ],
                'choice_label' => fn (FeedbackCategory $c): string => match ($c) {
                    FeedbackCategory::BUG => 'feedback.category.bug',
                    FeedbackCategory::IMPROVEMENT => 'feedback.category.improvement',
                    FeedbackCategory::QUESTION => 'feedback.category.question',
                    FeedbackCategory::OTHER => 'feedback.category.other',
                },
                'choice_translation_domain' => 'messages',
                'label' => 'feedback.form.category',
                'constraints' => [new Assert\NotNull()],
                'required' => true,
            ])
            ->add('message', TextareaType::class, [
                'label' => 'feedback.form.message',
                'attr' => ['rows' => 5],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 3, max: 5000),
                ],
                'required' => true,
            ])
            ->add('extraContext', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => '',
            ]);

        if ($guestRequired) {
            $builder->add('guestEmail', EmailType::class, [
                'label' => 'feedback.form.guest_email',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Email(),
                ],
            ]);
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'guest_email_required' => true,
            'csrf_token_id' => 'feedback_submit',
        ]);
        $resolver->setAllowedTypes('guest_email_required', 'bool');
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'feedback_submit';
    }
}
