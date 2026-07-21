<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Infrastructure;

use App\Admin\Infrastructure\EasyAdminAdminLinkGenerator;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

final class EasyAdminAdminLinkGeneratorTest extends TestCase
{
    public function testHospitalCrudIndexUrlUsesHospitalController(): void
    {
        $generator = $this->createMock(AdminUrlGeneratorInterface::class);
        $generator->expects(self::once())
            ->method('setController')
            ->with(HospitalCrudController::class)
            ->willReturnSelf();
        $generator->expects(self::once())
            ->method('generateUrl')
            ->willReturn('/admin/hospital');

        self::assertSame(
            '/admin/hospital',
            new EasyAdminAdminLinkGenerator($generator)->hospitalCrudIndexUrl(),
        );
    }
}
