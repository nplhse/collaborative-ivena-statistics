<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Export;

use App\Allocation\Application\Export\OwnHospitalAllocationsExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers defensive private helpers that the integration export path does not exercise.
 */
final class OwnHospitalAllocationsExporterHelpersTest extends TestCase
{
    public function testTranslateHeaderFallsBackToColumnKey(): void
    {
        $exporter = $this->exporterForHelpers();
        $method = new \ReflectionMethod(OwnHospitalAllocationsExporter::class, 'translateHeader');

        self::assertSame('unknownColumn', $method->invoke($exporter, 'unknownColumn', 'en'));
    }

    public function testFormatDateTimeCoversNonDateTimeBranches(): void
    {
        $exporter = $this->exporterForHelpers();
        $method = new \ReflectionMethod(OwnHospitalAllocationsExporter::class, 'formatDateTime');

        self::assertNull($method->invoke($exporter, null));
        self::assertSame('2026-01-15 10:00:00', $method->invoke($exporter, '2026-01-15 10:00:00'));
        self::assertSame('123', $method->invoke($exporter, 123));
        self::assertSame('1.5', $method->invoke($exporter, 1.5));
        self::assertNull($method->invoke($exporter, new \stdClass()));
    }

    public function testScalarCellCoversStringableAndNonScalarBranches(): void
    {
        $exporter = $this->exporterForHelpers();
        $method = new \ReflectionMethod(OwnHospitalAllocationsExporter::class, 'scalarCell');

        self::assertNull($method->invoke($exporter, null));
        self::assertSame('ok', $method->invoke($exporter, 'ok'));
        self::assertSame(7, $method->invoke($exporter, 7));
        self::assertSame(
            'Stringable Hospital',
            $method->invoke($exporter, new class implements \Stringable {
                public function __toString(): string
                {
                    return 'Stringable Hospital';
                }
            }),
        );
        self::assertNull($method->invoke($exporter, ['not-a-scalar']));
        self::assertNull($method->invoke($exporter, new \stdClass()));
    }

    public function testFormatBoolReturnsNullForMissingValues(): void
    {
        $exporter = $this->exporterForHelpers();
        $method = new \ReflectionMethod(OwnHospitalAllocationsExporter::class, 'formatBool');

        self::assertNull($method->invoke($exporter, null, 'en'));
        self::assertSame('export.boolean.true', $method->invoke($exporter, true, 'en'));
        self::assertSame('export.boolean.false', $method->invoke($exporter, false, 'en'));
    }

    private function exporterForHelpers(): OwnHospitalAllocationsExporter
    {
        $exporter = new \ReflectionClass(OwnHospitalAllocationsExporter::class)->newInstanceWithoutConstructor();

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id,
        );

        $reflection = new \ReflectionClass($exporter);
        $reflection->getProperty('translator')->setValue($exporter, $translator);
        $reflection->getProperty('requestStack')->setValue($exporter, new RequestStack());

        return $exporter;
    }
}
