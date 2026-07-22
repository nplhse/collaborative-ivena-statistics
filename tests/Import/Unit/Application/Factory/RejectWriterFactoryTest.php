<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Factory;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Factory\RejectWriterFactory;
use PHPUnit\Framework\TestCase;

final class RejectWriterFactoryTest extends TestCase
{
    private RejectWriterInterface $dbWriter;
    private RejectWriterInterface $csvWriter;

    protected function setUp(): void
    {
        $this->dbWriter = $this->createStub(RejectWriterInterface::class);
        $this->dbWriter->method('getType')->willReturn('db');

        $this->csvWriter = $this->createStub(RejectWriterInterface::class);
        $this->csvWriter->method('getType')->willReturn('csv');
    }

    public function testCreateWithoutArgumentReturnsDefaultTypeWriter(): void
    {
        $factory = new RejectWriterFactory([$this->dbWriter, $this->csvWriter], 'db');

        self::assertSame($this->dbWriter, $factory->create());
    }

    public function testCreateWithExplicitTypeReturnsMatchingWriter(): void
    {
        $factory = new RejectWriterFactory([$this->dbWriter, $this->csvWriter], 'db');

        self::assertSame($this->csvWriter, $factory->create('csv'));
    }

    public function testCreateWithUnknownTypeThrowsInvalidArgumentException(): void
    {
        $factory = new RejectWriterFactory([$this->dbWriter, $this->csvWriter], 'db');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Available/');

        $factory->create('unknown');
    }
}
