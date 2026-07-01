<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class SettingsNotificationsType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('receivesMonthlySubmissionReminder', CheckboxType::class, [
            'required' => false,
            'label' => 'label.settings.notifications.monthly_reminder',
            'help' => 'help.settings.notifications.monthly_reminder',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'user',
        ]);
    }
}
