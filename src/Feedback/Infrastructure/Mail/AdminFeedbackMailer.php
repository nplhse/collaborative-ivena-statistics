<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Mail;

use App\Admin\UI\Http\Controller\DashboardController;
use App\Admin\UI\Http\Controller\Feedback\FeedbackCrudController;
use App\Feedback\Application\Contract\AdminFeedbackNotifierInterface;
use App\Feedback\Application\FeedbackMailPreview;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Infrastructure\Security\FeedbackRecipientEmailResolver;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for AdminFeedbackNotifierInterface. */
#[AsAlias(AdminFeedbackNotifierInterface::class)]
final readonly class AdminFeedbackMailer implements AdminFeedbackNotifierInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $mailConfig,
        private FeedbackRecipientEmailResolver $feedbackRecipientResolver,
        private TranslatorInterface $translator,
        private LocaleResolver $localeResolver,
        private LoggerInterface $logger,
        private AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    #[\Override]
    public function notify(
        Feedback $feedback,
        FeedbackCategory $category,
        string $contextJsonPreview,
    ): void {
        $recipientsByLocale = $this->localeResolver->groupEmailsByLocale(
            $this->feedbackRecipientResolver->resolveRecipientUsers(),
        );
        if ([] === $recipientsByLocale) {
            $this->logger->info('feedback.admin_mail_skipped', [
                'reason' => 'no_feedback_recipients',
                'feedback_id' => $feedback->getId(),
            ]);

            return;
        }

        $messagePreview = FeedbackMailPreview::fromMessage((string) $feedback->getMessage());
        $adminUrl = $this->feedbackAdminUrl($feedback);

        foreach ($recipientsByLocale as $locale => $recipients) {
            $email = $this->createTemplatedEmail($locale)
                ->to(...$recipients)
                ->subject(sprintf(
                    '[%s] %s (%s)',
                    $this->mailConfig->appName,
                    $this->translator->trans('feedback.email.title', [], 'feedback', $locale),
                    $category->value,
                ))
                ->htmlTemplate('@Feedback/email/admin_feedback_notification.html.twig')
                ->context([
                    'feedback' => $feedback,
                    'categoryLabel' => $category->value,
                    'contextJson' => $contextJsonPreview,
                    'messagePreview' => $messagePreview,
                    'adminUrl' => $adminUrl,
                ]);

            $this->mailer->send($email);
        }
    }

    private function feedbackAdminUrl(Feedback $feedback): ?string
    {
        $id = $feedback->getId();
        if (null === $id) {
            return null;
        }

        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(FeedbackCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($id)
            ->generateUrl();
    }

    private function createTemplatedEmail(string $locale): TemplatedEmail
    {
        $email = new TemplatedEmail()
            ->from(new Address($this->mailConfig->fromEmail, $this->mailConfig->fromName))
            ->locale($locale);

        $replyTo = $this->mailConfig->replyTo();
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        return $email;
    }
}
