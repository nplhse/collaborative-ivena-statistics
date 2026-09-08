<?php

declare(strict_types=1);

namespace App\User\Infrastructure\EventSubscriber;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Mail\TransactionalMailer;
use App\User\Application\Event\UserBecameParticipant;
use App\User\Application\Mail\WelcomeEmailNextStepsBuilder;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: UserBecameParticipant::class, method: 'onUserBecameParticipant')]
final readonly class UserBecameParticipantNotificationSubscriber
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
        private TransactionalMailer $transactionalMailer,
        private LocaleResolver $localeResolver,
        private WelcomeEmailNextStepsBuilder $nextStepsBuilder,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onUserBecameParticipant(UserBecameParticipant $event): void
    {
        $user = $this->userRepository->find($event->userId);
        if (!$user instanceof User || !$user->isEnabled()) {
            return;
        }

        $email = $user->getEmail();
        if (null === $email || '' === trim($email)) {
            return;
        }

        $nextSteps = [];
        foreach ($this->nextStepsBuilder->buildForUser($user) as $step) {
            $nextSteps[] = [
                'titleKey' => $step->titleKey,
                'descriptionKey' => $step->descriptionKey,
                'url' => $this->urlGenerator->generate($step->route, [], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $this->transactionalMailer->sendParticipationWelcomeEmail(
            $email,
            $user->getUserIdentifier(),
            $this->urlGenerator->generate('app_default', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $nextSteps,
            $this->localeResolver->resolveForUser($user),
        );
    }
}
