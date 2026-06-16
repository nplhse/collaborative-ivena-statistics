<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Factory\MediaFactory;
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
            'filename' => 'list-test.png',
            'originalFilename' => 'list-test.png',
            'title' => 'List test image',
            'type' => MediaType::IMAGE,
        ]);

        $client->loginUser($admin->_real());
        $client->request(Request::METHOD_GET, '/admin/media');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Media library');
        self::assertSelectorTextContains('body', 'List test image');
    }

    public function testAdminCanOpenMediaDetailWithEnumType(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'media-detail-'.bin2hex(random_bytes(4))]);

        $media = MediaFactory::createOne([
            'filename' => 'detail-test.png',
            'originalFilename' => 'detail-test.png',
        ]);

        $client->loginUser($admin->_real());
        $client->request(Request::METHOD_GET, '/admin/media/'.$media->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'detail-test.png');
    }

    public function testNonAdminGetsForbiddenOnMediaIndex(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne([
            'username' => 'media-user-'.bin2hex(random_bytes(4)),
        ]);

        $client->loginUser($user->_real());
        $client->request(Request::METHOD_GET, '/admin/media');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRemovesDatabaseRecordAndFile(): void
    {
        self::createClient();

        UserFactory::new()->asAdmin()->create();
        $media = MediaFactory::createOne(['filename' => 'delete-me.png']);
        $id = $media->getId();
        $path = self::getContainer()->getParameter('kernel.project_dir').'/public/uploads/media/delete-me.png';

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
