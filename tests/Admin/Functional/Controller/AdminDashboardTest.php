<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Entity\State;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AdminDashboardTest extends WebTestCase
{
    use Factories;

    public function testDashboardShowsTilesWithoutRedirectingToUserCrud(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'dashboard-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $text = $client->getCrawler()->text();
        self::assertStringContainsString('Users', $text);
        self::assertStringContainsString('Pages', $text);
        self::assertStringContainsString('Feedback', $text);
        self::assertStringContainsString('Reference data and allocations', $text);
        self::assertStringContainsString('Key metrics (last 30 days)', $text);
        self::assertStringContainsString('Recent failed imports', $text);
        self::assertStringContainsString('Daily trend (last 30 days)', $text);

        $path = $client->getRequest()->getPathInfo();
        self::assertMatchesRegularExpression('#^/admin/?$#', $path);
    }

    public function testRecentNotificationEventsListImportFailuresRegistrationsAndReminders(): void
    {
        // #1089: the card looked for intents that are never written and only showed reminders.
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([
            AuditEntry::INTENT_IMPORT_RUN_FAILED => '2026-11-13 09:00:00',
            AuditEntry::INTENT_USER_REGISTERED => '2026-11-13 08:00:00',
            AuditEntry::INTENT_HOSPITAL_REMINDER_SENT => '2026-11-13 07:00:00',
        ] as $intent => $occurredAt) {
            $entityManager->persist(new AuditEntry(
                new \DateTimeImmutable($occurredAt),
                'dashboard-'.$intent,
                null,
                'messenger',
                'update',
                State::class,
                '1',
                [],
                ['intent' => $intent],
            ));
        }
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [AuditEntry::INTENT_IMPORT_RUN_FAILED, AuditEntry::INTENT_USER_REGISTERED, AuditEntry::INTENT_HOSPITAL_REMINDER_SENT],
            $crawler->filter('.ea-dashboard-ops-notifications tbody code')->each(static fn ($node): string => $node->text()),
        );
    }
}
