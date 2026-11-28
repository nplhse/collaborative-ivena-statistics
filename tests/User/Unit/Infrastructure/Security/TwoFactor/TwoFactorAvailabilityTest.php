<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TwoFactorAvailability::class)]
final class TwoFactorAvailabilityTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function availability(): iterable
    {
        // key, demo mode => available
        yield 'valid key' => [self::KEY, false, true];
        yield 'no key' => ['', false, false];
        yield 'invalid key' => ['0011', false, false];
        // Demo accounts are shared (ADR 023), so nobody may bind them to a device.
        yield 'demo mode hides the feature' => [self::KEY, true, false];
    }

    #[DataProvider('availability')]
    public function testIsAvailableOnlyWithAValidKeyOutsideTheDemo(string $key, bool $demoMode, bool $available): void
    {
        $availability = new TwoFactorAvailability(new TwoFactorSecretCipher($key), $demoMode);

        $isAvailable = $availability->isAvailable();

        self::assertSame($available, $isAvailable);
    }

    /**
     * @return iterable<string, array{string, bool, bool, list<string>, bool}>
     */
    public static function obligation(): iterable
    {
        // key, demo mode, required for admins, roles => required
        yield 'administrator' => [self::KEY, false, true, [UserRole::USER, UserRole::ADMIN], true];
        yield 'regular user' => [self::KEY, false, true, [UserRole::USER], false];
        yield 'obligation switched off' => [self::KEY, false, false, [UserRole::USER, UserRole::ADMIN], false];
        yield 'never in the demo' => [self::KEY, true, true, [UserRole::USER, UserRole::ADMIN], false];
        yield 'never without a key' => ['', false, true, [UserRole::USER, UserRole::ADMIN], false];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('obligation')]
    public function testObligationAppliesToAdministratorsOnlyWhileTheFeatureIsAvailable(
        string $key,
        bool $demoMode,
        bool $requiredForAdmins,
        array $roles,
        bool $required,
    ): void {
        $availability = new TwoFactorAvailability(new TwoFactorSecretCipher($key), $demoMode, $requiredForAdmins);
        $user = new User()->setUsername('alice')->setRoles($roles);

        $isRequired = $availability->isRequiredFor($user);

        self::assertSame($required, $isRequired);
    }
}
