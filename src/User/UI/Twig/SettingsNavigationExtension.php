<?php

declare(strict_types=1);

namespace App\User\UI\Twig;

use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use Twig\Attribute\AsTwigFunction;

/**
 * The areas of the user settings, in display order. The overview tiles and the section
 * navigation of the single settings pages both render this list, so they cannot drift apart.
 * Notifications belong to the Notification context, which renders its page in this layout.
 * Two-factor authentication is listed only where it can be set up (#1357).
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
            'key' => 'two_factor',
            'route' => 'app_settings_two_factor',
            'icon' => 'tabler:shield-lock',
            'labelKey' => 'title.settings.two_factor',
            'descriptionKey' => 'text.settings.section.two_factor',
        ],
        [
            'key' => 'notifications',
            'route' => 'app_settings_notifications',
            'icon' => 'tabler:bell',
            'labelKey' => 'label.settings.notifications',
            'descriptionKey' => 'text.settings.section.notifications',
        ],
    ];

    public function __construct(
        private TwoFactorAvailability $twoFactorAvailability,
    ) {
    }

    /**
     * Translation keys are in the `user` domain.
     *
     * @return list<array{key: string, route: string, icon: string, labelKey: string, descriptionKey: string}>
     */
    #[AsTwigFunction(name: 'settings_sections')]
    public function sections(): array
    {
        if ($this->twoFactorAvailability->isAvailable()) {
            return self::SECTIONS;
        }

        return array_values(array_filter(self::SECTIONS, static fn (array $section): bool => 'two_factor' !== $section['key']));
    }

    /** Whether users can set up two-factor authentication (#1357); hides the hints that point to it otherwise. */
    #[AsTwigFunction(name: 'two_factor_available')]
    public function twoFactorAvailable(): bool
    {
        return $this->twoFactorAvailability->isAvailable();
    }
}
