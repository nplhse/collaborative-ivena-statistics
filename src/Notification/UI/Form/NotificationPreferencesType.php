<?php

declare(strict_types=1);

namespace App\Notification\UI\Form;

use App\Notification\Application\Preference\NotificationPreferenceMatrix;
use App\User\UI\Form\SettingsNotificationsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings page "Notifications": one checkbox per configurable control (ADR 016) and, until the
 * monthly reminder is migrated, its opt-out from the User context. Mandatory and fixed channels
 * are rendered as disabled checkboxes outside the form, so they are never submitted.
 *
 * Data: ['preferences' => array<field name, bool>, 'reminder' => array<string, mixed>].
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class NotificationPreferencesType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var NotificationPreferenceMatrix $matrix */
        $matrix = $options['matrix'];

        $preferences = $builder->create('preferences', FormType::class, ['label' => false]);
        foreach ($matrix->configurableControls() as $control) {
            $preferences->add($control->fieldName(), CheckboxType::class, [
                'label' => false,
                'required' => false,
            ]);
        }

        $builder->add($preferences);
        $builder->add('reminder', SettingsNotificationsType::class, ['label' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('matrix');
        $resolver->setAllowedTypes('matrix', NotificationPreferenceMatrix::class);
        $resolver->setDefaults([
            'translation_domain' => 'notification',
        ]);
    }
}
