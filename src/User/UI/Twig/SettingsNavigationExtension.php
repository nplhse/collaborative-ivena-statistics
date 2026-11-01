<?php

declare(strict_types=1);

namespace App\User\UI\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * The areas of the user settings, in display order. The overview tiles and the section
 * navigation of the single settings pages both render this list, so they cannot drift apart.
 * Notifications belong to the Notification context, which renders its page in this layout.
 */
final readonly class SettingsNavigationExtension
{
    /**
     * @var list<array{key: string, route: string, icon: string, labelKey: string, descriptionKey: string}>
     */
    private const array SECTIONS = [
        [
            'key' => 'account',
            'route' => 'app_settings_account',
            'icon' => 'tabler:user-circle',
            'labelKey' => 'label.settings.my_account',
            'descriptionKey' => 'text.settings.section.account',
        ],
        [
            'key' => 'email',
            'route' => 'app_settings_email',
            'icon' => 'tabler:mail',
            'labelKey' => 'label.settings.change_email',
            'descriptionKey' => 'text.settings.section.email',
        ],
        [
            'key' => 'password',
            'route' => 'app_settings_password',
            'icon' => 'tabler:lock',
            'labelKey' => 'label.settings.set_new_password',
            'descriptionKey' => 'text.settings.section.password',
        ],
        [
            'key' => 'notifications',
            'route' => 'app_settings_notifications',
            'icon' => 'tabler:bell',
            'labelKey' => 'label.settings.notifications',
            'descriptionKey' => 'text.settings.section.notifications',
        ],
    ];

    /**
     * Translation keys are in the `user` domain.
     *
     * @return list<array{key: string, route: string, icon: string, labelKey: string, descriptionKey: string}>
     */
    #[AsTwigFunction(name: 'settings_sections')]
    public function sections(): array
    {
        return self::SECTIONS;
    }
}
