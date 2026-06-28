<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Export;

use App\Allocation\Application\Export\AllocationExportValueFormatter;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AllocationExportValueFormatterTest extends TestCase
{
    private AllocationExportValueFormatter $formatter;

    #[\Override]
    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'label.gender.male' => 'Male',
                'label.transportType.ground' => 'Ground',
                'label.transportType.air' => 'Air',
                default => $id,
            },
        );

        $this->formatter = new AllocationExportValueFormatter($translator);
    }

    public function testFormatsGenderTransportAndUrgencyForExport(): void
    {
        self::assertSame('Male', $this->formatter->gender(AllocationGender::MALE));
        self::assertSame('SK1', $this->formatter->urgency(AllocationUrgency::EMERGENCY));
        self::assertSame('Ground', $this->formatter->transportType(AllocationTransportType::GROUND));
        self::assertSame('Air', $this->formatter->transportType('A'));
    }
}
