<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Condition\TwoFactorConditionInterface;

/**
 * `/login/confirm` (`ConfirmPasswordAuthenticator`) re-checks the password of a session that already passed the second
 * factor, so it does not ask for a code again (#1357, ADR 040).
 *
 * @psalm-suppress UnusedClass
 */
final readonly class SkipOnPasswordConfirmationCondition implements TwoFactorConditionInterface
{
    private const string CONFIRM_PASSWORD_ROUTE = 'app_confirm_password';

    #[\Override]
    public function shouldPerformTwoFactorAuthentication(AuthenticationContextInterface $context): bool
    {
        return self::CONFIRM_PASSWORD_ROUTE !== $context->getRequest()->attributes->get('_route');
    }
}
