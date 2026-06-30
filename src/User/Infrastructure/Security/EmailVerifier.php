<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Mail\TransactionalMailer;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final readonly class EmailVerifier
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private TransactionalMailer $transactionalMailer,
        private UrlGeneratorInterface $urlGenerator,
        private LocaleResolver $localeResolver,
    ) {
    }

    public function sendEmailConfirmation(string $verifyRouteName, User $user): void
    {
        $email = $user->getEmail();
        if (null === $email || '' === $email) {
            throw new \LogicException('User must have an email before verification can be sent.');
        }

        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyRouteName,
            (string) $user->getId(),
            $email,
            ['id' => (string) $user->getId()]
        );

        $this->transactionalMailer->sendVerificationEmail(
            $email,
            $signatureComponents->getSignedUrl(),
            $signatureComponents->getExpirationMessageKey(),
            $signatureComponents->getExpirationMessageData(),
            $this->urlGenerator->generate('app_default', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->localeResolver->resolveForUser($user),
        );
    }

    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $email = $user->getEmail();
        if (null === $email || '' === $email) {
            throw new \LogicException('User must have an email before verification can be validated.');
        }

        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), $email);
    }
}
