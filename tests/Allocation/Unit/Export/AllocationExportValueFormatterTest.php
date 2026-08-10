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
    public function testFormatsGenderTransportAndUrgencyForExport(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => match ($id) {
                'label.gender.male' => 'de' === $locale ? 'Männlich' : 'Male',
                'label.transportType.ground' => 'de' === $locale ? 'Boden' : 'Ground',
                'label.transportType.air' => 'de' === $locale ? 'Luft' : 'Air',
                default => $id,
            },
        );

        $formatter = new AllocationExportValueFormatter($translator);

        self::assertSame('Male', $formatter->gender(AllocationGender::MALE));
        self::assertSame('SK1', $formatter->urgency(AllocationUrgency::EMERGENCY));
        self::assertSame('Ground', $formatter->transportType(AllocationTransportType::GROUND));
        self::assertSame('Air', $formatter->transportType('A'));
        self::assertSame('Männlich', $formatter->gender(AllocationGender::MALE, 'de'));
        self::assertSame('Luft', $formatter->transportType(AllocationTransportType::AIR, 'de'));
    }
}
