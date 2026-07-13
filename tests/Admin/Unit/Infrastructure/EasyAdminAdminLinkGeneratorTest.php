<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Infrastructure;

use App\Admin\Infrastructure\EasyAdminAdminLinkGenerator;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class EasyAdminAdminLinkGeneratorTest extends TestCase
{
    public function testHospitalCrudIndexUrlUsesAdminUrlGenerator(): void
    {
        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->expects(self::once())
            ->method('setController')
            ->with(HospitalCrudController::class)
            ->willReturnSelf();
        $adminUrlGenerator->expects(self::once())
            ->method('generateUrl')
            ->willReturn('/admin/hospital');

        $generator = new EasyAdminAdminLinkGenerator($adminUrlGenerator);

        self::assertSame('/admin/hospital', $generator->hospitalCrudIndexUrl());
    }
}
