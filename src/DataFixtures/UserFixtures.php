<?php

namespace App\DataFixtures;

use App\Entity\User;
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
        foreach (UserFixtures::getUserData() as [$username, $password, $roles]) {
            $user = new User();
            $user->setUsername($username);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setRoles($roles);

            $manager->persist($user);

            $this->addReference($username, $user);
        }

        $manager->flush();
    }

    /**
     * @return array<array{string, string, list<string>}>
     */
    public static function getUserData(): array
    {
        return [
            ['admin', 'password', ['ROLE_ADMIN', 'ROLE_USER']],
            ['foo', 'bar', ['ROLE_USER']],
        ];
    }
}
