<?php

declare(strict_types=1);

namespace App\User\Infrastructure\AdministrativeUser;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\AdministrativeUserValidationException;
use App\User\Application\AdministrativeUser\CreateUserInput;
use App\User\Application\AdministrativeUser\IdentityTakenException;
use App\User\Application\AdministrativeUser\LastAdministratorException;
use App\User\Application\AdministrativeUser\UserNotDeletableException;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Domain\Validator\UserPasswordConstraints;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\Infrastructure\Registration\RegistrationIdentityGuard;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for AdministrativeUserService. */
#[AsAlias(AdministrativeUserService::class)]
final readonly class DoctrineAdministrativeUserService implements AdministrativeUserService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private RegistrationIdentityGuard $registrationIdentityGuard,
        private AuditContext $auditContext,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[\Override]
    public function create(CreateUserInput $input): User
    {
        $user = new User()
            ->setUsername($input->username)
            ->setEmail($input->email)
            ->setIsVerified(true)
            ->setIsEnabled(true)
            ->setCredentialsExpired(false);

        $username = $user->getUsername() ?? '';
        $email = $user->getEmail() ?? '';

        $violations = [
            ...$this->messages($this->validator->validate($username, UserUsernameConstraints::forUsername())),
            ...$this->messages($this->validator->validate($email, [new NotBlank(), new Email()])),
            ...$this->messages($this->validator->validate($input->plainPassword, UserPasswordConstraints::forPlainPassword())),
        ];
        if ([] !== $violations) {
            throw new AdministrativeUserValidationException($violations);
        }

        if ($this->isIdentityTaken($username, $email)) {
            throw new IdentityTakenException();
        }

        $roles = [];
        if ($input->grantAdmin) {
            $roles[] = UserRole::ADMIN;
        }
        $user->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->plainPassword));

        $this->entityManager->persist($user);
        $this->auditContext->beginIntent('user.cli.created', ['source' => 'cli']);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();

            throw new IdentityTakenException();
        } finally {
            $this->auditContext->endIntent();
        }

        $userId = $user->getId();
        if (null === $userId) {
            throw new \LogicException('Persisted user has no id.');
        }

        $this->eventDispatcher->dispatch(new UserRegistered($userId));

        return $user;
    }

    #[\Override]
    public function isIdentityTaken(string $username, string $email): bool
    {
        return $this->registrationIdentityGuard->isIdentityTaken($username, $email);
    }

    #[\Override]
    public function findByUsername(string $username): ?User
    {
        $normalized = UserUsernameConstraints::trim($username);
        if ('' === $normalized) {
            return null;
        }

        $user = $this->userRepository->findOneBy(['username' => $normalized]);

        return $user instanceof User ? $user : null;
    }

    #[\Override]
    public function countAdministrators(): int
    {
        return $this->userRepository->countUsersWithRole(UserRole::ADMIN);
    }

    #[\Override]
    public function promoteToAdmin(User $user): void
    {
        if ($this->hasAdminRole($user)) {
            return;
        }

        $roles = $user->getRoles();
        $roles[] = UserRole::ADMIN;
        $user->setRoles(array_values(array_unique($roles)));

        $this->auditContext->beginIntent('user.cli.promoted_admin', ['source' => 'cli']);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    #[\Override]
    public function demoteFromAdmin(User $user): void
    {
        if (!$this->hasAdminRole($user)) {
            return;
        }

        if (1 === $this->countAdministrators()) {
            throw new LastAdministratorException();
        }

        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => UserRole::ADMIN !== $role,
        ));
        $user->setRoles($roles);

        $this->auditContext->beginIntent('user.cli.demoted_admin', ['source' => 'cli']);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    #[\Override]
    public function delete(User $user): void
    {
        if ($this->hasAdminRole($user) && 1 === $this->countAdministrators()) {
            throw UserNotDeletableException::lastAdministrator();
        }

        if (!$user->getHospitals()->isEmpty()) {
            throw UserNotDeletableException::ownsHospitals();
        }

        $this->auditContext->beginIntent('user.cli.deleted', [
            'source' => 'cli',
            'username' => $user->getUsername(),
        ]);
        try {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException) {
            $this->entityManager->clear();

            throw UserNotDeletableException::referenced();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    private function hasAdminRole(User $user): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }

    /**
     * @return list<string>
     */
    private function messages(ConstraintViolationListInterface $violations): array
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
