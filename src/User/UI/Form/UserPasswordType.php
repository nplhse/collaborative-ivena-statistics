<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use App\User\Domain\Validator\UserPasswordConstraints;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<string|null>
 */
final class UserPasswordType extends AbstractType
{
    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['strength_feedback'] = $options['strength_feedback'];

        if ($options['strength_feedback']) {
            $view->vars['password_strength_policy'] = UserPasswordConstraints::clientConfig();
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'strength_feedback' => true,
        ]);

        $resolver->setAllowedTypes('strength_feedback', 'bool');
    }

    #[\Override]
    public function getParent(): string
    {
        return PasswordType::class;
    }
}
