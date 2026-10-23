<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\Notification\Application\Contract\NotificationEmailCustomizerInterface;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Application\Mail\WelcomeEmailNextStepsBuilder;
use App\User\Domain\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The email of `participation.activated` is the welcome mail with the user's next steps.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationEmailCustomizerInterface.
 */
final readonly class ParticipationWelcomeEmailCustomizer implements NotificationEmailCustomizerInterface
{
    public function __construct(
        private WelcomeEmailNextStepsBuilder $nextStepsBuilder,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private MailConfig $mailConfig,
    ) {
    }

    #[\Override]
    public function supports(string $type): bool
    {
        return 'participation.activated' === $type;
    }

    #[\Override]
    public function customize(NotificationEmailContent $content, User $recipient): void
    {
        $nextSteps = [];
        foreach ($this->nextStepsBuilder->buildForUser($recipient) as $step) {
            $nextSteps[] = [
                'titleKey' => $step->titleKey,
                'descriptionKey' => $step->descriptionKey,
                'url' => $this->urlGenerator->generate($step->route, [], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $content->subject = $this->translator->trans('email.welcome.title', ['app' => $this->mailConfig->appName], 'user', $content->locale);
        $content->htmlTemplate = '@User/participation/welcome_email.html.twig';
        $content->context = [
            'greetingName' => $recipient->getUserIdentifier(),
            'dashboardUrl' => $this->urlGenerator->generate('app_default', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'nextSteps' => $nextSteps,
        ];
    }
}
