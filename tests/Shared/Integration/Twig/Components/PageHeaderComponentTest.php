<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PageHeaderComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTitlePretitleAndSlots(): void
    {
        $html = (string) $this->renderTwigComponent(
            'PageHeader',
            [
                'title' => 'Case flow',
                'pretitle' => 'Analysis',
                'titleTestId' => 'stats-case-flow-heading-title',
                'pretitleTestId' => 'stats-case-flow-heading-subtitle',
            ],
            null,
            [
                'breadcrumbs' => '<nav data-testid="page-header-breadcrumbs">Home</nav>',
                'meta' => '<span data-testid="page-header-meta">Quality</span>',
                'context' => '<p data-testid="page-header-context">Description</p>',
                'actions' => '<button type="button" data-testid="page-header-action">Edit</button>',
            ],
        );

        self::assertStringContainsString('container-xl', $html);
        self::assertStringContainsString('page-header d-print-none', $html);
        self::assertStringContainsString('page-pretitle', $html);
        self::assertStringContainsString('data-testid="stats-case-flow-heading-subtitle"', $html);
        self::assertStringContainsString('Analysis', $html);
        self::assertStringContainsString('page-title mb-0', $html);
        self::assertStringContainsString('data-testid="stats-case-flow-heading-title"', $html);
        self::assertStringContainsString('Case flow', $html);
        self::assertStringContainsString('data-testid="page-header-breadcrumbs"', $html);
        self::assertStringContainsString('flex-shrink-0 d-print-none mt-2', $html);
        self::assertStringContainsString('data-testid="page-header-meta"', $html);
        self::assertStringContainsString('data-testid="page-header-context"', $html);
        self::assertStringContainsString('col-auto ms-auto d-print-none', $html);
        self::assertStringContainsString('btn-list', $html);
        self::assertStringContainsString('data-testid="page-header-action"', $html);
    }

    public function testOmitsBreadcrumbRowAndActionsWhenEmpty(): void
    {
        $html = (string) $this->renderTwigComponent('PageHeader', [
            'title' => 'Only title',
        ]);

        self::assertStringContainsString('Only title', $html);
        self::assertStringNotContainsString('d-flex align-items-start', $html);
        self::assertStringNotContainsString('btn-list', $html);
        self::assertStringNotContainsString('page-pretitle', $html);
    }

    public function testUsesCustomActionsClass(): void
    {
        $html = (string) $this->renderTwigComponent(
            'PageHeader',
            [
                'title' => 'Explorer',
                'actionsClass' => 'd-flex flex-wrap align-items-center justify-content-end gap-2',
                'actionsTestId' => 'stats-analysis-explorer-actions',
                'class' => 'mb-0',
            ],
            null,
            [
                'actions' => '<button type="button">Save</button>',
            ],
        );

        self::assertStringContainsString('page-header d-print-none mb-0', $html);
        self::assertStringContainsString('data-testid="stats-analysis-explorer-actions"', $html);
        self::assertStringContainsString('d-flex flex-wrap align-items-center justify-content-end gap-2', $html);
        self::assertStringNotContainsString('btn-list', $html);
    }
}
