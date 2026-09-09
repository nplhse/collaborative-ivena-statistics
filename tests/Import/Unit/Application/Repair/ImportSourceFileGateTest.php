<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Repair;

use App\Import\Application\Repair\ImportSourceFileGate;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Domain\Enum\ImportSourceFileStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ImportSourceFileGateTest extends TestCase
{
    private string $projectDir;
    private string $importsBaseDir;
    private Filesystem $filesystem;
    private ImportSourceFileGate $gate;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/import-gate-'.bin2hex(random_bytes(4));
        $this->importsBaseDir = Path::join($this->projectDir, 'var', 'imports');
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->importsBaseDir, 0775);
        $this->gate = new ImportSourceFileGate(new ImportFileStorage(
            $this->projectDir,
            $this->importsBaseDir,
            $this->filesystem,
            new NullLogger(),
        ));
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->projectDir)) {
            $this->filesystem->remove($this->projectDir);
        }

        parent::tearDown();
    }

    public function testReadyWhenFileExistsAndHasBytes(): void
    {
        $relative = 'var/imports/ready.csv';
        file_put_contents(Path::join($this->projectDir, $relative), "a;b\n1;2\n");

        self::assertSame(ImportSourceFileStatus::Ready, $this->gate->inspect($relative));
    }

    public function testEmptyPath(): void
    {
        self::assertSame(ImportSourceFileStatus::EmptyPath, $this->gate->inspect(null));
        self::assertSame(ImportSourceFileStatus::EmptyPath, $this->gate->inspect(''));
        self::assertSame(ImportSourceFileStatus::EmptyPath, $this->gate->inspect('   '));
    }

    public function testOutsideBase(): void
    {
        self::assertSame(ImportSourceFileStatus::OutsideBase, $this->gate->inspect('/etc/passwd'));
    }

    public function testNotFound(): void
    {
        self::assertSame(ImportSourceFileStatus::NotFound, $this->gate->inspect('var/imports/missing.csv'));
    }

    public function testEmptyFile(): void
    {
        $relative = 'var/imports/empty.csv';
        file_put_contents(Path::join($this->projectDir, $relative), '');

        self::assertSame(ImportSourceFileStatus::EmptyFile, $this->gate->inspect($relative));
    }

    public function testUnreadableFile(): void
    {
        $relative = 'var/imports/unreadable.csv';
        $absolute = Path::join($this->projectDir, $relative);
        file_put_contents($absolute, "a;b\n1;2\n");

        $previous = umask(0);
        chmod($absolute, 0000);
        umask($previous);

        try {
            if (is_readable($absolute)) {
                self::markTestSkipped('Process can still read chmod 000 files.');
            }

            self::assertSame(ImportSourceFileStatus::Unreadable, $this->gate->inspect($relative));
        } finally {
            chmod($absolute, 0644);
        }
    }
}
