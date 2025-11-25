<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Twig\Extension;

use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Util\ScopeLabelFormatter;
use App\Statistics\UI\Twig\Extension\ScopeLabelExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ScopeLabelExtensionTest extends TestCase
{
    /** @var ScopeLabelFormatter&MockObject */
    private ScopeLabelFormatter $formatter;

    protected function setUp(): void
    {
        /** @var ScopeLabelFormatter&MockObject $formatter */
        $formatter = $this->createMock(ScopeLabelFormatter::class);
        $this->formatter = $formatter;
    }

    public function testFilterIsRegisteredAndDelegatesToFormatter(): void
    {
        // Arrange
        $extension = new ScopeLabelExtension($this->formatter);

        $scope = new Scope('hospital', '123', 'all', 'ignored');

        $this->formatter
            ->expects(self::once())
            ->method('format')
            ->with($scope)
            ->willReturn('Hospital: Saint Mary');

        // Act
        $filters = $extension->getFilters();

        self::assertCount(1, $filters);
        self::assertInstanceOf(TwigFilter::class, $filters[0]);
        self::assertSame('scope_label', $filters[0]->getName());

        // Call filter manually
        $callable = $filters[0]->getCallable();
        $result = $callable($scope);

        // Assert
        self::assertSame('Hospital: Saint Mary', $result);
    }

    public function testFunctionIsRegisteredAndDelegatesToFormatter(): void
    {
        // Arrange
        $extension = new ScopeLabelExtension($this->formatter);

        $scope = new Scope('state', 'BY', 'month', '2025-11-01');

        $this->formatter
            ->expects(self::once())
            ->method('format')
            ->with($scope)
            ->willReturn('November 2025 – State: Bavaria');

        // Act
        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('scope_label', $functions[0]->getName());

        // Call function manually
        $callable = $functions[0]->getCallable();
        $result = $callable($scope);

        // Assert
        self::assertSame('November 2025 – State: Bavaria', $result);
    }
}
