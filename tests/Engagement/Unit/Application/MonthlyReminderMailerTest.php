<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\Dto\MonthlyReminderContent;
use App\Engagement\Application\MonthlyReminderMailer;
use App\Shared\Infrastructure\Mail\MailConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MonthlyReminderMailerTest extends TestCase
{
    public function testSendUsesConfiguredSenderSubjectTemplateAndLocale(): void
    {
        $content = $this->content();
        $capturedLocale = null;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null) use (&$capturedLocale): string {
                if ('monthly_reminder.subject' === $id) {
                    $capturedLocale = $locale;

                    return sprintf('Reminder for %s (%s)', $parameters['hospital'], $parameters['period']);
                }

                return $id;
            },
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame('no-reply@example.test', $email->getFrom()[0]->getAddress());
                self::assertSame('IVENA Stats', $email->getFrom()[0]->getName());
                self::assertSame(['owner@example.test'], array_map(
                    static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame('Reminder for Test Hospital (May 2026)', $email->getSubject());
                self::assertSame('@Engagement/email/monthly_submission_reminder.html.twig', $email->getHtmlTemplate());
                self::assertSame('de', $email->getLocale());
                self::assertSame('IVENA Stats', $email->getContext()['app_title'] ?? null);

                return true;
            }));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        new MonthlyReminderMailer(
            $mailer,
            $messageBus,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'IVENA Stats',
                appName: 'IVENA Stats',
                replyTo: '',
            ),
            $translator,
            $logger,
            bulkDelayMs: 0,
        )->send('owner@example.test', $content, 'de');

        self::assertSame('de', $capturedLocale);
    }

    public function testReplyToIsAppliedWhenConfigured(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('subject');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(['support@example.test'], array_map(
                    static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
                    $email->getReplyTo(),
                ));

                return true;
            }));

        new MonthlyReminderMailer(
            $mailer,
            $this->createMock(MessageBusInterface::class),
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'IVENA Stats',
                appName: 'IVENA Stats',
                replyTo: 'support@example.test',
            ),
            $translator,
            $this->createMock(LoggerInterface::class),
            bulkDelayMs: 0,
        )->send('owner@example.test', $this->content(), 'en');
    }

    public function testBulkIndexDispatchesDelayedSendEmailMessage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('subject');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(
                self::callback(static fn (SendEmailMessage $message): bool => $message->getMessage() instanceof TemplatedEmail),
                self::callback(function (array $stamps): bool {
                    self::assertCount(1, $stamps);
                    self::assertInstanceOf(DelayStamp::class, $stamps[0]);
                    self::assertSame(6000, $stamps[0]->getDelay());

                    return true;
                }),
            )
            ->willReturn(new Envelope(new SendEmailMessage(new TemplatedEmail())));

        new MonthlyReminderMailer(
            $mailer,
            $messageBus,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'IVENA Stats',
                appName: 'IVENA Stats',
                replyTo: '',
            ),
            $translator,
            $this->createMock(LoggerInterface::class),
            bulkDelayMs: 3000,
        )->send('owner@example.test', $this->content(), 'en', bulkIndex: 2);
    }

    public function testDispatchIdHeaderIsAddedWhenProvided(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('subject');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(
                    '42',
                    $email->getHeaders()->get(MonthlyReminderMailer::DISPATCH_ID_HEADER)?->getBodyAsString(),
                );

                return true;
            }));

        new MonthlyReminderMailer(
            $mailer,
            $this->createMock(MessageBusInterface::class),
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'IVENA Stats',
                appName: 'IVENA Stats',
                replyTo: '',
            ),
            $translator,
            $this->createMock(LoggerInterface::class),
            bulkDelayMs: 0,
        )->send('owner@example.test', $this->content(), 'en', dispatchId: 42);
    }

    private function content(): MonthlyReminderContent
    {
        return new MonthlyReminderContent(
            hospitalName: 'Test Hospital',
            reportingPeriodLabel: 'May 2026',
            uploadMonthLabel: 'June 2026',
            preheader: 'Preview',
            isPersonalized: true,
            allocationCount: 10,
            allocationMomPercent: null,
            lastImportLabel: 'None',
            lastImportStale: true,
            withPhysicianPercent: 0.0,
            withPhysicianBaselineDeltaPp: null,
            baselinePeriodLabel: 'baseline',
            medianTransportMinutes: 0.0,
            medianTransportBaselineDeltaMinutes: null,
            trendSummary: '',
            chartBars: [],
            urgencySegments: [],
            urgencyBenchmarkNote: null,
            genderSegments: [],
            insights: [],
            submissionMonthsCompleted: 0,
            submissionMonthsTotal: 12,
            submissionProgressPercent: 0,
            submissionMonths: [],
            longestSubmissionGapLabel: null,
            importCreateUrl: 'https://example.test/import',
            statisticsDashboardUrl: 'https://example.test/stats',
            benchmarkingUrl: 'https://example.test/benchmark',
            notificationsSettingsUrl: 'https://example.test/settings',
        );
    }
}
