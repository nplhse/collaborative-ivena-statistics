<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Media\MediaStorage;
use App\Content\Infrastructure\Repository\MediaRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Upload, replacement and deletion of media files through the media library (ADR 039).
 */
#[ResetDatabase]
final class MediaUploadTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string PNG_3X2 = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgwAQAABQAAWX1h1kAAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $temporaryFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testUploadStoresTheFileInTheMonthOfTheUploadWithChecksumAndDimensions(): void
    {
        $client = $this->adminClient();
        self::mockTime('2026-11-21 10:00:00');

        $media = $this->upload($client, $this->png(), 'Team photo.png');

        self::assertMatchesRegularExpression('#^originals/2026/11/[0-9a-f-]{36}\.png$#', (string) $media->getStorageKey());
        self::assertNull($media->getFilename());
        self::assertSame('Team photo.png', $media->getOriginalFilename());
        self::assertSame('image/png', $media->getMimeType());
        self::assertSame(MediaType::IMAGE, $media->getType());
        self::assertSame([3, 2], [$media->getWidth(), $media->getHeight()]);

        $bytes = (string) base64_decode(self::PNG_3X2, true);
        self::assertSame(\strlen($bytes), $media->getSize());
        self::assertSame(hash('sha256', $bytes), $media->getChecksumSha256());
        self::assertSame(hash('sha256', $bytes), hash_file('sha256', $this->storage()->absolutePath((string) $media->getStorageKey())));
    }

    public function testDetailPageShowsTheNewUrl(): void
    {
        $client = $this->adminClient();
        $media = $this->upload($client, $this->png(), 'preview.png');

        $client->request(Request::METHOD_GET, '/admin/media/'.$media->getId());

        self::assertResponseIsSuccessful();
        $url = '/media/'.substr((string) $media->getStorageKey(), \strlen('originals/'));
        self::assertSelectorExists(sprintf('[data-testid="media-detail-preview"][src="%s"]', $url));
        self::assertStringContainsString($url, (string) $client->getCrawler()->filter('#media-snippet')->text());
    }

    public function testReplacingTheFileRemovesTheOldFileAfterSaving(): void
    {
        $client = $this->adminClient();
        $media = $this->upload($client, $this->png(), 'first.png');
        $oldKey = (string) $media->getStorageKey();

        $crawler = $client->request(Request::METHOD_GET, '/admin/media/'.$media->getId().'/edit');
        $form = $crawler->filter('form[name="Media"]')->form();
        $this->fileField($form)->upload($this->pdf());
        $client->submit($form);

        self::assertResponseRedirects();
        $replaced = $this->find((int) $media->getId());
        self::assertNotSame($oldKey, $replaced->getStorageKey());
        self::assertSame(MediaType::PDF, $replaced->getType());
        self::assertNull($replaced->getWidth());
        self::assertFalse(is_file($this->storage()->absolutePath($oldKey)), 'the replaced file is removed');
        self::assertTrue(is_file($this->storage()->absolutePath((string) $replaced->getStorageKey())));
    }

    public function testDeletingAMediaItemRemovesItsFile(): void
    {
        $client = $this->adminClient();
        $media = $this->upload($client, $this->png(), 'delete.png');
        $key = (string) $media->getStorageKey();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->remove($this->find((int) $media->getId()));
        $entityManager->flush();

        self::assertFalse(is_file($this->storage()->absolutePath($key)));
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    private function upload(KernelBrowser $client, string $path, string $name): Media
    {
        $crawler = $client->request(Request::METHOD_GET, '/admin/media/new');
        $form = $crawler->filter('form[name="Media"]')->form();
        $this->fileField($form)->upload($this->renamed($path, $name));
        $form['Media[title]'] = $name;
        $client->submit($form);

        self::assertResponseRedirects();
        $media = self::getContainer()->get(MediaRepository::class)->findOneBy(['title' => $name]);
        self::assertInstanceOf(Media::class, $media);

        return $media;
    }

    private function fileField(Form $form): FileFormField
    {
        $field = $form->get('Media[file]');
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }

    private function find(int $id): Media
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();
        $media = $entityManager->find(Media::class, $id);
        self::assertInstanceOf(Media::class, $media);

        return $media;
    }

    private function storage(): MediaStorage
    {
        return self::getContainer()->get(MediaStorage::class);
    }

    private function png(): string
    {
        return $this->temporaryFile('.png', (string) base64_decode(self::PNG_3X2, true));
    }

    private function pdf(): string
    {
        return $this->temporaryFile('.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function temporaryFile(string $suffix, string $bytes): string
    {
        $path = sys_get_temp_dir().'/media-upload-'.bin2hex(random_bytes(4)).$suffix;
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** Copy of a file under the given name, so the upload carries it as the original file name. */
    private function renamed(string $path, string $name): string
    {
        $directory = sys_get_temp_dir().'/media-upload-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $target = $directory.'/'.$name;
        copy($path, $target);
        $this->temporaryFiles[] = $target;

        return $target;
    }
}
