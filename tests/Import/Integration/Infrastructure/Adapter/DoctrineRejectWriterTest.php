<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Infrastructure\Adapter;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Infrastructure\Adapter\DoctrineRejectWriter;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineRejectWriterTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testStartWriteCloseCreatesOneImportReject(): void
    {
        $hospital = HospitalFactory::createOne();
        $import = ImportFactory::createOne(['hospital' => $hospital]);

        $writer = new DoctrineRejectWriter($this->em);

        $writer->start($import);
        $writer->write(['age' => 'not-a-number'], ['age: Invalid age'], 3);
        $writer->close();

        self::assertSame(1, $writer->getCount());
        self::assertNull($writer->getPath());
        self::assertSame('db', $writer->getType());

        $reject = $this->em->getRepository(ImportReject::class)->findOneBy([]);
        self::assertInstanceOf(ImportReject::class, $reject);
        self::assertSame(3, $reject->getLineNumber());
        self::assertSame(['age: Invalid age'], $reject->getMessages());
        self::assertSame(['age' => 'not-a-number'], $reject->getRow());
        self::assertSame($import->getId(), $reject->getImport()?->getId());
    }

    public function testWriteBeforeStartThrowsLogicException(): void
    {
        $writer = new DoctrineRejectWriter($this->em);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Reject writer not started. Call start() before write().');

        $writer->write(['age' => 'not-a-number'], ['age: Invalid age'], 1);
    }

    public function testStartWithUnpersistedImportThrowsLogicException(): void
    {
        $writer = new DoctrineRejectWriter($this->em);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Import has no id assigned yet.');

        $writer->start(new Import());
    }
}
