<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Registration;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Registration\RegistrationIdentityChecker;

final class RegistrationIdentityCheckerTest extends DatabaseKernelTestCase
{
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

    public function testUsernameIsTakenIgnoringCaseAndSurroundingWhitespace(): void
    {
        UserFactory::createOne([
            'username' => 'Alice',
            'email' => 'alice@example.test',
        ]);
        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        $taken = $checker->isUsernameTaken('  aLiCe  ');

        self::assertTrue($taken);
    }

    public function testFreeUsernameIsNotTaken(): void
    {
        UserFactory::createOne([
            'username' => 'Alice',
            'email' => 'bob@example.test',
        ]);
        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        // Only the username counts: self-registration must not learn whether an email has an account (#936).
        $taken = $checker->isUsernameTaken('bob');

        self::assertFalse($taken);
    }

    public function testUsernameTakenErrorIsTranslated(): void
    {
        $checker = self::getContainer()->get(RegistrationIdentityChecker::class);

        $error = $checker->createUsernameTakenFormError();

        self::assertSame('This username is already taken.', $error->getMessage());
    }
}
