<?php

declare(strict_types=1);

namespace App\User\UI\Console\Command;

use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\LastAdministratorException;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\UI\Console\ConsoleConstraintViolations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:demote-admin',
    description: 'Remove ROLE_ADMIN from an existing user identified by username.',
)]
final readonly class DemoteUserFromAdminCommand
{
    public function __construct(
        private AdministrativeUserService $administrativeUsers,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input): int
    {
        if (!$input->isInteractive()) {
            $io->error('This command must be run interactively.');

            return Command::FAILURE;
        }

        $io->title('Remove administrator privileges');

        $user = $this->askUser($io);
        if (!$user instanceof User) {
            return Command::FAILURE;
        }

        if (!\in_array(UserRole::ADMIN, $user->getRoles(), true)) {
            $io->warning(sprintf('User "%s" does not have administrator privileges.', $user->getUserIdentifier()));

            return Command::SUCCESS;
        }

        if (1 === $this->administrativeUsers->countAdministrators()) {
            $io->error(LastAdministratorException::MESSAGE);

            return Command::FAILURE;
        }

        $confirmed = $io->confirm(sprintf(
            'Remove administrator privileges from "%s" (%s)?',
            $user->getUserIdentifier(),
            $user->getEmail() ?? '—',
        ), false);
        if (!$confirmed) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        try {
            $this->administrativeUsers->demoteFromAdmin($user);
        } catch (LastAdministratorException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Removed ROLE_ADMIN from "%s".', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }

    private function askUser(SymfonyStyle $io): ?User
    {
        $username = $io->ask('Username', null, function (?string $answer): string {
            $value = UserUsernameConstraints::trim($answer ?? '');
            $violations = $this->validator->validate($value, UserUsernameConstraints::forUsername());
            if (0 < $violations->count()) {
                throw new \InvalidArgumentException(ConsoleConstraintViolations::firstMessage($violations));
            }

            return $value;
        });
        if (!\is_string($username)) {
            $io->error('No user found with the given username.');

            return null;
        }

        $user = $this->administrativeUsers->findByUsername($username);
        if ($user instanceof User) {
            return $user;
        }

        $io->error(sprintf('No user found with username "%s".', $username));

        return null;
    }
}
