<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Acceptance of a new version of the terms of use at login (#1564).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class TermsAcceptanceType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('accept', CheckboxType::class, [
            'mapped' => false,
            'label' => 'label.terms.accept',
            'constraints' => [
                new IsTrue(message: 'validation.terms.accept_required'),
            ],
        ]);

        // Upload authority confirmed under an earlier version (#1565): renewed here, optional.
        /** @var list<string> $hospitalNames */
        $hospitalNames = $options['upload_authority_hospitals'];
        if ([] !== $hospitalNames) {
            $builder->add('uploadAuthority', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'label.terms.upload_authority',
                'label_translation_parameters' => ['hospitals' => implode(', ', $hospitalNames)],
                'help' => 'help.terms.upload_authority',
            ]);
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'user',
            'data_class' => null,
            'upload_authority_hospitals' => [],
        ]);
        $resolver->setAllowedTypes('upload_authority_hospitals', 'array');
    }
}
