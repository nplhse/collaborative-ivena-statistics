<?php

declare(strict_types=1);

namespace App\User\UI\Console\Command;

use App\User\Domain\Entity\User;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\Infrastructure\Repository\UserRepository;
use App\User\UI\Console\Input\NormalizeUsernamesInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:normalize-usernames',
    description: 'Normalize usernames that violate the canonical character policy (default: dry-run preview).',
)]
final readonly class NormalizeUsernamesCommand
{
    public const string STATUS_RENAMED = 'renamed';

    public const string STATUS_WOULD_RENAME = 'would_rename';

    public const string STATUS_COLLISION = 'collision';

    public const string STATUS_STILL_INVALID = 'still_invalid';

    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] NormalizeUsernamesInput $input,
    ): int {
        $this->entityManager->clear();

        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }
        $dryRun = !$apply;

        $io->title('Normalize usernames to the canonical character policy');

        if ($dryRun) {
            $io->note('Dry run: no rows will be written. Re-run with --apply to persist changes.');
        } else {
            $io->warning('Apply mode: usernames will be updated where a unique, valid replacement exists.');
        }

        $users = $this->userRepository->findBy([], ['id' => 'ASC']);
        $claimedUsernames = [];
        foreach ($users as $user) {
            $userId = $user->getId();
            $username = $user->getUsername();
            if (null === $userId || null === $username) {
                continue;
            }

            $claimedUsernames[$username] = $userId;
        }

        $inspected = 0;
        $alreadyValid = 0;
        $renamed = 0;
        $collisions = 0;
        $stillInvalid = 0;
        /** @var list<array{id: int, current: string, proposed: string, status: string}> $rows */
        $rows = [];
        /** @var array<int, string> $updates */
        $updates = [];

        foreach ($users as $user) {
            $userId = $user->getId();
            $current = $user->getUsername();
            if (null === $userId || null === $current) {
                continue;
            }

            ++$inspected;

            if (UserUsernameConstraints::isValid($current)) {
                ++$alreadyValid;

                continue;
            }

            $proposed = UserUsernameConstraints::proposeNormalized($current);
            if (!UserUsernameConstraints::isValid($proposed)) {
                ++$stillInvalid;
                $rows[] = [
                    'id' => $userId,
                    'current' => $current,
                    'proposed' => $proposed,
                    'status' => self::STATUS_STILL_INVALID,
                ];

                continue;
            }

            $claimedBy = $claimedUsernames[$proposed] ?? null;
            if (null !== $claimedBy && $claimedBy !== $userId) {
                ++$collisions;
                $rows[] = [
                    'id' => $userId,
                    'current' => $current,
                    'proposed' => $proposed,
                    'status' => self::STATUS_COLLISION,
                ];

                continue;
            }

            ++$renamed;
            $rows[] = [
                'id' => $userId,
                'current' => $current,
                'proposed' => $proposed,
                'status' => $dryRun ? self::STATUS_WOULD_RENAME : self::STATUS_RENAMED,
            ];
            unset($claimedUsernames[$current]);
            $claimedUsernames[$proposed] = $userId;
            $updates[$userId] = $proposed;
        }

        if ([] !== $rows) {
            $io->table(
                ['id', 'alt', 'neu', 'status'],
                array_map(
                    static fn (array $row): array => [
                        (string) $row['id'],
                        $row['current'],
                        $row['proposed'],
                        $row['status'],
                    ],
                    $rows,
                ),
            );
        }

        if (!$dryRun && [] !== $updates) {
            $this->applyUpdates($users, $updates);
        }

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Users inspected', (string) $inspected],
                ['Already valid', (string) $alreadyValid],
                [$dryRun ? 'Would rename' : 'Renamed', (string) $renamed],
                ['Collision', (string) $collisions],
                ['Still invalid', (string) $stillInvalid],
            ],
        );

        if ($dryRun) {
            $io->success('Dry run finished. Re-run with --apply to persist changes.');
        } else {
            $io->success(sprintf('Updated %d username(s).', $renamed));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<User>         $users
     * @param array<int, string> $updates
     */
    private function applyUpdates(array $users, array $updates): void
    {
        $usersById = [];
        foreach ($users as $user) {
            $userId = $user->getId();
            if (null === $userId) {
                continue;
            }

            $usersById[$userId] = $user;
        }

        foreach ($updates as $userId => $username) {
            $user = $usersById[$userId] ?? null;
            if (!$user instanceof User) {
                continue;
            }

            $user->setUsername($username);
        }

        $this->entityManager->flush();
    }
}
