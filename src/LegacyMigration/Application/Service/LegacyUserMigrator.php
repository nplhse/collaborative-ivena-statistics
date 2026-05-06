<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

final readonly class LegacyUserMigrator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $legacyConnection,
        private Connection $defaultConnection,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LegacyMigrationStateRepositoryInterface $stateRepository,
    ) {
    }

    public function migrate(bool $dryRun = false): int
    {
        $rows = $this->legacyConnection->fetchAllAssociative('SELECT id, username, email FROM user ORDER BY id ASC');
        $migrated = 0;
        $slugger = new AsciiSlugger();

        foreach ($rows as $row) {
            $legacyUserId = (int) $row['id'];
            $exists = (int) $this->defaultConnection->fetchOne(
                'SELECT COUNT(*) FROM legacy_migration_user_mapping WHERE legacy_user_id = :legacyId',
                ['legacyId' => $legacyUserId]
            );
            if ($exists > 0) {
                continue;
            }

            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            if ('' === $email) {
                throw new \RuntimeException(sprintf('Legacy user %d has no email.', $legacyUserId));
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if (!$user instanceof User && !$dryRun) {
                $usernameBase = (string) $slugger->slug((string) ($row['username'] ?? 'legacy-user-'.$legacyUserId))->lower();
                $usernameBase = '' === $usernameBase ? 'legacy-user' : $usernameBase;
                $username = $usernameBase;
                $suffix = 1;
                while ($this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]) instanceof User) {
                    ++$suffix;
                    $username = sprintf('%s-%d', $usernameBase, $suffix);
                }

                $user = new User()
                    ->setEmail($email)
                    ->setUsername($username)
                    ->setRoles(['ROLE_USER'])
                    ->setIsVerified(false)
                    ->setCredentialsExpired(true);

                $plain = bin2hex(random_bytes(16));
                $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }

            if ($dryRun) {
                ++$migrated;
                continue;
            }

            \assert($user instanceof User);
            $this->defaultConnection->insert('legacy_migration_user_mapping', [
                'legacy_user_id' => $legacyUserId,
                'new_user_id' => (int) $user->getId(),
                'legacy_email' => $email,
                'migrated_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ]);
            ++$migrated;
        }

        $this->stateRepository->log('users', 'info', 'users phase finished', null, ['migrated' => $migrated, 'dryRun' => $dryRun]);

        return $migrated;
    }
}
