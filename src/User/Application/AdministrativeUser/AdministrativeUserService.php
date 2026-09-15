<?php

declare(strict_types=1);

namespace App\User\Application\AdministrativeUser;

use App\User\Domain\Entity\User;

interface AdministrativeUserService
{
    /**
     * @throws AdministrativeUserValidationException
     * @throws IdentityTakenException
     */
    public function create(CreateUserInput $input): User;

    public function isIdentityTaken(string $username, string $email): bool;

    public function findByUsername(string $username): ?User;

    public function countAdministrators(): int;

    public function promoteToAdmin(User $user): void;

    /**
     * @throws LastAdministratorException
     */
    public function demoteFromAdmin(User $user): void;

    /**
     * @throws UserNotDeletableException
     */
    public function delete(User $user): void;
}
