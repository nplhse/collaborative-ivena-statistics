<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MonthlyReminderDispatchCrudControllerTest extends WebTestCase
{
    use Factories;
    use MailerAssertionsTrait;

    public function testAdminCanViewReadOnlyReminderDispatchList(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'reminder-admin-'.bin2hex(random_bytes(4)),
        ]);
        $hospital = HospitalFactory::createOne();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist(new MonthlyReminderDispatch(
            $hospital,
            '2026-06',
            MonthlyReminderTrigger::Scheduler->value,
            new \DateTimeImmutable(),
            MonthlyReminderDispatchStatus::Sent,
            'owner@example.test',
            null,
            new \DateTimeImmutable(),
        ));
        $entityManager->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/monthly-reminder-dispatch');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2026-06', $client->getResponse()->getContent());
    }

    public function testReminderDispatchIndexOmitsSecondaryColumns(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'reminder-index-'.bin2hex(random_bytes(4)),
        ]);
        $hospital = HospitalFactory::createOne();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist(new MonthlyReminderDispatch(
            $hospital,
            '2026-07',
            MonthlyReminderTrigger::Admin->value,
            new \DateTimeImmutable('2026-07-01 08:00:00'),
            MonthlyReminderDispatchStatus::Sent,
            'owner@example.test',
            null,
            new \DateTimeImmutable('2026-07-01 08:05:00'),
        ));
        $entityManager->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/monthly-reminder-dispatch');
        self::assertResponseIsSuccessful();

        $headerText = implode(' | ', $crawler->filter('table thead th')->each(static fn ($node): string => trim($node->text())));
        self::assertStringContainsString('Hospital', $headerText);
        self::assertStringContainsString('Reporting period', $headerText);
        self::assertStringContainsString('Trigger', $headerText);
        self::assertStringContainsString('Status', $headerText);
        self::assertStringContainsString('Queued at', $headerText);
        self::assertStringNotContainsString('Recipient', $headerText);
        self::assertStringNotContainsString('Delivered at', $headerText);
    }

    public function testReminderDispatchDetailShowsRecipientAndDelivery(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'reminder-detail-'.bin2hex(random_bytes(4)),
        ]);
        $hospital = HospitalFactory::createOne();
        $dispatch = new MonthlyReminderDispatch(
            $hospital,
            '2026-08',
            MonthlyReminderTrigger::Cli->value,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            MonthlyReminderDispatchStatus::Sent,
            'detail-owner@example.test',
            null,
            new \DateTimeImmutable('2026-08-01 09:10:00'),
        );

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($dispatch);
        $entityManager->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/monthly-reminder-dispatch/'.$dispatch->getId());
        self::assertResponseIsSuccessful();

        $body = $crawler->text();
        self::assertStringContainsString('detail-owner@example.test', $body);
        self::assertStringContainsString('Recipient', $body);
        self::assertStringContainsString('Delivered at', $body);
        self::assertSelectorExists('a.action-sendMonthlyReminder');
    }

    public function testReminderDispatchSendActionQueuesEmailForHospitalOwner(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne([
            'email' => sprintf('dispatch-owner-%s@example.test', bin2hex(random_bytes(4))),
            'isVerified' => true,
        ]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'isParticipating' => true,
        ]);
        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'reminder-send-'.bin2hex(random_bytes(4)),
        ]);
        $dispatch = new MonthlyReminderDispatch(
            $hospital,
            '2026-05',
            MonthlyReminderTrigger::Scheduler->value,
            new \DateTimeImmutable('2026-06-01 08:00:00'),
            MonthlyReminderDispatchStatus::Failed,
            (string) $owner->getEmail(),
            'SMTP unavailable',
        );

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($dispatch);
        $entityManager->flush();

        $client->loginUser($admin);
        $client->request(
            Request::METHOD_GET,
            '/admin/monthly-reminder-dispatch/'.$dispatch->getId().'/send-monthly-reminder',
        );
        self::assertResponseRedirects();
        self::assertQueuedEmailCount(1);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testCreateActionIsDisabled(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/monthly-reminder-dispatch/new');
        self::assertResponseStatusCodeSame(403);
    }
}
