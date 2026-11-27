<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Media\MediaStorage;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MediaCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanOpenMediaLibraryIndex(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'media-admin-'.bin2hex(random_bytes(4))]);

        MediaFactory::createOne([
            'originalFilename' => 'list-test.png',
            'title' => 'List test image',
            'type' => MediaType::IMAGE,
        ]);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/media');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Media library');
        self::assertSelectorTextContains('body', 'List test image');

        $crawler = $client->getCrawler();
        $headerText = implode(' | ', $crawler->filter('table thead th')->each(static fn ($node): string => trim($node->text())));
        self::assertStringNotContainsString('Created', $headerText);
        self::assertStringNotContainsString('Updated', $headerText);
    }

    public function testAdminCanOpenMediaDetailWithEnumType(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'media-detail-'.bin2hex(random_bytes(4))]);

        $media = MediaFactory::createOne([
            'originalFilename' => 'detail-test.png',
        ]);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/media/'.$media->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'detail-test.png');
        self::assertSelectorExists('[data-controller="copy-to-clipboard"]');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString("document.getElementById('media-snippet-copy')", $html);
        self::assertStringNotContainsString('id="media-snippet-copy"', $html);
    }

    public function testMediaNewFormHasNoInlineOnchangeOnFileInput(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'media-new-'.bin2hex(random_bytes(4))]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/media/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="file-input-preview"]');
        $fileInput = $crawler->filter('input[type="file"]');
        self::assertGreaterThan(0, $fileInput->count());
        self::assertNull($fileInput->attr('onchange'));
        self::assertStringContainsString('file-input-preview#preview', (string) $fileInput->attr('data-action'));
    }

    public function testNonAdminGetsForbiddenOnMediaIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'media-user-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/media');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRemovesDatabaseRecordAndStoredFile(): void
    {
        self::createClient();

        UserFactory::new()->asAdmin()->create();
        $media = MediaFactory::createOne();
        $id = $media->getId();
        $path = self::getContainer()->get(MediaStorage::class)->absolutePath((string) $media->getStorageKey());

        self::assertFileExists($path);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entity = $em->find(Media::class, $id);
        self::assertInstanceOf(Media::class, $entity);
        $em->remove($entity);
        $em->flush();

        self::assertNull($em->find(Media::class, $id));
        self::assertFileDoesNotExist($path);
    }
}
