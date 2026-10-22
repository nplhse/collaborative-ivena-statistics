<?php

declare(strict_types=1);

namespace App\DataFixtures\Notification\Command;

use App\Notification\Application\Contract\NotificationHospitalAccessInterface;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates demo notifications for one user so the inbox can be tried locally (dev and test only,
 * like everything under src/DataFixtures). Goes through the real publisher.
 */
#[AsCommand(
    name: 'app:notification:demo',
    description: 'Create demo notifications for a user (dev only).',
)]
final readonly class NotificationDemoCommand
{
    public function __construct(
        private UserRepository $userRepository,
        private NotificationPublisherInterface $publisher,
        private NotificationHospitalAccessInterface $hospitalAccess,
        private Connection $connection,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Username or email of the recipient')]
        string $user,
        #[Option(description: 'Number of normal entries to create')]
        int $count = 3,
        #[Option(description: 'Delete all notifications of the user before creating new ones')]
        bool $clear = false,
    ): int {
        $recipient = $this->userRepository->findOneBy(['username' => trim($user)])
            ?? $this->userRepository->findOneBy(['email' => mb_strtolower(trim($user))]);
        $recipientId = $recipient instanceof User ? $recipient->getId() : null;
        if (!$recipient instanceof User || null === $recipientId) {
            $io->error(sprintf('No user found for "%s".', $user));

            return Command::FAILURE;
        }

        if ($count < 0) {
            $io->error('--count must not be negative.');

            return Command::INVALID;
        }

        if ($clear) {
            $this->connection->executeStatement('DELETE FROM notification WHERE recipient_id = ?', [$recipientId]);
            $this->connection->executeStatement('DELETE FROM notification_deduplication WHERE recipient_id = ?', [$recipientId]);
            $this->connection->executeStatement('DELETE FROM notification_recipient_state WHERE user_id = ?', [$recipientId]);
            $io->writeln('Removed existing notifications.');
        }

        $run = bin2hex(random_bytes(4));

        for ($i = 1; $i <= $count; ++$i) {
            $this->publisher->publish(new NotificationRequest(
                type: 'demo.import_finished',
                recipientUserIds: [$recipientId],
                deduplicationKey: sprintf('demo.import_finished:%s:%d', $run, $i),
                parameters: ['importName' => sprintf('Demo import %d', $i), 'rows' => 100 * $i],
                action: new NotificationAction('app_import_index'),
            ));
        }

        $notes = [sprintf('%d normal entries with a link to the import list', $count)];

        $hospital = $this->inaccessibleHospital($recipient);
        if (null !== $hospital) {
            $this->publisher->publish(new NotificationRequest(
                type: 'demo.hospital',
                recipientUserIds: [$recipientId],
                deduplicationKey: sprintf('demo.hospital:%s', $run),
                parameters: ['hospitalName' => $hospital['name']],
                action: new NotificationAction('app_hospitals_index'),
                scopeReferenceId: $hospital['id'],
            ));
            $notes[] = sprintf('1 entry about "%s", shown redacted because the user has no access to it', $hospital['name']);
        } else {
            $notes[] = 'no redacted entry: the user can access every hospital';
        }

        $this->publisher->publish(new NotificationRequest(
            type: 'demo.admin_only',
            recipientUserIds: [$recipientId],
            deduplicationKey: sprintf('demo.admin_only:%s', $run),
            action: new NotificationAction('app_admin_dashboard'),
        ));
        $notes[] = \in_array('ROLE_ADMIN', $recipient->getRoles(), true)
            ? '1 admin-only entry, visible because the user is an admin'
            : '1 admin-only entry, hidden because the user is not an admin';

        $io->success(sprintf('Created demo notifications for %s.', $recipient->getUserIdentifier()));
        $io->listing($notes);

        return Command::SUCCESS;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function inaccessibleHospital(User $user): ?array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, name FROM hospital ORDER BY id LIMIT 200');
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $accessible = array_flip($this->hospitalAccess->filterAccessible($user, $ids));

        foreach ($rows as $row) {
            if (!isset($accessible[(int) $row['id']])) {
                return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }

        return null;
    }
}
