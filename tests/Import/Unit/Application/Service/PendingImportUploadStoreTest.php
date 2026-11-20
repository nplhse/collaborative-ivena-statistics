<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Service\PendingImportUpload;
use App\Import\Application\Service\PendingImportUploadStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PendingImportUploadStoreTest extends TestCase
{
    private string $baseDir;
    private MockClock $clock;
    private PendingImportUploadStore $store;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/pending-upload-store-'.bin2hex(random_bytes(4));
        $this->clock = new MockClock('2026-11-20 12:00:00', 'Europe/Berlin');
        $this->store = new PendingImportUploadStore($this->baseDir, new Filesystem(), $this->clock);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->baseDir);
    }

    public function testAStoredFileIsFoundByItsTokenForItsUserOnly(): void
    {
        $upload = $this->store->store($this->upload('export.csv', "a;b\n1;2\n"), 7);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $upload->token);
        self::assertSame('export.csv', $upload->originalName);
        self::assertSame(8, $upload->size);
        self::assertFileExists($this->baseDir.'/pending/7/'.$upload->token.'.upload');

        $found = $this->store->find($upload->token, 7);
        self::assertInstanceOf(PendingImportUpload::class, $found);
        self::assertSame('export.csv', $found->originalName);
        self::assertEquals(new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('Europe/Berlin')), $found->uploadedAt);

        self::assertNull($this->store->find($upload->token, 8), 'Another user cannot reach the file with the token.');
        self::assertNull($this->store->find('../7/'.$upload->token, 8), 'A path is no token.');
        self::assertNull($this->store->find(strtoupper($upload->token), 7));
    }

    public function testTheUploadedFileKeepsTheClientNameAndCanBeMovedLikeAnUpload(): void
    {
        $upload = $this->store->store($this->upload('export.csv', "a;b\n"), 7);

        $file = $this->store->uploadedFile($upload->token, 7);
        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame('export.csv', $file->getClientOriginalName());
        self::assertTrue($file->isValid());

        $file->move($this->baseDir.'/imports', 'moved.csv');
        self::assertFileExists($this->baseDir.'/imports/moved.csv');
        self::assertNull($this->store->find($upload->token, 7), 'A moved file is consumed.');
    }

    public function testDeleteRemovesFileAndMetadataOfTheUserOnly(): void
    {
        $upload = $this->store->store($this->upload('export.csv', 'x'), 7);

        $this->store->delete($upload->token, 8);
        self::assertNotNull($this->store->find($upload->token, 7), 'The token of another user deletes nothing.');

        $this->store->delete($upload->token, 7);
        self::assertNull($this->store->find($upload->token, 7));
        self::assertFileDoesNotExist($this->baseDir.'/pending/7/'.$upload->token.'.json');
    }

    public function testPurgeDeletesFilesOlderThanTheRetention(): void
    {
        $old = $this->store->store($this->upload('old.csv', 'x'), 7);
        $fresh = $this->store->store($this->upload('fresh.csv', 'y'), 8);

        $now = $this->clock->now()->getTimestamp();
        // 24 h 1 min and 23 h 59 min before "now": only the first is past the 24 h retention.
        foreach (['upload', 'json'] as $extension) {
            touch($this->baseDir.'/pending/7/'.$old->token.'.'.$extension, $now - 24 * 3600 - 60);
            touch($this->baseDir.'/pending/8/'.$fresh->token.'.'.$extension, $now - 24 * 3600 + 60);
        }

        self::assertSame(2, $this->store->purgeExpired(), 'File and metadata of the old upload.');
        self::assertNull($this->store->find($old->token, 7));
        self::assertNotNull($this->store->find($fresh->token, 8));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedMetadata(): iterable
    {
        yield 'no JSON' => ['{'];
        yield 'not an object' => ['"export.csv"'];
        yield 'missing size' => ['{"originalName":"a.csv","mimeType":"text/csv","uploadedAt":"2026-11-20T12:00:00+01:00"}'];
        yield 'unreadable time' => ['{"originalName":"a.csv","mimeType":"text/csv","size":1,"uploadedAt":"yesterday"}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedMetadata')]
    public function testDamagedMetadataMakesTheUploadUnknown(string $metadata): void
    {
        $upload = $this->store->store($this->upload('export.csv', 'x'), 7);
        file_put_contents($this->baseDir.'/pending/7/'.$upload->token.'.json', $metadata);

        self::assertNull($this->store->find($upload->token, 7));
        self::assertNull($this->store->uploadedFile($upload->token, 7));
    }

    public function testDeleteIgnoresSomethingThatIsNoToken(): void
    {
        $upload = $this->store->store($this->upload('export.csv', 'x'), 7);

        $this->store->delete('../7/'.$upload->token, 7);

        self::assertNotNull($this->store->find($upload->token, 7));
    }

    public function testPurgeWithoutPendingUploadsDeletesNothing(): void
    {
        self::assertSame(0, $this->store->purgeExpired());
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pending-upload');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
