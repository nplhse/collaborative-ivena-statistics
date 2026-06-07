<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use App\Import\Infrastructure\Mapping\DispatchAreaSourceResolver;
use PHPUnit\Framework\TestCase;

final class DispatchAreaSourceResolverTest extends TestCase
{
    private DispatchAreaSourceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DispatchAreaSourceResolver();
    }

    public function testPrefersZuweisungDurchWhenPresent(): void
    {
        $source = $this->resolver->resolve([
            'zuweisung_durch' => 'Leitstelle Schwalm-Eder (Disponent)',
            'versorgungsbereich' => 'Leitstelle Waldeck-Frankenberg',
        ]);

        self::assertSame('Leitstelle Schwalm-Eder (Disponent)', $source->value);
        self::assertSame('zuweisung_durch', $source->column);
    }

    public function testFallsBackToVersorgungsbereichWhenZuweisungDurchEmpty(): void
    {
        $source = $this->resolver->resolve([
            'zuweisung_durch' => '',
            'versorgungsbereich' => 'Leitstelle Kassel',
        ]);

        self::assertSame('Leitstelle Kassel', $source->value);
        self::assertSame('versorgungsbereich', $source->column);
    }

    public function testUsesVersorgungsbereichForKoordinierungsstelle(): void
    {
        $source = $this->resolver->resolve([
            'zuweisung_durch' => 'Koordinierungsstelle für Sekundärtransporte - HE (Einsatzbearbeiter KST Hessen)',
            'versorgungsbereich' => 'Leitstelle Frankfurt',
        ]);

        self::assertSame('Leitstelle Frankfurt', $source->value);
        self::assertSame('versorgungsbereich', $source->column);
    }

    public function testResolveRowKeyMatchesSourceColumn(): void
    {
        $row = [
            'zuweisung_durch' => 'Koordinierungsstelle für Sekundärtransporte - HE (Einsatzbearbeiter)',
            'versorgungsbereich' => 'Leitstelle Frankfurt',
        ];

        self::assertSame('versorgungsbereich', $this->resolver->resolveRowKey($row));
    }
}
