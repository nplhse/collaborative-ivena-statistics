<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\UI\Twig;

use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Twig\StatisticsNavigationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StatisticsNavigationExtensionTest extends TestCase
{
    public function testHospitalCatalogKeysUseAllocationDomain(): void
    {
        $extension = new StatisticsNavigationExtension(
            new StatisticsNavigationUrlBuilder($this->createStub(UrlGeneratorInterface::class)),
            new RequestStack(),
        );

        self::assertSame('allocation', $extension->statisticsLabelDomain('hospital.size.Medium'));
        self::assertSame('allocation', $extension->statisticsLabelDomain('hospital.tier.Full'));
        self::assertSame('allocation', $extension->statisticsLabelDomain('hospital.location.Urban'));
        self::assertSame('statistics', $extension->statisticsLabelDomain('stats.case_flow.size.Medium'));
        self::assertSame('messages', $extension->statisticsLabelDomain('label.gender'));
        self::assertSame('shared', $extension->statisticsLabelDomain('link.explore'));
    }
}
