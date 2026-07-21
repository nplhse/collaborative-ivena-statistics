<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Infrastructure;

use App\Admin\Infrastructure\EasyAdminMediaLibraryUrlProvider;
use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class EasyAdminMediaLibraryUrlProviderTest extends TestCase
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
            ->willReturn('/admin/media');

        self::assertSame(
            '/admin/media',
            new EasyAdminMediaLibraryUrlProvider($generator)->getIndexUrl(),
        );
    }
}
