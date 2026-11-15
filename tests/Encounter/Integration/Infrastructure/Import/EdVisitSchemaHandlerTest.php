<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Infrastructure\Import\EdVisitSchemaHandler;
use App\Tests\Support\Encounter\Probe\ProbeRow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EdVisitSchemaHandlerTest extends KernelTestCase
{
    public function testRowsOfAnotherSchemaAreRefused(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(EncounterSchemaHandlerRegistry::class);
        self::assertInstanceOf(EncounterSchemaHandlerRegistry::class, $registry);
        $handler = $registry->find('ed_visit');
        self::assertInstanceOf(EdVisitSchemaHandler::class, $handler);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The ed_visit handler got a row of '.ProbeRow::class.'.');

        $handler->write(new SchemaWriteContext(1, 1, 1), [new ProbeRow(3, 'a', 'b', null)]);
    }
}
