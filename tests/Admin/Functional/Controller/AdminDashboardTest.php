<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AdminDashboardTest extends WebTestCase
{
    use Factories;

    public function testDashboardRendersWithoutLinkTilesOrRedirectingToUserCrud(): void
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
        $main = $client->getCrawler()->filter('#main')->text();
        self::assertStringNotContainsString('Reference data and allocations', $main, 'the link tiles repeated the sidebar and are gone');
        self::assertStringNotContainsString('Review and respond to user feedback', $main);
        self::assertStringContainsString('Key metrics (last 30 days)', $text);
        self::assertStringNotContainsString('Recent failed imports', $text, 'failed imports are covered by the notifications card');
        self::assertStringContainsString('Daily trend (last 30 days)', $text);

        $path = $client->getRequest()->getPathInfo();
        self::assertMatchesRegularExpression('#^/admin/?$#', $path);
    }

    public function testRecentNotificationsShowLabelledEventsWithContextAndAuditLinks(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create();
        $client->loginUser($admin);

        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Nord']);
        $import = ImportFactory::createOne(['name' => 'October upload', 'hospital' => $hospital, 'createdBy' => $admin]);
        $newUser = UserFactory::createOne(['username' => 'new-user']);

        $this->persistEntry('2026-11-13 09:00:00', 'req-import', Import::class, (string) $import->getId(), AuditEntry::INTENT_IMPORT_RUN_FAILED, [
            'import_id' => $import->getId(),
            'reason' => 'file_not_found',
        ]);
        $this->persistEntry('2026-11-13 08:00:00', 'req-user', User::class, (string) $newUser->getId(), AuditEntry::INTENT_USER_REGISTERED);
        $this->persistEntry('2026-11-13 07:00:00', 'req-reminder', MonthlyReminderDispatch::class, '5', AuditEntry::INTENT_HOSPITAL_REMINDER_FAILED, [
            'hospital_id' => $hospital->getId(),
            'reporting_period' => '2026-10',
            'reason' => 'recipient_unavailable',
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();

        $items = $crawler->filter('.ea-dashboard-ops-notifications li.timeline-item');
        self::assertSame(['Import failed', 'New registration', 'Reminder not delivered'], $items->each(
            static fn (Crawler $item): string => trim($item->filter('.timeline-category')->text()),
        ));
        self::assertSame([
            'October upload · Klinikum Nord · Import file was not found',
            'User new-user',
            'Klinikum Nord · reporting month October 2026 · Recipient disabled or without email address',
        ], $items->each(static fn (Crawler $item): string => trim($item->filter('.ea-notification-context')->text())));
        self::assertSame(
            ['/admin/audit/requests/req-import', '/admin/audit/requests/req-user', '/admin/audit/requests/req-reminder'],
            $items->each(static fn (Crawler $item): string => (string) $item->filter('[data-testid="ops-notification-audit-link"]')->attr('href')),
        );
        self::assertStringContainsString('timeline-tone-danger', (string) $items->eq(0)->attr('class'), 'a failed import stands out');
        self::assertStringContainsString('timeline-tone-danger', (string) $items->eq(2)->attr('class'), 'so does a reminder that was not delivered');

        $showAll = $crawler->filter('[data-testid="ops-notifications-show-all"]');
        self::assertSame(
            self::getContainer()->get('router')->generate('app_admin_dashboard_audit_timeline'),
            $showAll->attr('href'),
            'show all opens the unfiltered audit timeline',
        );
    }

    public function testRecentNotificationsLeaveOtherIntentsOutAndFallBackForMissingRecords(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $this->persistEntry('2026-11-13 10:00:00', 'req-other', User::class, '1', 'user.admin.updated');
        // A sent reminder needs no action: it stays in the audit timeline only (#1173).
        $this->persistEntry('2026-11-13 09:30:00', 'req-sent', MonthlyReminderDispatch::class, '6', AuditEntry::INTENT_HOSPITAL_REMINDER_SENT, [
            'hospital_id' => 1,
            'reporting_period' => '2026-10',
        ]);
        // An older run: free-text reason, and the import has been deleted since.
        $this->persistEntry('2026-11-13 09:00:00', 'req-legacy', Import::class, '999', AuditEntry::INTENT_IMPORT_RUN_FAILED, [
            'import_id' => 999,
            'reason' => 'SQLSTATE[23505]: duplicate key',
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();

        $items = $crawler->filter('.ea-dashboard-ops-notifications li.timeline-item');
        self::assertCount(1, $items, 'only events an admin may need to act on');
        self::assertSame('#999 · – · Reason in the audit log', trim($items->filter('.ea-notification-context')->text()));
        self::assertStringNotContainsString('SQLSTATE', $crawler->filter('.ea-dashboard-ops-notifications')->text(), 'free-text reasons stay in the audit log');
    }

    public function testRecentNotificationsShowAnEmptyState(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ea-dashboard-ops-notifications li.timeline-item'));
        self::assertStringContainsString('No recent notification events.', $crawler->filter('.ea-dashboard-ops-notifications')->text());
    }

    /**
     * @param array<string, mixed> $intentMetadata
     */
    private function persistEntry(string $occurredAt, string $requestId, string $entityClass, string $entityId, string $intent, array $intentMetadata = []): void
    {
        $metadata = ['intent' => $intent];
        if ([] !== $intentMetadata) {
            $metadata['intent_metadata'] = $intentMetadata;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new AuditEntry(
            new \DateTimeImmutable($occurredAt),
            $requestId,
            null,
            'messenger',
            'update',
            $entityClass,
            $entityId,
            [],
            $metadata,
        ));
        $entityManager->flush();
    }
}
