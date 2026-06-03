<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use App\Content\Application\Media\MediaLibraryAdminUrlProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class MediaLibraryAdminUrlProviderTest extends TestCase
{
    public function testGetIndexUrlTargetsMediaLibraryIndex(): void
    {
        $generator = $this->createMock(AdminUrlGeneratorInterface::class);
        $generator->expects(self::once())
            ->method('setController')
            ->with(MediaCrudController::class)
            ->willReturnSelf();
        $generator->expects(self::once())
            ->method('setAction')
            ->with(Crud::PAGE_INDEX)
            ->willReturnSelf();
        $generator->expects(self::once())
            ->method('generateUrl')
            ->willReturn('/admin?crudAction=index&crudControllerFqcn=MediaCrudController');

        self::assertSame(
            '/admin?crudAction=index&crudControllerFqcn=MediaCrudController',
            new MediaLibraryAdminUrlProvider($generator)->getIndexUrl(),
        );
    }
}
