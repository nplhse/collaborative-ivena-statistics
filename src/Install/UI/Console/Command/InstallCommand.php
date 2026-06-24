<?php

declare(strict_types=1);

namespace App\Install\UI\Console\Command;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:install',
    description: 'Run one-time server bootstrap (initial admin user and future install steps).',
)]
final readonly class InstallCommand
{
    private const string BOOTSTRAP_ADMIN_USERNAME = 'admin';

    private const string BOOTSTRAP_ADMIN_EMAIL = 'admin@test.local';

    private const string BOOTSTRAP_ADMIN_PLAIN_PASSWORD = 'Ivena123';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // Add further one-time bootstrap steps here as private methods, in order.
        $this->ensureBootstrapAdmin($io);

        $io->success('Install finished.');

        return Command::SUCCESS;
    }

    private function ensureBootstrapAdmin(SymfonyStyle $io): void
    {
        $existing = $this->userRepository->findOneBy(['username' => self::BOOTSTRAP_ADMIN_USERNAME]);

        if ($existing instanceof User) {
            $io->comment(sprintf(
                'Bootstrap admin user "%s" already exists; skipping user creation.',
                self::BOOTSTRAP_ADMIN_USERNAME,
            ));

            return;
        }

        $user = new User();
        $user->setUsername(self::BOOTSTRAP_ADMIN_USERNAME);
        $user->setEmail(self::BOOTSTRAP_ADMIN_EMAIL);
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $user->setIsVerified(true);
        $user->setCredentialsExpired(false);
        $user->setIsEnabled(true);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, self::BOOTSTRAP_ADMIN_PLAIN_PASSWORD),
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->writeln(sprintf(
            '<info>Created bootstrap admin user "%s". Change the default password after first login.</info>',
            self::BOOTSTRAP_ADMIN_USERNAME,
        ));
    }
}
