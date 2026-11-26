<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Twig;

use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use App\User\UI\Twig\SettingsNavigationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The sub-items of "Sign-in & security" (#1508) follow what the user can use: no sign-in link for administrators, no
 * two-factor authentication where it is not available.
 */
final class SettingsNavigationExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'user, two-factor available' => [false, true, ['password', 'login_link', 'two_factor']];
        yield 'user, two-factor unavailable' => [false, false, ['password', 'login_link']];
        yield 'administrator, two-factor available' => [true, true, ['password', 'two_factor']];
        yield 'administrator, two-factor unavailable' => [true, false, ['password']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testSecuritySubItemsFollowRoleAndAvailability(bool $admin, bool $twoFactorAvailable, array $expected): void
    {
        $extension = new SettingsNavigationExtension(
            new TwoFactorAvailability(new TwoFactorSecretCipher($twoFactorAvailable ? str_repeat('a', 64) : ''), false),
            $this->authorization($admin),
        );

        $sections = $extension->sections();
        $security = array_values(array_filter($sections, static fn (array $section): bool => 'security' === $section['key']))[0];

        self::assertSame(['account', 'email', 'security', 'notifications'], array_column($sections, 'key'));
        self::assertSame($expected, array_column($security['children'], 'key'));
        self::assertSame([], $sections[0]['children']);
    }

    private function authorization(bool $admin): AuthorizationCheckerInterface
    {
        return new readonly class($admin) implements AuthorizationCheckerInterface {
            public function __construct(private bool $admin)
            {
            }

            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return UserRole::ADMIN === $attribute && $this->admin;
            }
        };
    }
}
