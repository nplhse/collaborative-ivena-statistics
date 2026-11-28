<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\Service;

use App\Admin\Application\DTO\RecentNotificationEventDto;
use App\Admin\Application\Service\RecentNotificationEvents;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The events of the dashboard card "Recent notifications" (#1137) with the context an admin needs; the card itself is
 * rendered in AdminDashboardTest.
 */
#[ResetDatabase]
final class RecentNotificationEventsTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testAFailedImportCarriesTheImportTheHospitalAndTheReasonCode(): void
    {
        $import = ImportFactory::createOne(['name' => 'October upload', 'hospital' => HospitalFactory::createOne(['name' => 'Klinikum Nord'])]);
        $this->clearAuditLog();
        $this->persistEntry('2026-11-13 09:00:00', 'req-import', Import::class, (string) $import->getId(), AuditEntry::INTENT_IMPORT_RUN_FAILED, [
            'import_id' => $import->getId(),
            'reason' => 'missing_hospital',
        ]);

        $events = $this->events()->latest();

        self::assertEquals([new RecentNotificationEventDto(
            intent: AuditEntry::INTENT_IMPORT_RUN_FAILED,
            occurredAt: new \DateTimeImmutable('2026-11-13 09:00:00'),
            requestId: 'req-import',
            subject: 'October upload',
            subjectId: (string) $import->getId(),
            hospital: 'Klinikum Nord',
            reasonCode: 'missing_hospital',
        )], $events);
    }

    public function testARegistrationCarriesTheUsername(): void
    {
        $user = UserFactory::createOne(['username' => 'new-user']);
        $this->clearAuditLog();
        $this->persistEntry('2026-11-13 08:00:00', 'req-user', User::class, (string) $user->getId(), AuditEntry::INTENT_USER_REGISTERED);

        $events = $this->events()->latest();

        self::assertCount(1, $events);
        self::assertSame('new-user', $events[0]->subject);
        self::assertSame((string) $user->getId(), $events[0]->subjectId);
    }

    public function testAFailedReminderCarriesTheHospitalTheReasonAndTheReportingMonth(): void
    {
        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Süd']);
        $this->clearAuditLog();
        $this->persistEntry('2026-11-13 07:00:00', 'req-reminder', MonthlyReminderDispatch::class, '5', AuditEntry::INTENT_HOSPITAL_REMINDER_FAILED, [
            'hospital_id' => $hospital->getId(),
            'reporting_period' => '2026-10',
            'reason' => 'recipient_unavailable',
        ]);

        $events = $this->events()->latest();

        self::assertCount(1, $events);
        self::assertSame('Klinikum Süd', $events[0]->subject);
        self::assertSame('recipient_unavailable', $events[0]->reasonCode);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 00:00:00'), $events[0]->reportingPeriod);
    }

    public function testOnlyEventsAnAdminMayActOnAreListedNewestFirst(): void
    {
        $this->clearAuditLog();
        $this->persistEntry('2026-11-13 07:00:00', 'req-registered', User::class, '1', AuditEntry::INTENT_USER_REGISTERED);
        $this->persistEntry('2026-11-13 08:00:00', 'req-failed', Import::class, '2', AuditEntry::INTENT_IMPORT_RUN_FAILED);
        $this->persistEntry('2026-11-13 09:00:00', 'req-other', User::class, '1', 'user.admin.updated');
        // A sent reminder needs no action: it stays in the audit timeline only (#1173).
        $this->persistEntry('2026-11-13 10:00:00', 'req-sent', MonthlyReminderDispatch::class, '6', AuditEntry::INTENT_HOSPITAL_REMINDER_SENT, ['hospital_id' => 1]);

        $events = $this->events()->latest();

        self::assertSame(['req-failed', 'req-registered'], array_map(static fn (RecentNotificationEventDto $event): string => $event->requestId, $events));
    }

    public function testTheLimitKeepsTheNewestEvents(): void
    {
        $this->clearAuditLog();
        $this->persistEntry('2026-11-13 07:00:00', 'req-1', User::class, '1', AuditEntry::INTENT_USER_REGISTERED);
        $this->persistEntry('2026-11-13 08:00:00', 'req-2', User::class, '2', AuditEntry::INTENT_USER_REGISTERED);
        $this->persistEntry('2026-11-13 09:00:00', 'req-3', User::class, '3', AuditEntry::INTENT_USER_REGISTERED);

        $events = $this->events()->latest(2);

        self::assertSame(['req-3', 'req-2'], array_map(static fn (RecentNotificationEventDto $event): string => $event->requestId, $events));
    }

    public function testADeletedImportKeepsItsIdAndAFreeTextReasonIsLeftOut(): void
    {
        $this->clearAuditLog();
        // An older run: free-text reason, and the import has been deleted since.
        $this->persistEntry('2026-11-13 09:00:00', 'req-legacy', Import::class, '999', AuditEntry::INTENT_IMPORT_RUN_FAILED, [
            'import_id' => 999,
            'reason' => 'SQLSTATE[23505]: duplicate key',
        ]);

        $events = $this->events()->latest();

        self::assertCount(1, $events);
        self::assertNull($events[0]->subject);
        self::assertSame('999', $events[0]->subjectId);
        self::assertNull($events[0]->hospital);
        self::assertNull($events[0]->reasonCode);
    }

    public function testAnImportFailureWithoutMetadataTakesTheIdOfTheAuditedImport(): void
    {
        $import = ImportFactory::createOne(['name' => 'Handler path', 'hospital' => HospitalFactory::createOne()]);
        $this->clearAuditLog();
        $this->persistEntry('2026-11-13 09:00:00', 'req-handler', Import::class, (string) $import->getId(), AuditEntry::INTENT_IMPORT_RUN_FAILED);

        $events = $this->events()->latest();

        self::assertSame('Handler path', $events[0]->subject);
    }

    private function events(): RecentNotificationEvents
    {
        return self::getContainer()->get(RecentNotificationEvents::class);
    }

    /** Creating the fixtures writes audit entries of its own; the entries of the test are the only content. */
    private function clearAuditLog(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM audit_log');
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
        $entityManager->persist(new AuditEntry(new \DateTimeImmutable($occurredAt), $requestId, null, 'messenger', 'update', $entityClass, $entityId, [], $metadata));
        $entityManager->flush();
    }
}
