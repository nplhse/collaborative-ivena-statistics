<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Util;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Util\ScopeRoute;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ScopeRouteTest extends TestCase
{
    /** @var UrlGeneratorInterface&MockObject */
    private UrlGeneratorInterface $router;

    protected function setUp(): void
    {
        /** @var UrlGeneratorInterface&MockObject $router */
        $router = $this->createMock(UrlGeneratorInterface::class);
        $this->router = $router;
    }

    public function testToPathPassesCorrectRouteNameAndParams(): void
    {
        // Arrange
        $sut = new ScopeRoute($this->router);

        $scopeType = 'org';
        $scopeId = '42';
        $gran = 'day';
        $key = '2025-11-01';

        $expectedUrl = '/stats/org/42/day/2025-11-01';

        $this->router
            ->expects(self::once())
            ->method('generate')
            ->with(
                'app_stats_dashboard',
                [
                    'scopeType' => $scopeType,
                    'scopeId' => $scopeId,
                    'gran' => $gran,
                    'key' => $key,
                ]
            )
            ->willReturn($expectedUrl);

        // Act
        $result = $sut->toPath($scopeType, $scopeId, $gran, $key);

        // Assert
        self::assertSame($expectedUrl, $result);
    }

    public function testFromScopeDelegatesToToPathWithCorrectValues(): void
    {
        // Arrange
        $sut = new ScopeRoute($this->router);

        $scope = new Scope(
            scopeType: 'team',
            scopeId: 'abc',
            granularity: 'hour',
            periodKey: '2025-11-08-13'
        );

        $expectedUrl = '/stats/team/abc/hour/2025-11-08-13';

        $this->router
            ->expects(self::once())
            ->method('generate')
            ->with(
                'app_stats_dashboard',
                [
                    'scopeType' => 'team',
                    'scopeId' => 'abc',
                    'gran' => 'hour',
                    'key' => '2025-11-08-13',
                ]
            )
            ->willReturn($expectedUrl);

        // Act
        $result = $sut->fromScope($scope);

        // Assert
        self::assertSame($expectedUrl, $result);
    }
}
