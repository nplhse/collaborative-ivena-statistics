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

    public function testIsIdentityTakenWhenUsernameExistsWithSurroundingWhitespace(): void
    {
        UserFactory::createOne([
            'username' => 'alice',
            'email' => 'alice@example.test',
        ]);

        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        self::assertTrue($checker->isIdentityTaken('  alice  ', 'other@example.test'));
    }

    public function testIsIdentityTakenWhenUsernameDiffersOnlyInCase(): void
    {
        UserFactory::createOne([
            'username' => 'Alice',
            'email' => 'alice@example.test',
        ]);

        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        self::assertTrue($checker->isIdentityTaken('aLiCe', 'other@example.test'), 'profile URLs ignore the case (#1353)');
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

    public function testIsUsernameTakenIgnoresTheEmail(): void
    {
        UserFactory::createOne([
            'username' => 'Alice',
            'email' => 'alice@example.test',
        ]);

        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        self::assertTrue($checker->isUsernameTaken('  aLiCe  '));
        // Self-registration must not learn whether an email has an account (#936).
        self::assertFalse($checker->isUsernameTaken('bob'));
        self::assertSame('This username is already taken.', $checker->createUsernameTakenFormError()->getMessage());
    }
}
