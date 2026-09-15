<?php

declare(strict_types=1);

namespace App\User\UI\Console\Command;

use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\AdministrativeUserValidationException;
use App\User\Application\AdministrativeUser\CreateUserInput;
use App\User\Application\AdministrativeUser\IdentityTakenException;
use App\User\Domain\Validator\UserPasswordConstraints;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\UI\Console\ConsoleConstraintViolations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create a user interactively from the CLI (enabled and email-verified).',
)]
final readonly class CreateUserCommand
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

        $io->title('Create user');

        $username = $this->askValidated(
            $io,
            'Username',
            static fn (string $value): string => UserUsernameConstraints::trim($value),
            UserUsernameConstraints::forUsername(),
        );
        $email = $this->askValidated(
            $io,
            'Email address',
            static fn (string $value): string => mb_strtolower(trim($value)),
            [new NotBlank(), new Email()],
        );

        if ($this->administrativeUsers->isIdentityTaken($username, $email)) {
            $io->error(IdentityTakenException::MESSAGE);

            return Command::FAILURE;
        }

        $plainPassword = $this->askPassword($io);
        $grantAdmin = $io->confirm('Grant administrator privileges?', false);

        try {
            $user = $this->administrativeUsers->create(new CreateUserInput(
                username: $username,
                email: $email,
                plainPassword: $plainPassword,
                grantAdmin: $grantAdmin,
            ));
        } catch (IdentityTakenException|AdministrativeUserValidationException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Created user "%s" <%s> (admin: %s).',
            $user->getUserIdentifier(),
            $user->getEmail() ?? '',
            $grantAdmin ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }

    /**
     * @param callable(string): string $normalize
     * @param list<Constraint>         $constraints
     */
    private function askValidated(SymfonyStyle $io, string $question, callable $normalize, array $constraints): string
    {
        $answer = $io->ask($question, null, function (?string $answer) use ($normalize, $constraints): string {
            $value = $normalize($answer ?? '');
            $violations = $this->validator->validate($value, $constraints);
            if (0 < $violations->count()) {
                throw new \InvalidArgumentException(ConsoleConstraintViolations::firstMessage($violations));
            }

            return $value;
        });
        if (!\is_string($answer)) {
            throw new \LogicException('Expected a string answer.');
        }

        return $answer;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        while (true) {
            $password = $io->askHidden('Password', function (?string $answer): string {
                $plain = $answer ?? '';
                $violations = $this->validator->validate($plain, UserPasswordConstraints::forPlainPassword());
                if (0 < $violations->count()) {
                    throw new \InvalidArgumentException(ConsoleConstraintViolations::firstMessage($violations));
                }

                return $plain;
            });
            if (!\is_string($password)) {
                throw new \LogicException('Expected a string password.');
            }

            $confirm = $io->askHidden('Confirm password');
            if ($password === $confirm) {
                return $password;
            }

            $io->error('The password and confirmation do not match.');
        }
    }
}
