<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final readonly class RecordFeedbackHandler
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        #[Autowire('%app.mailer_from%')]
        private string $mailerFrom,
        #[Autowire('%app.feedback.admin_notification_email%')]
        private string $adminNotificationEmail,
        private LoggerInterface $logger,
        #[Autowire('%app.title%')]
        private string $appTitle,
    ) {
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function execute(
        FeedbackCategory $category,
        string $message,
        ?string $guestEmail,
        ?User $submittedBy,
        string $pageUrl,
        ?string $routeName,
        ?array $context,
        ?string $userAgent,
        ?string $appVersion,
    ): void {
        $pageUrl = $this->truncate($pageUrl, 2048);
        $userAgent = null !== $userAgent ? $this->truncate($userAgent, 4096) : null;
        $appVersion = null !== $appVersion ? $this->truncate($appVersion, 64) : null;

        $feedback = new Feedback()
            ->setCategory($category)
            ->setMessage($message)
            ->setGuestEmail($guestEmail)
            ->setSubmittedBy($submittedBy)
            ->setPageUrl($pageUrl)
            ->setRouteName($routeName)
            ->setContext($context)
            ->setUserAgent($userAgent)
            ->setAppVersion($appVersion);

        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        $recipient = trim($this->adminNotificationEmail);
        if ('' !== $recipient) {
            $subject = sprintf('[%s] Feedback (%s)', $this->appTitle, $category->value);
            $this->mailer->send(
                new TemplatedEmail()
                    ->from($this->fromAddress())
                    ->to($recipient)
                    ->subject($subject)
                    ->htmlTemplate('@Feedback/email/admin_feedback_notification.html.twig')
                    ->context([
                        'feedback' => $feedback,
                        'categoryLabel' => $category->value,
                        'contextJson' => $this->encodeContextPreview($feedback->getContext()),
                    ])
            );
        } else {
            $this->logger->info('feedback.admin_mail_skipped', [
                'reason' => 'empty FEEDBACK_ADMIN_EMAIL',
                'feedback_id' => $feedback->getId(),
            ]);
        }
    }

    private function fromAddress(): Address
    {
        return new Address($this->mailerFrom, $this->appTitle);
    }

    /** @param array<string, mixed>|null $context */
    private function encodeContextPreview(?array $context): string
    {
        if (null === $context || [] === $context) {
            return '—';
        }

        try {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return '(invalid JSON)';
        }

        return $this->truncate($encoded, 12000);
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 1).'…';
    }
}
