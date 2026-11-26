<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Repository;

use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/** Usernames are unique regardless of case (#1353, migration Version20261119155253). */
#[ResetDatabase]
final class UsernameCaseUniquenessTest extends KernelTestCase
{
    use Factories;

    public function testFindsTheUserWhateverTheCase(): void
    {
        $user = UserFactory::createOne(['username' => 'Ada.Lovelace']);

        $repository = self::getContainer()->get(UserRepository::class);

        self::assertSame($user->getId(), $repository->findOneByUsernameInsensitive('ada.lovelace')?->getId());
        self::assertSame($user->getId(), $repository->findOneByUsernameInsensitive('ADA.LOVELACE')?->getId());
        self::assertNull($repository->findOneByUsernameInsensitive('ada'));
    }

    public function testDatabaseRejectsAUsernameThatDiffersOnlyInCase(): void
    {
        UserFactory::createOne(['username' => 'Ada.Lovelace']);

        $this->expectException(UniqueConstraintViolationException::class);

        UserFactory::createOne(['username' => 'ada.lovelace']);
    }
}
