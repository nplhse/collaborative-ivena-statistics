<?php

declare(strict_types=1);

namespace App\User\UI\Console\Command;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorReset;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Last resort when an administrator lost the authenticator app and the backup codes and no other administrator can
 * reset it in the user list (#1357, ADR 040). Writes an audit entry and tells the user by mail.
 *
 * @psalm-suppress UnusedClass
 */
#[AsCommand(
    name: 'app:user:reset-two-factor',
    description: 'Switch off two-factor authentication of a user identified by username.',
)]
final readonly class ResetTwoFactorCommand
{
    public function __construct(
        private UserRepository $userRepository,
        private TwoFactorReset $twoFactorReset,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Username of the account')]
        string $username,
    ): int {
        $user = $this->userRepository->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            $io->error(\sprintf('No user "%s".', $username));

            return Command::FAILURE;
        }

        if (!$user->isTotpAuthenticationEnabled()) {
            $io->note(\sprintf('User "%s" has no two-factor authentication.', $username));

            return Command::SUCCESS;
        }

        if (!$io->confirm(\sprintf('Switch off two-factor authentication of "%s"? The user gets an email about it.', $username), false)) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        $this->twoFactorReset->reset($user, TwoFactorReset::INTENT_CONSOLE);
        $io->success(\sprintf('Two-factor authentication of "%s" is switched off.', $username));

        return Command::SUCCESS;
    }
}
