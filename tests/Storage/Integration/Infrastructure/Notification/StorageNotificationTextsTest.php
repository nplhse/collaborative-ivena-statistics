<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Notification;

use App\Notification\Application\Delivery\NotificationEmailContent;
use App\Storage\Application\Notification\CapacityLevelChange;
use App\Storage\Application\Notification\StorageNotificationParameters;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Infrastructure\Notification\StorageCapacityAlertNotification;
use App\Storage\Infrastructure\Notification\StorageDailyReportNotification;
use App\Storage\Infrastructure\Notification\StorageEmailCustomizer;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every variant of the storage texts renders in both languages, and the emails show the right rows.
 */
final class StorageNotificationTextsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }

    #[DataProvider('locales')]
    public function testEveryAlertVariantRenders(string $locale): void
    {
        $titles = [];
        foreach (CapacityLevel::cases() as $from) {
            foreach (CapacityLevel::cases() as $to) {
                $change = CapacityLevelChange::between($from, $to);
                if (!$change instanceof CapacityLevelChange) {
                    continue;
                }

                foreach ([CapacitySource::Budget, CapacitySource::Filesystem] as $source) {
                    $parameters = StorageNotificationParameters::alert($this->measurementFor($to, $source), $change);
                    $titles[] = $this->assertRenders('storage.capacity_alert.title', $parameters, $locale);
                    $this->assertRenders('storage.capacity_alert.message', $parameters, $locale);
                }
            }
        }

        self::assertCount(8, array_unique($titles), 'one title per escalated level, per lower level and for resolved');
    }

    #[DataProvider('locales')]
    public function testEveryReportVariantRenders(string $locale): void
    {
        $measurements = [
            StorageMeasurementBuilder::create(),
            StorageMeasurementBuilder::create(source: CapacitySource::Filesystem, capacityBytes: 5000, filesystemTotalBytes: 5000, filesystemFreeBytes: 1000),
            StorageMeasurementBuilder::create(usedBytes: null, level: CapacityLevel::Unknown, databaseBytes: null),
            StorageMeasurementBuilder::create(level: CapacityLevel::Unknown, source: CapacitySource::None, capacityBytes: null, mode: MeasurementMode::Deployer),
        ];
        $previous = [
            null,
            StorageMeasurementBuilder::create(day: '2026-10-24', usedBytes: 800),
            StorageMeasurementBuilder::create(day: '2026-10-20', usedBytes: 900),
            StorageMeasurementBuilder::create(day: '2026-10-24', usedBytes: 850),
        ];

        foreach ($measurements as $measurement) {
            foreach ($previous as $earlier) {
                $parameters = StorageNotificationParameters::report($measurement, $earlier);
                $this->assertRenders('storage.daily_report.title', $parameters, $locale);
                $this->assertRenders('storage.daily_report.message', $parameters, $locale);
                foreach ($this->emailRows(StorageDailyReportNotification::TYPE, $parameters, $locale) as $row) {
                    self::assertStringNotContainsString('{', $row['label'].$row['value']);
                }
            }
        }
    }

    public function testAlertEmailRows(): void
    {
        $change = CapacityLevelChange::between(CapacityLevel::Notice, CapacityLevel::Warning);
        self::assertNotNull($change);
        $parameters = StorageNotificationParameters::alert(
            StorageMeasurementBuilder::create(level: CapacityLevel::Warning, source: CapacitySource::Filesystem, capacityBytes: 5000, filesystemTotalBytes: 5000, filesystemFreeBytes: 400),
            $change,
        );

        self::assertSame([
            ['label' => 'Level', 'value' => 'notice → warning'],
            ['label' => 'Filesystem', 'value' => '4.9 KB'],
            ['label' => 'In use', 'value' => '4.5 KB (92 %)'],
            ['label' => 'Remaining', 'value' => '400 B (8 %)'],
        ], $this->emailRows(StorageCapacityAlertNotification::TYPE, $parameters, 'en'));
    }

    public function testReportEmailRowsWithoutReferenceOrMeasurement(): void
    {
        $parameters = StorageNotificationParameters::report(
            StorageMeasurementBuilder::create(usedBytes: null, level: CapacityLevel::Unknown, source: CapacitySource::None, capacityBytes: null, databaseBytes: null),
            null,
        );

        self::assertSame([
            ['label' => 'Stufe', 'value' => 'unbekannt'],
            ['label' => 'Von der Anwendung belegt', 'value' => 'nicht gemessen'],
            ['label' => 'Datenbank', 'value' => 'nicht gemessen'],
            ['label' => 'Kapazitätsreferenz', 'value' => 'nicht konfiguriert'],
            ['label' => 'Verbleibend', 'value' => 'nicht konfiguriert'],
            ['label' => 'Änderung', 'value' => '–'],
            ['label' => 'Größte Kategorien', 'value' => 'Datenbank: 0 B · Importe: 0 B · Medien: 0 B'],
        ], $this->emailRows(StorageDailyReportNotification::TYPE, $parameters, 'de'));
    }

    public function testCustomizerKeepsTheGenericTextsAndOnlyTakesStorageTypes(): void
    {
        $customizer = self::getContainer()->get(StorageEmailCustomizer::class);
        self::assertTrue($customizer->supports(StorageCapacityAlertNotification::TYPE));
        self::assertTrue($customizer->supports(StorageDailyReportNotification::TYPE));
        self::assertFalse($customizer->supports('admin.user_registered'));

        $content = $this->content(StorageDailyReportNotification::TYPE, StorageNotificationParameters::report(StorageMeasurementBuilder::create(), null), 'en');
        $customizer->customize($content, new User());

        self::assertSame(StorageEmailCustomizer::TEMPLATE, $content->htmlTemplate);
        self::assertSame('Title', $content->context['title']);
        self::assertSame('Message', $content->context['message']);
        self::assertSame('https://example.test/admin', $content->context['actionUrl']);
        self::assertSame('https://example.test/notifications', $content->context['inboxUrl']);
    }

    private function measurementFor(CapacityLevel $level, CapacitySource $source): StorageMeasurement
    {
        $used = ['normal' => 500, 'notice' => 850, 'warning' => 950, 'critical' => 995, 'exceeded' => 1200][$level->value] ?? null;

        return CapacitySource::Filesystem === $source
            ? StorageMeasurementBuilder::create(usedBytes: 100, level: $level, source: $source, capacityBytes: 1000, filesystemTotalBytes: 1000, filesystemFreeBytes: max(0, 1000 - (int) $used))
            : StorageMeasurementBuilder::create(usedBytes: $used, level: $level);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function assertRenders(string $key, array $parameters, string $locale): string
    {
        $text = self::getContainer()->get(TranslatorInterface::class)->trans($key, $parameters, 'notification', $locale);

        self::assertNotSame($key, $text);
        self::assertStringNotContainsString('{', $text, $text);
        self::assertStringNotContainsString('  ', $text, $text);
        self::assertSame(trim($text), $text);

        return $text;
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array{label: string, value: string}>
     */
    private function emailRows(string $type, array $parameters, string $locale): array
    {
        $content = $this->content($type, $parameters, $locale);
        self::getContainer()->get(StorageEmailCustomizer::class)->customize($content, new User());

        /* @var list<array{label: string, value: string}> */
        return $content->context['rows'];
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function content(string $type, array $parameters, string $locale): NotificationEmailContent
    {
        return new NotificationEmailContent(
            type: $type,
            locale: $locale,
            parameters: $parameters,
            subject: 'Title',
            htmlTemplate: 'generic',
            context: ['title' => 'Title', 'message' => 'Message', 'actionUrl' => 'https://example.test/admin', 'inboxUrl' => 'https://example.test/notifications'],
        );
    }
}
