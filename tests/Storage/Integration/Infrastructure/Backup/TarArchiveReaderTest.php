<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupComponent;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\FileComponentSource;
use App\Storage\Infrastructure\Backup\TarArchiveReader;
use App\Storage\Infrastructure\Backup\TarFileArchiver;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Reads archives of the system tar (GNU tar in CI, bsdtar on macOS) and hand-written ones for the entries a
 * backup must never contain.
 */
final class TarArchiveReaderTest extends TestCase
{
    private StorageFixtureDirectory $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testReadsWhatTheArchiverWroteAndMatchesItsChecksumList(): void
    {
        $long = str_repeat('deep-directory/', 8).'with-a-file-name-longer-than-one-hundred-characters.csv';
        $this->fixture->file('src/a.csv', 3);
        $this->fixture->file('src/.hidden', 1);
        $this->fixture->file('src/2026/empty.csv', 0);
        $this->fixture->file('src/2026/big.bin', 1_500_000);
        $this->fixture->file('src/'.$long, 7);
        $out = $this->fixture->directory('out');

        $archived = new TarFileArchiver($this->fixture->path)->archive(new FileComponentSource(BackupComponent::Imports, $this->fixture->path.'/src'), $out.'/x.tar.gz', $out.'/x.sha256');
        $entries = new TarArchiveReader()->entries($out.'/x.tar.gz');

        self::assertSame(5, $archived->files);
        self::assertSame(['.hidden', '2026/big.bin', '2026/empty.csv', 'a.csv', $long], array_keys($entries));
        self::assertSame(1_500_000, $entries['2026/big.bin']['size']);
        self::assertSame($archived->bytes, array_sum(array_column($entries, 'size')));

        $list = '';
        foreach ($entries as $path => $entry) {
            $list .= $entry['sha256'].'  '.$path."\n";
        }
        self::assertSame(file_get_contents($out.'/x.sha256'), $list);
    }

    public function testRejectsALinkPackedBySystemTar(): void
    {
        $this->fixture->file('src/a.csv', 3);
        $this->fixture->link('src/link.csv', 'a.csv');
        new Process(['tar', '-czf', $this->fixture->path.'/x.tar.gz', '-C', $this->fixture->path.'/src', '.'])->mustRun();

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('The archive contains link.csv, which is not a regular file.');

        new TarArchiveReader()->entries($this->fixture->path.'/x.tar.gz');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unsafeArchives(): iterable
    {
        yield 'parent directory' => [self::entry('./../evil.csv', '0', 'x'), 'The archive contains the unsafe path "./../evil.csv".'];
        yield 'absolute path' => [self::entry('/etc/evil', '0', 'x'), 'The archive contains the unsafe path "/etc/evil".'];
        yield 'hard link' => [self::entry('./a.csv', '0', 'x').self::entry('./b.csv', '1'), 'The archive contains b.csv, which is not a regular file.'];
        yield 'device' => [self::entry('./dev', '3'), 'The archive contains dev, which is not a regular file.'];
        yield 'GNU long link' => [self::entry('././@LongLink', 'K', 'target'), 'The archive contains a link.'];
        yield 'duplicate' => [self::entry('./a.csv', '0', 'x').self::entry('a.csv', '0', 'y'), 'The archive contains the file "a.csv" more than once or without a name.'];
    }

    #[DataProvider('unsafeArchives')]
    public function testRejectsEntriesARestoreMustNotExtract(string $tar, string $message): void
    {
        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage($message);

        $this->read($tar.$this->end());
    }

    public function testFollowsPaxAndGnuLongNames(): void
    {
        $pax = '21 path=pax/long.csv'."\n1 x"; // the trailing record is too short and ends the parsing
        $tar = self::entry('./PaxHeaders/global', 'g', "11 comment\n")
            .self::entry('./PaxHeaders/x', 'x', $pax).self::entry('./short-a', '0', 'aa')
            .self::entry('././@LongLink', 'L', "gnu/long.csv\0").self::entry('./short-b', '0', 'bbb')
            .self::entry('./dir/', '5')
            .self::entry('./plain.csv', '0', 'c')
            .self::entry('./base256.csv', '0', 'dddd', base256: true);

        self::assertSame([
            'base256.csv' => ['size' => 4, 'sha256' => hash('sha256', 'dddd')],
            'gnu/long.csv' => ['size' => 3, 'sha256' => hash('sha256', 'bbb')],
            'pax/long.csv' => ['size' => 2, 'sha256' => hash('sha256', 'aa')],
            'plain.csv' => ['size' => 1, 'sha256' => hash('sha256', 'c')],
        ], $this->read($tar.$this->end()));
    }

    public function testRejectsATruncatedOrDamagedArchive(): void
    {
        $tar = self::entry('./a.csv', '0', str_repeat('x', 2000)).$this->end();

        try {
            $this->read(substr($tar, 0, 1000));
            self::fail('A truncated archive should fail.');
        } catch (BackupFailed $failure) {
            self::assertSame('The archive ends unexpectedly.', $failure->getMessage());
        }

        $tar[3] = 'X';
        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('The archive has a damaged header.');
        $this->read($tar);
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('missing.tar.gz cannot be opened.');

        new TarArchiveReader()->entries($this->fixture->path.'/missing.tar.gz');
    }

    /**
     * @return array<string, array{size: int, sha256: string}>
     */
    private function read(string $tar): array
    {
        $file = $this->fixture->path.'/crafted.tar.gz';
        file_put_contents($file, gzencode($tar));

        return new TarArchiveReader()->entries($file);
    }

    /**
     * One ustar entry: header plus content padded to whole blocks.
     */
    private static function entry(string $name, string $type, string $content = '', bool $base256 = false): string
    {
        $header = str_pad($name, 100, "\0")
            .str_pad('0000644', 8, "\0")
            .str_pad('0001750', 8, "\0")
            .str_pad('0001750', 8, "\0")
            .($base256 ? "\x80\0\0\0".pack('J', \strlen($content)) : str_pad(sprintf('%011o', \strlen($content)), 12, "\0"))
            .str_pad(sprintf('%011o', 1_763_635_805), 12, "\0")
            .'        '
            .$type
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

        $sum = array_sum(array_map(ord(...), str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);

        $padding = (512 - \strlen($content) % 512) % 512;

        return $header.$content.str_repeat("\0", $padding);
    }

    private function end(): string
    {
        return str_repeat("\0", 1024);
    }
}
