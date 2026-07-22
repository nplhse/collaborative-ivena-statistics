<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Adapter;

use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Adapter\SplCsvRejectWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SplCsvRejectWriterTest extends TestCase
{
    private string $rejectsBaseDir;
    private Filesystem $filesystem;
    private SplCsvRejectWriter $writer;

    protected function setUp(): void
    {
        $this->rejectsBaseDir = sys_get_temp_dir().'/'.uniqid('spl-csv-reject-writer-', true);
        $this->filesystem = new Filesystem();
        $this->writer = new SplCsvRejectWriter($this->filesystem, $this->rejectsBaseDir);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->rejectsBaseDir)) {
            $this->filesystem->remove($this->rejectsBaseDir);
        }

        parent::tearDown();
    }

    private function createImportStub(): Import
    {
        $import = $this->createStub(Import::class);
        $import->method('getId')->willReturn(42);

        return $import;
    }

    public function testStartCreatesFileWithHeaderAndResetsCount(): void
    {
        $this->writer->start($this->createImportStub());

        self::assertNotNull($this->writer->getPath());
        self::assertFileExists($this->writer->getPath());
        self::assertSame(0, $this->writer->getCount());

        $content = file_get_contents((string) $this->writer->getPath());
        self::assertNotFalse($content);
        self::assertStringContainsString('line;error_messages;row_json', $content);
    }

    public function testWriteAfterStartIncrementsCountAndAppendsLine(): void
    {
        $this->writer->start($this->createImportStub());

        $this->writer->write(['field' => 'value'], ['boom'], 7);

        self::assertSame(1, $this->writer->getCount());

        $content = file_get_contents((string) $this->writer->getPath());
        self::assertNotFalse($content);
        self::assertStringContainsString('7', $content);
        self::assertStringContainsString('boom', $content);
    }

    public function testWriteBeforeStartThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);

        $this->writer->write(['field' => 'value'], ['boom'], 1);
    }

    public function testGetTypeReturnsCsv(): void
    {
        self::assertSame('csv', $this->writer->getType());
    }

    public function testCloseAfterStartDoesNotThrow(): void
    {
        $this->writer->start($this->createImportStub());

        $this->writer->close();

        $this->addToAssertionCount(1);
    }
}
