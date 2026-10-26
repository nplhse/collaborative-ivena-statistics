<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Integration\Infrastructure\Notification;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Infrastructure\Notification\MonthlyReminderEmailCustomizer;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class MonthlyReminderEmailCustomizerTest extends DatabaseKernelTestCase
{
    public function testHandlesTheReminderTypeOnly(): void
    {
        $customizer = self::getContainer()->get(MonthlyReminderEmailCustomizer::class);

        self::assertTrue($customizer->supports('engagement.monthly_reminder'));
        self::assertFalse($customizer->supports('engagement.monthly_reminder_manual'), 'the former manual type is gone');
        self::assertFalse($customizer->supports('import.completed'));
    }

    public function testRendersTheReminderDesignWithoutReferenceDateOrDispatch(): void
    {
        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Test']);
        $content = $this->content(['hospitalId' => $hospital->getId()]);

        self::getContainer()->get(MonthlyReminderEmailCustomizer::class)->customize($content, UserFactory::createOne());

        self::assertSame('@Engagement/email/monthly_submission_reminder.html.twig', $content->htmlTemplate);
        self::assertStringContainsString('Klinikum Test', $content->subject);
        self::assertSame([], $content->headers);
    }

    public function testTheEmailCarriesNoDispatchHeaders(): void
    {
        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Test']);
        $content = $this->content(['hospitalId' => $hospital->getId(), 'dispatchId' => 12, 'attemptId' => 'attempt-a']);

        self::getContainer()->get(MonthlyReminderEmailCustomizer::class)->customize($content, UserFactory::createOne());

        self::assertSame([], $content->headers, 'the dispatch follows the delivery events, not mail headers');
    }

    public function testKeepsTheGenericEmailWhenTheHospitalIsGone(): void
    {
        $content = $this->content(['hospitalId' => 999_999]);

        self::getContainer()->get(MonthlyReminderEmailCustomizer::class)->customize($content, UserFactory::createOne());

        self::assertSame('generic.html.twig', $content->htmlTemplate);
        self::assertSame('Generic subject', $content->subject);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function content(array $parameters): NotificationEmailContent
    {
        return new NotificationEmailContent('engagement.monthly_reminder', 'en', $parameters, 'Generic subject', 'generic.html.twig', []);
    }
}
