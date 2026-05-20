<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[\Override]
    #[DataProvider('getUserData')]
    public function load(ObjectManager $manager): void
    {
        foreach (UserFixtures::getUserData() as [$username, $password, $roles, $email, $isVerified]) {
            $user = new User();
            $user->setUsername($username);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setRoles($roles);
            $user->setEmail($email);
            $user->setIsVerified($isVerified);
            $user->setCredentialsExpired(false);
            $user->setIsEnabled(true);

            $manager->persist($user);

            $this->addReference($username, $user);
        }

        $manager->flush();
    }

    /**
     * @return array<array{string, string, list<string>, string, bool}>
     */
    public static function getUserData(): array
    {
        return [
            ['admin', 'password', ['ROLE_ADMIN', 'ROLE_FEEDBACK_RECIPIENT', 'ROLE_USER'], 'admin@test.local', true],
            ['foo', 'bar', ['ROLE_USER'], 'foo@bar.local', true],
        ];
    }
}
