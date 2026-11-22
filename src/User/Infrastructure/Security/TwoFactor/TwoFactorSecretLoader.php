<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decrypts the TOTP secret after a user is loaded (#1357). A secret that cannot be decrypted stays null, so codes fail
 * (`User::getTotpAuthenticationConfiguration()`) and only backup codes work.
 *
 * @psalm-suppress UnusedClass
 */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: User::class)]
final readonly class TwoFactorSecretLoader
{
    public function __construct(
        private TwoFactorSecretCipher $cipher,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
    ) {
    }

    public function postLoad(User $user): void
    {
        $encrypted = $user->getTotpSecretEncrypted();
        if (null === $encrypted) {
            return;
        }

        $secret = $this->cipher->decrypt($encrypted);
        if (null === $secret) {
            $this->logger->error('security.two_factor.secret_undecryptable', ['user_id' => $user->getId()]);
        }

        $user->revealTotpSecret($secret);
    }
}
