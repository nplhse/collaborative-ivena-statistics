<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLabelResolver;
use App\Statistics\Domain\Entity\SavedExplorerView;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SavedExplorerViewLabelResolverTest extends TestCase
{
    public function testResolvesSystemViewTitleViaTranslator(): void
    {
        $view = new SavedExplorerView(
            slug: 'allocations-over-time',
            title: 'stats.analysis_explorer.system_view.allocations-over-time.title',
            category: 'Allocations',
            configJson: ['schemaVersion' => 3],
            description: 'stats.analysis_explorer.system_view.allocations-over-time.description',
            isSystem: true,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('stats.analysis_explorer.system_view.allocations-over-time.title', [], 'statistics')
            ->willReturn('Allocations over time');

        $resolver = new SavedExplorerViewLabelResolver($translator);

        self::assertSame('Allocations over time', $resolver->title($view));
    }

    public function testReturnsPlainTextForUserView(): void
    {
        $view = new SavedExplorerView(
            slug: null,
            title: 'My custom analysis',
            category: 'My views',
            configJson: ['schemaVersion' => 3],
            description: 'User description',
            isSystem: false,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $resolver = new SavedExplorerViewLabelResolver($translator);

        self::assertSame('My custom analysis', $resolver->title($view));
        self::assertSame('User description', $resolver->description($view));
    }

    public function testDescriptionReturnsNullWhenUnset(): void
    {
        $view = new SavedExplorerView(
            slug: null,
            title: 'Untitled',
            category: 'My views',
            configJson: ['schemaVersion' => 3],
            isSystem: false,
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $resolver = new SavedExplorerViewLabelResolver($translator);

        self::assertNull($resolver->description($view));
    }
}
