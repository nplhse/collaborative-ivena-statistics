<?php

declare(strict_types=1);

namespace App\Notification\UI\Form;

use App\Notification\Application\Preference\NotificationPreferenceControl;
use App\Notification\Application\Preference\NotificationPreferenceMatrix;
use App\Notification\Application\Preference\NotificationPreferenceState;
use App\User\UI\Form\SettingsNotificationsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Settings page "Notifications": one three-state select per configurable control (ADR 016) and,
 * until the monthly reminder is migrated, its opt-out from the User context.
 *
 * Data: ['preferences' => array<field name, NotificationPreferenceState>, 'reminder' => array<string, mixed>].
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
            $preferences->add($control->fieldName(), EnumType::class, [
                'class' => NotificationPreferenceState::class,
                'label' => false,
                'choice_label' => static fn (NotificationPreferenceState $state): TranslatableMessage => self::stateLabel($control, $state),
                'attr' => ['class' => 'form-select-sm'],
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

    private static function stateLabel(NotificationPreferenceControl $control, NotificationPreferenceState $state): TranslatableMessage
    {
        return match ($state) {
            NotificationPreferenceState::Default => new TranslatableMessage('notification.preferences.state.default', [
                'inherited' => match ($control->inherited) {
                    true => 'on',
                    false => 'off',
                    null => 'mixed',
                },
            ], 'notification'),
            NotificationPreferenceState::On => new TranslatableMessage('notification.preferences.state.on', [], 'notification'),
            NotificationPreferenceState::Off => new TranslatableMessage('notification.preferences.state.off', [], 'notification'),
        };
    }
}
