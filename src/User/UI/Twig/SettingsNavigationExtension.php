<?php

declare(strict_types=1);

namespace App\User\UI\Twig;

use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The areas of the user settings, in display order. The overview tiles and the section
 * navigation of the single settings pages both render this list, so they cannot drift apart.
 * Notifications belong to the Notification context, which renders its page in this layout.
 * "Sign-in & security" (#1508) groups the password, the login link and two-factor authentication: its own page shows
 * their status, the navigation lists them below it. The login link is not offered to administrators, two-factor
 * authentication only where it can be set up (#1357).
 */
final readonly class SettingsNavigationExtension
{
    /**
     * @var list<array{key: string, route: string, icon: string, labelKey: string, descriptionKey: string, children?: list<array{key: string, route: string, icon: string, labelKey: string, descriptionKey: string}>}>
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
            'key' => 'security',
            'route' => 'app_settings_security',
            'icon' => 'tabler:shield-lock',
            'labelKey' => 'title.settings.security',
            'descriptionKey' => 'text.settings.section.security',
            'children' => [
                [
                    'key' => 'password',
                    'route' => 'app_settings_password',
                    'icon' => 'tabler:lock',
                    'labelKey' => 'label.settings.set_new_password',
                    'descriptionKey' => 'text.settings.section.password',
                ],
                [
                    'key' => 'login_link',
                    'route' => 'app_settings_login_link_show',
                    'icon' => 'tabler:mail-check',
                    'labelKey' => 'label.settings.login_link',
                    'descriptionKey' => 'text.settings.section.login_link',
                ],
                [
                    'key' => 'two_factor',
                    'route' => 'app_settings_two_factor',
                    'icon' => 'tabler:shield-check',
                    'labelKey' => 'title.settings.two_factor',
                    'descriptionKey' => 'text.settings.section.two_factor',
                ],
            ],
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
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Translation keys are in the `user` domain. Sub-items the current user cannot use are left out.
     *
     * @return list<array{key: string, route: string, icon: string, labelKey: string, descriptionKey: string, children: list<array{key: string, route: string, icon: string, labelKey: string, descriptionKey: string}>}>
     */
    #[AsTwigFunction(name: 'settings_sections')]
    public function sections(): array
    {
        $hidden = [];
        if (!$this->twoFactorAvailability->isAvailable()) {
            $hidden[] = 'two_factor';
        }
        if ($this->authorizationChecker->isGranted(UserRole::ADMIN)) {
            $hidden[] = 'login_link';
        }

        $sections = [];
        foreach (self::SECTIONS as $section) {
            $children = [];
            foreach ($section['children'] ?? [] as $child) {
                if (!\in_array($child['key'], $hidden, true)) {
                    $children[] = $child;
                }
            }

            $sections[] = [
                'key' => $section['key'],
                'route' => $section['route'],
                'icon' => $section['icon'],
                'labelKey' => $section['labelKey'],
                'descriptionKey' => $section['descriptionKey'],
                'children' => $children,
            ];
        }

        return $sections;
    }

    /** Whether users can set up two-factor authentication (#1357); hides the hints that point to it otherwise. */
    #[AsTwigFunction(name: 'two_factor_available')]
    public function twoFactorAvailable(): bool
    {
        return $this->twoFactorAvailability->isAvailable();
    }
}
