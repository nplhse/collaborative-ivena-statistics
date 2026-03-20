<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerifier
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailerFrom,
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

        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Collaborative IVENA statistics'))
            ->to($email)
            ->subject('Bitte E-Mail-Adresse bestaetigen')
            ->htmlTemplate('@User/registration/confirmation_email.html.twig')
            ->context([
                'signedUrl' => $signatureComponents->getSignedUrl(),
                'expiresAtMessageKey' => $signatureComponents->getExpirationMessageKey(),
                'expiresAtMessageData' => $signatureComponents->getExpirationMessageData(),
                'homepageUrl' => $this->urlGenerator->generate('app_default', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
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
