<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableValueResolver;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventChildPreview;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalTableRow;
use App\Statistics\ClosureAnalytics\Application\Export\ClosureTableCsvCellFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ClosureTableCsvCellFormatterTest extends TestCase
{
    public function testFormatsEventCellsWithoutHtml(): void
    {
        $formatter = $this->formatter();
        $row = new ClosureEventRow(
            'group:1:demo',
            ClosureEventType::Group,
            1,
            'Demo Hospital',
            'demo',
            new \DateTimeImmutable('2026-05-01 10:00:00', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-05-01 12:00:00', new \DateTimeZone('Europe/Berlin')),
            2,
            240,
            120,
            1_440,
            [
                new ClosureEventChildPreview(
                    1,
                    'Surgery',
                    'Trauma',
                    'emergency',
                    'no_bed_capacity',
                    'Ward 1',
                    new \DateTimeImmutable('2026-05-01 10:00:00'),
                    new \DateTimeImmutable('2026-05-01 12:00:00'),
                ),
                new ClosureEventChildPreview(
                    2,
                    'Surgery',
                    'Orthopedics',
                    'inpatient',
                    'no_bed_capacity',
                    'Ward 1',
                    new \DateTimeImmutable('2026-05-01 10:00:00'),
                    new \DateTimeImmutable('2026-05-01 12:00:00'),
                ),
            ],
        );

        self::assertSame('01.05.2026 10:00', $formatter->format($row, $this->column('startsAt', 'datetime')));
        self::assertSame('Demo Hospital', $formatter->format($row, $this->column('hospital')));
        self::assertSame('Group; demo', $formatter->format($row, $this->column('event')));
        self::assertSame('Trauma; Orthopedics', $formatter->format($row, $this->column('departments')));
        self::assertSame(2, $formatter->format($row, $this->column('closureCount', 'number')));
        self::assertSame('Emergency; Inpatient', $formatter->format($row, $this->column('careLevels')));
        self::assertSame('4 h', $formatter->format($row, $this->column('summedMinutes')));
        self::assertSame('No beds', $formatter->format($row, $this->column('reasons')));
        self::assertStringNotContainsString('<', (string) $formatter->format($row, $this->column('event')));
    }

    public function testFormatsIntervalCells(): void
    {
        $formatter = $this->formatter();
        $row = new ClosureIntervalTableRow(
            7,
            'interval:7',
            ClosureEventType::Single,
            'Demo Hospital',
            'Surgery',
            'Trauma',
            new \DateTimeImmutable('2026-05-01 10:00:00', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-05-01 12:00:00', new \DateTimeZone('Europe/Berlin')),
            'emergency',
            'no_bed_capacity',
            'Ward 1',
            null,
            120,
        );

        self::assertSame('Single', $formatter->format($row, $this->column('event')));
        self::assertSame('Trauma', $formatter->format($row, $this->column('department')));
        self::assertSame('Emergency', $formatter->format($row, $this->column('careLevel')));
        self::assertSame('2 h', $formatter->format($row, $this->column('durationMinutes')));
        self::assertSame('No beds', $formatter->format($row, $this->column('reason')));
        self::assertSame('Ward 1', $formatter->format($row, $this->column('closureUnit')));
    }

    private function formatter(): ClosureTableCsvCellFormatter
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => match ($id) {
                'stats.closure.events.group' => 'Group',
                'stats.closure.events.single' => 'Single',
                'label.urgency.emergency' => 'Emergency',
                'label.urgency.inpatient' => 'Inpatient',
                'stats.closure.reason.no_bed_capacity' => 'No beds',
                default => $id,
            },
        );

        return new ClosureTableCsvCellFormatter($translator, new DataTableValueResolver());
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function column(string $key, string $type = 'custom', array $extra = []): DataTableColumn
    {
        return DataTableColumn::fromArray([
            'key' => $key,
            'label' => $key,
            'type' => $type,
            'format' => 'd.m.Y H:i',
            'timezone' => 'Europe/Berlin',
            ...$extra,
        ]);
    }
}
