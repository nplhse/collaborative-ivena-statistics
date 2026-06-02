<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class MediaCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testAdminCanOpenMediaLibraryIndex(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'media-admin-'.bin2hex(random_bytes(4))]);

        MediaFactory::createOne([
            'filename' => 'list-test.png',
            'originalFilename' => 'list-test.png',
            'title' => 'List test image',
            'type' => MediaType::IMAGE,
        ]);

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/media')
            ->assertSuccessful()
            ->assertSee('Media library')
            ->assertSee('List test image')
        ;
    }

    public function testAdminCanOpenMediaDetailWithEnumType(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'media-detail-'.bin2hex(random_bytes(4))]);

        $media = MediaFactory::createOne([
            'filename' => 'detail-test.png',
            'originalFilename' => 'detail-test.png',
        ]);

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/media/'.$media->getId())
            ->assertSuccessful()
            ->assertSee('detail-test.png')
        ;
    }

    public function testNonAdminGetsForbiddenOnMediaIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'media-user-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/media')
            ->assertStatus(403)
        ;
    }

    public function testDeleteRemovesDatabaseRecordAndFile(): void
    {
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
