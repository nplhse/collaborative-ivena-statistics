<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Registration;

use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Registration\RegistrationIdentityChecker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RegistrationIdentityCheckerTest extends KernelTestCase
{
    use Factories;

    public function testIsIdentityTakenWhenUsernameExists(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        self::assertTrue($checker->isIdentityTaken('alice', 'other@example.test'));
    }

    public function testIsIdentityTakenWhenEmailExistsNormalized(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        self::assertTrue($checker->isIdentityTaken('bob', '  Alice@Example.TEST '));
    }

    public function testIsIdentityTakenWhenNeitherExists(): void
    {
        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        self::assertFalse($checker->isIdentityTaken('bob', 'bob@example.test'));
    }

    public function testCreateIdentityTakenFormErrorMessage(): void
    {
        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);
        $error = $checker->createIdentityTakenFormError();

        self::assertSame('This username or email address is already taken.', $error->getMessage());
    }
}
