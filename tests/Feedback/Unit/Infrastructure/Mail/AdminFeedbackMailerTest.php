<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Infrastructure\Mail;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Infrastructure\Mail\AdminFeedbackMailer;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\FeedbackRecipientEmailResolver;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminFeedbackMailerTest extends TestCase
{
    public function testNotifySendsLocalizedAdminFeedbackMail(): void
    {
        $feedback = $this->createFeedbackWithId(42, 'Broken filter see https://spam.example/x now');

        $recipientResolver = $this->createStub(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([
            $this->createAdminUser('admin-a@example.test', 'de'),
            $this->createAdminUser('admin-b@example.test', 'de'),
        ]);

        $translator = $this->createTranslatorStub();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(
                    ['admin-a@example.test', 'admin-b@example.test'],
                    array_map(static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(), $email->getTo()),
                );
                self::assertSame('[Test App] Feedback (bug)', $email->getSubject());
                self::assertSame('de', $email->getLocale());
                self::assertSame('@Feedback/email/admin_feedback_notification.html.twig', $email->getHtmlTemplate());

                $context = $email->getContext();
                self::assertSame('Broken filter see now', $context['messagePreview'] ?? null);
                self::assertSame('https://admin.example.test/feedback/42', $context['adminUrl'] ?? null);
                self::assertStringNotContainsString('https://spam.example', (string) ($context['messagePreview'] ?? ''));

                return true;
            }));

        $this->createMailer($mailer, $recipientResolver, translator: $translator)->notify(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    public function testNotifySendsSeparateMailsPerLocale(): void
    {
        $feedback = $this->createFeedbackWithId(7, 'Broken filter');

        $recipientResolver = $this->createStub(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([
            $this->createAdminUser('de-admin@example.test', 'de'),
            $this->createAdminUser('en-admin@example.test', 'en'),
        ]);

        $localeResolver = new LocaleResolver(new LocaleCookieManager());

        $translator = $this->createTranslatorStub();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                $locale = $email->getLocale();
                self::assertContains($locale, ['de', 'en']);

                return true;
            }));

        $this->createMailer($mailer, $recipientResolver, translator: $translator, localeResolver: $localeResolver)->notify(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    public function testNotifySkipsWhenNoRecipients(): void
    {
        $feedback = $this->createFeedbackWithId(1, 'Broken filter');

        $recipientResolver = $this->createStub(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'feedback.admin_mail_skipped',
                self::callback(static fn (array $context): bool => 'no_feedback_recipients' === ($context['reason'] ?? null)),
            );

        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->expects(self::never())->method('unsetAll');

        $this->createMailer($mailer, $recipientResolver, $logger, adminUrlGenerator: $adminUrlGenerator)->notify(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    private function createMailer(
        MailerInterface $mailer,
        ?FeedbackRecipientEmailResolver $recipientResolver = null,
        ?LoggerInterface $logger = null,
        ?TranslatorInterface $translator = null,
        ?LocaleResolver $localeResolver = null,
        ?AdminUrlGeneratorInterface $adminUrlGenerator = null,
    ): AdminFeedbackMailer {
        if (!$adminUrlGenerator instanceof AdminUrlGeneratorInterface) {
            $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
            $adminUrlGenerator->method('unsetAll')->willReturnSelf();
            $adminUrlGenerator->method('setDashboard')->willReturnSelf();
            $adminUrlGenerator->method('setController')->willReturnSelf();
            $adminUrlGenerator->method('setAction')->willReturnSelf();
            $adminUrlGenerator->method('setEntityId')->willReturnSelf();
            $adminUrlGenerator->method('generateUrl')->willReturnCallback(
                static fn (): string => 'https://admin.example.test/feedback/42',
            );
        }

        return new AdminFeedbackMailer(
            $mailer,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'Test App',
                appName: 'Test App',
                replyTo: '',
            ),
            $recipientResolver ?? $this->createStub(FeedbackRecipientEmailResolver::class),
            $translator ?? $this->createTranslatorStub(),
            $localeResolver ?? new LocaleResolver(new LocaleCookieManager()),
            $logger ?? $this->createStub(LoggerInterface::class),
            $adminUrlGenerator,
        );
    }

    private function createTranslatorStub(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'feedback.email.title' => 'Feedback',
                default => $id,
            },
        );

        return $translator;
    }

    private function createAdminUser(string $email, string $locale): User
    {
        $user = new User();
        $user->setUsername(str_replace('@', '-', $email));
        $user->setEmail($email);
        $user->setPassword('hashed');
        $user->setLocale($locale);

        return $user;
    }

    private function createFeedbackWithId(int $id, string $message): Feedback
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage($message)
            ->setPageUrl('https://example.test/page');

        $reflection = new \ReflectionProperty(Feedback::class, 'id');
        $reflection->setValue($feedback, $id);

        return $feedback;
    }
}
