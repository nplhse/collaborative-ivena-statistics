<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\AccountLock;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Sign-in refused because the account is locked (#1762). Shows the same message as a wrong password, so the form does
 * not reveal that the account exists; the owner learns about the lock by mail and in the app.
 */
final class AccountLockedException extends BadCredentialsException
{
}
