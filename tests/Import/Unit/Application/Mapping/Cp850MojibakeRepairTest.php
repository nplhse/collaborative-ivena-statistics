<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Mapping;

use App\Import\Application\Mapping\Cp850MojibakeRepair;
use PHPUnit\Framework\TestCase;

final class Cp850MojibakeRepairTest extends TestCase
{
    public function testRepairsDosOemUmlautsStoredAsC1Controls(): void
    {
        self::assertSame('Häuslicher Einsatz', Cp850MojibakeRepair::repair("H\u{0084}uslicher Einsatz"));
        self::assertSame('Öffentlicher Raum', Cp850MojibakeRepair::repair("\u{0099}ffentlicher Raum"));
        self::assertTrue(Cp850MojibakeRepair::hasC1Controls("H\u{0084}uslicher Einsatz"));
        self::assertFalse(Cp850MojibakeRepair::hasC1Controls('Häuslicher Einsatz'));
    }

    public function testLeavesCleanUtf8Unchanged(): void
    {
        self::assertSame('Häuslicher Einsatz', Cp850MojibakeRepair::repair('Häuslicher Einsatz'));
        self::assertSame('aus Klinik', Cp850MojibakeRepair::repair('aus Klinik'));
    }

    public function testDoesNotGuessWhenRealUnicodeIsMixedIn(): void
    {
        $mixed = "H\u{0084}uslicher \u{1F697}";
        self::assertSame($mixed, Cp850MojibakeRepair::repair($mixed));
        self::assertTrue(Cp850MojibakeRepair::hasC1Controls($mixed));
    }
}
