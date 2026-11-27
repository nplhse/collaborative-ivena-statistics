<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Registration;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Mail\TransactionalMailer;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tells the owner of an existing account that someone tried to register with its email address (#936).
 *
 * The registration form answers such an attempt like a new address, so it does not reveal whether the address has an
 * account. At most one mail per account within `registration_attempt_notice` keeps the form from being used to flood
 * the mailbox; further attempts are only logged.
 */
final readonly class ExistingAccountRegistrationNotifier
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private TransactionalMailer $transactionalMailer,
        private UrlGeneratorInterface $urlGenerator,
        private LocaleResolver $localeResolver,
        #[Autowire(service: 'limiter.registration_attempt_notice')]
        private RateLimiterFactoryInterface $noticeLimiter,
        #[Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
    ) {
    }

    public function notify(User $user): void
    {
        // Every stored account has an email (non-null column); an account without one would be a bug.
        $email = $user->getEmail() ?? throw new \LogicException('An existing account has no email address.');

        if (!$this->noticeLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            $this->logger->info('security.registration.existing_email_notice_limited', [
                'user_id' => $user->getId(),
            ]);

            return;
        }

        $this->transactionalMailer->sendRegistrationAttemptEmail(
            $email,
            $user->getUserIdentifier(),
            $this->urlGenerator->generate('app_forgot_password_request', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->localeResolver->resolveForUser($user),
        );
    }
}
