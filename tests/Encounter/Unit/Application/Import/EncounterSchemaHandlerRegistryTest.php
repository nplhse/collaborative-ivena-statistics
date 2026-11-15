<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Import;

use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Tests\Support\Encounter\Probe\ProbeSchemaHandler;
use PHPUnit\Framework\TestCase;

final class EncounterSchemaHandlerRegistryTest extends TestCase
{
    public function testFindsHandlersBySchema(): void
    {
        $probe = new ProbeSchemaHandler();
        $registry = new EncounterSchemaHandlerRegistry([$probe]);

        self::assertTrue($registry->has('probe'));
        self::assertSame($probe, $registry->find('probe'));
        self::assertFalse($registry->has('inpatient_stay'));
        self::assertNull($registry->find('inpatient_stay'));
        self::assertSame([$probe], $registry->all());
    }

    public function testTwoHandlersForOneSchemaAreRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Encounter schema "probe" has two handlers');

        new EncounterSchemaHandlerRegistry([new ProbeSchemaHandler(), new ProbeSchemaHandler()]);
    }
}
