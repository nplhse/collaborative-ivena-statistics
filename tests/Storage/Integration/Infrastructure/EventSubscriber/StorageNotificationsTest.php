<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\EventSubscriber;

use App\Storage\Application\Contract\StorageCapacityAlertStoreInterface;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\Event\StorageMeasured;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationPreferenceTestState;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Address;

/**
 * Storage alert and daily report through the real notification system (ADR 016, ADR 017).
 */
final class StorageNotificationsTest extends DatabaseKernelTestCase
{
    private const int MB = 1024 * 1024;

    private Connection $connection;
    private StorageMeasurementRepositoryInterface $measurements;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->measurements = self::getContainer()->get(StorageMeasurementRepositoryInterface::class);
    }

    public function testAnEscalationAlertsAdministratorsInAppAndByEmailOnce(): void
    {
        $admin = UserFactory::new()->asAdmin()->create(['email' => 'admin-storage@example.test']);
        $user = UserFactory::createOne();

        $this->announce($this->save(StorageMeasurementBuilder::create(usedBytes: 995 * self::MB, level: CapacityLevel::Critical, capacityBytes: 1000 * self::MB)));

        $rows = $this->connection->fetchAllAssociative("SELECT * FROM notification WHERE recipient_id = ? AND type = 'storage.capacity_alert'", [$admin->getId()]);
        self::assertCount(1, $rows);
        self::assertSame('high', $rows[0]['priority']);
        self::assertSame('role', $rows[0]['access_scope']);
        self::assertSame('ROLE_ADMIN', $rows[0]['required_role']);
        self::assertSame('app_admin_dashboard', $rows[0]['action_route']);
        $parameters = json_decode((string) $rows[0]['parameters'], true);
        self::assertIsArray($parameters);
        self::assertSame(['escalated', 'normal', 'critical'], [$parameters['direction'], $parameters['from'], $parameters['to']]);
        self::assertSame(['storage.capacity_alert'], $this->types($admin), 'the daily report is opt-in');
        self::assertSame([], $this->types($user));
        self::assertSame(CapacityLevel::Critical, self::getContainer()->get(StorageCapacityAlertStoreInterface::class)->lastNotifiedLevel());

        $email = $this->emailTo('admin-storage@example.test');
        self::assertSame('Storage is critically full', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('The storage level rose from “normal” to “critical”.', $html);
        self::assertStringContainsString('99.5 % of the configured budget is in use (995.0 MB of 1000.0 MB), 5.0 MB remain.', $html);
        self::assertStringContainsString('Configured budget', $html);
        self::assertStringContainsString('/admin', $html);

        // The same measurement announced again (a rerun) and the next unchanged day send nothing.
        $this->announce($this->measurements->findForDay(new \DateTimeImmutable('2026-10-25')));
        $this->announce($this->save(StorageMeasurementBuilder::create(day: '2026-10-26', usedBytes: 996 * self::MB, level: CapacityLevel::Critical, capacityBytes: 1000 * self::MB)));
        self::assertSame(['storage.capacity_alert'], $this->types($admin));
    }

    public function testAFallBackToNormalIsAnnouncedAsResolvedInTheRecipientsLanguage(): void
    {
        UserFactory::new()->asAdmin()->create(['email' => 'admin-de@example.test', 'locale' => 'de']);
        self::getContainer()->get(StorageCapacityAlertStoreInterface::class)->recordNotified(CapacityLevel::Warning, new \DateTimeImmutable('2026-10-20'), new \DateTimeImmutable());

        $this->announce($this->save(StorageMeasurementBuilder::create(usedBytes: 500, level: CapacityLevel::Normal)));

        $email = $this->emailTo('admin-de@example.test');
        self::assertSame('Speichernutzung wieder normal', $email->getSubject());
        self::assertStringContainsString('Die Speicherstufe ist von „Warnung“ wieder auf „normal“ gesunken.', (string) $email->getHtmlBody());
        self::assertSame(CapacityLevel::Normal, self::getContainer()->get(StorageCapacityAlertStoreInterface::class)->lastNotifiedLevel());
    }

    public function testTheDailyReportGoesOnlyToAdministratorsWhoOptedIn(): void
    {
        $subscribed = UserFactory::new()->asAdmin()->create(['email' => 'report@example.test']);
        $other = UserFactory::new()->asAdmin()->create();
        $preferences = new NotificationPreferenceTestState($this->connection);
        $preferences->switchOn($subscribed, 'storage.daily_report', 'in_app');
        $preferences->switchOn($subscribed, 'storage.daily_report', 'email');

        $this->save(StorageMeasurementBuilder::create(day: '2026-10-24', usedBytes: 400 * self::MB, level: CapacityLevel::Normal, capacityBytes: 1000 * self::MB));
        $this->announce($this->save(StorageMeasurementBuilder::create(
            usedBytes: 450 * self::MB,
            level: CapacityLevel::Normal,
            capacityBytes: 1000 * self::MB,
            mode: MeasurementMode::Deployer,
            items: [
                new StorageMeasurementItem(StorageLocation::Database, StorageCategory::Database, 'database', 200 * self::MB),
                new StorageMeasurementItem(StorageLocation::Shared, StorageCategory::Imports, 'var_imports', 150 * self::MB, locationDetail: 'var/imports'),
                new StorageMeasurementItem(StorageLocation::PreviousRelease, StorageCategory::Application, 'vendor', 100 * self::MB, locationDetail: '20261024.1'),
            ],
        )));

        self::assertSame(['storage.daily_report'], $this->types($subscribed));
        self::assertSame([], $this->types($other));

        $email = $this->emailTo('report@example.test');
        self::assertSame('Daily storage report: 450.0 MB used', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('The application uses 450.0 MB. Of the configured budget of 1000.0 MB, 550.0 MB (55 %) remain. That is 50.0 MB more than the day before. Largest category: Database (200.0 MB).', $html);
        self::assertStringContainsString('Database: 200.0 MB · Imports: 150.0 MB · Application: 100.0 MB', $html);
        self::assertStringContainsString('1 release, 100.0 MB', $html);
        self::assertStringContainsString('Shared directory', $html);
        self::assertStringContainsString('+50.0 MB since the day before', $html);
    }

    private function save(StorageMeasurement $measurement): StorageMeasurement
    {
        return $this->measurements->save($measurement);
    }

    private function announce(?StorageMeasurement $measurement): void
    {
        self::assertInstanceOf(StorageMeasurement::class, $measurement);
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(
            new StorageMeasured((int) $measurement->getId(), $measurement->getMeasuredOn(), $measurement->getLevel()),
        );
    }

    /**
     * @return list<string>
     */
    private function types(User $user): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT type FROM notification WHERE recipient_id = ? ORDER BY type', [$user->getId()]));
    }

    private function emailTo(string $address): TemplatedEmail
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof TemplatedEmail && \in_array($address, array_map(static fn (Address $to): string => $to->getAddress(), $message->getTo()), true)) {
                return $message;
            }
        }

        self::fail(\sprintf('No email to %s.', $address));
    }
}
