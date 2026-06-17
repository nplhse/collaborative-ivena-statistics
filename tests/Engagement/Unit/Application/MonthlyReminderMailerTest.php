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
use Symfony\Contracts\Translation\TranslatorInterface;

final class MonthlyReminderMailerTest extends TestCase
{
    public function testSendUsesConfiguredSenderSubjectAndTemplate(): void
    {
        $content = $this->content();

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => match ($id) {
                'monthly_reminder.subject' => sprintf('Reminder for %s (%s)', $parameters['hospital'], $parameters['period']),
                default => $id,
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
                self::assertSame('IVENA Stats', $email->getContext()['app_title'] ?? null);

                return true;
            }));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        new MonthlyReminderMailer(
            $mailer,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'IVENA Stats',
                appName: 'IVENA Stats',
                replyTo: '',
            ),
            $translator,
            $logger,
        )->send('owner@example.test', $content);
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
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'IVENA Stats',
                appName: 'IVENA Stats',
                replyTo: 'support@example.test',
            ),
            $translator,
            $this->createMock(LoggerInterface::class),
        )->send('owner@example.test', $this->content());
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
