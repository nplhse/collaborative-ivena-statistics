<?php

declare(strict_types=1);

namespace App\Onboarding\UI\Console\Command;

use App\Onboarding\Application\OnboardingProgressService;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:onboarding:initialize',
    description: 'Initialize participant onboarding progress for existing users (idempotent).',
)]
final readonly class InitializeOnboardingCommand
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
        private OnboardingProgressService $onboardingProgressService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Show what would be created without persisting', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Only initialize a single user by ID', name: 'user-id')]
        ?int $userId = null,
    ): int {
        $users = $this->userRepository->findEnabledVerifiedUsersWithRoles([UserRole::PARTICIPANT]);

        if (null !== $userId) {
            $users = array_values(array_filter(
                $users,
                static fn (\App\User\Domain\Entity\User $user): bool => $user->getId() === $userId,
            ));

            if ([] === $users) {
                $io->error(sprintf('No enabled verified participant found with user id %d.', $userId));

                return Command::FAILURE;
            }
        }

        $totals = ['created' => 0, 'skipped' => 0, 'already' => 0];

        foreach ($users as $user) {
            $stats = $this->onboardingProgressService->syncContextCompletedSteps($user, $dryRun);
            $totals['created'] += $stats['created'];
            $totals['already'] += $stats['already'];
        }

        $mode = $dryRun ? ' (dry-run)' : '';
        $io->success(sprintf(
            'Onboarding initialization complete%s: %d created, %d already present.',
            $mode,
            $totals['created'],
            $totals['already'],
        ));

        return Command::SUCCESS;
    }
}
