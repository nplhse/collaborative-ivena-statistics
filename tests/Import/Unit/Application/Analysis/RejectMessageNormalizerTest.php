<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Analysis;

use App\Import\Application\Analysis\RejectMessageNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RejectMessageNormalizerTest extends TestCase
{
    private RejectMessageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RejectMessageNormalizer();
    }

    public function testValidatorMessageExtractsFieldAndEmptyValue(): void
    {
        $result = $this->normalizer->normalize(
            'createdAt: This value should not be blank.',
            ['datum_erstellungsdatum' => ''],
        );

        self::assertSame('createdAt', $result['field']);
        self::assertSame('(empty)', $result['rejected_value']);
        self::assertSame('createdAt: This value should not be blank.', $result['reason']);
    }

    public function testRefNotFoundExtractsFieldAndQuotedValue(): void
    {
        $message = 'REF_NOT_FOUND | Reference not found for "speciality" | field=speciality | value="Innere Medizin"';

        $result = $this->normalizer->normalize($message, ['fachgebiet' => 'Innere Medizin']);

        self::assertSame('speciality', $result['field']);
        self::assertSame('Innere Medizin', $result['rejected_value']);
    }

    public function testRowKeyResolvedFromDtoFieldMap(): void
    {
        $message = 'REF_NOT_FOUND | field=speciality | value="Unknown"';

        $result = $this->normalizer->normalize($message, ['fachgebiet' => 'Kardiologie']);

        self::assertSame('speciality', $result['field']);
        self::assertSame('Unknown', $result['rejected_value']);
    }

    public function testDispatchAreaRejectUsesZuweisungDurchColumn(): void
    {
        $message = 'REF_NOT_FOUND | Reference not found for "dispatchArea" | field=dispatchArea';

        $result = $this->normalizer->normalize($message, [
            'zuweisung_durch' => 'Berlin (Disponent)',
            'versorgungsbereich' => 'Leitstelle Waldeck-Frankenberg',
        ]);

        self::assertSame('dispatchArea', $result['field']);
        self::assertSame('Berlin (Disponent)', $result['rejected_value']);
    }

    public function testDispatchAreaRejectUsesVersorgungsbereichForKoordinierungsstelle(): void
    {
        $message = 'REF_NOT_FOUND | field=dispatchArea';

        $result = $this->normalizer->normalize($message, [
            'zuweisung_durch' => 'Koordinierungsstelle für Sekundärtransporte - HE (Einsatzbearbeiter)',
            'versorgungsbereich' => 'Leitstelle Frankfurt',
        ]);

        self::assertSame('dispatchArea', $result['field']);
        self::assertSame('Leitstelle Frankfurt', $result['rejected_value']);
    }

    public function testUnknownMessageUsesUnknownField(): void
    {
        $result = $this->normalizer->normalize('Unable to detect a supported row type.', []);

        self::assertSame('(unknown)', $result['field']);
        self::assertSame('', $result['rejected_value']);
    }

    #[DataProvider('forClauseFieldProvider')]
    public function testForClauseExtractsField(string $message, string $expectedField): void
    {
        $result = $this->normalizer->normalize($message, []);

        self::assertSame($expectedField, $result['field']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function forClauseFieldProvider(): iterable
    {
        yield 'import exception' => [
            'Reference not found for "department"',
            'department',
        ];
    }
}
