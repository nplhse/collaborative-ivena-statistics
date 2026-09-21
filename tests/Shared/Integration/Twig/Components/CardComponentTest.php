<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class CardComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTitleBodyActionsToolbarAndFooter(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Card',
            [
                'title' => 'Importe',
                'status' => 'warning',
                'size' => 'md',
                'data-testid' => 'import-run',
            ],
            '<p>Laufdetails</p>',
            [
                'actions' => '<a class="btn btn-sm" href="/imports/new">Neu</a>',
                'toolbar' => '<div class="btn-list">Filter</div>',
                'footer' => '<span class="text-secondary">Stand heute</span>',
            ],
        );

        self::assertStringContainsString('card card-md', $html);
        self::assertStringContainsString('data-testid="import-run"', $html);
        self::assertStringContainsString('card-status-top bg-warning', $html);
        self::assertStringContainsString('<h3 class="card-title mb-0">Importe</h3>', $html);
        self::assertStringContainsString('card-actions', $html);
        self::assertStringContainsString('href="/imports/new"', $html);
        self::assertStringContainsString('btn-list', $html);
        self::assertStringContainsString('card-body', $html);
        self::assertStringContainsString('Laufdetails', $html);
        self::assertStringContainsString('card-footer', $html);
        self::assertStringContainsString('Stand heute', $html);
        self::assertStringNotContainsString('pagination', $html);
        self::assertSame(2, substr_count($html, 'card-header'));
    }

    public function testRendersHeaderWhenOnlyActionsAreSet(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Card',
            [],
            '<p>Inhalt</p>',
            [
                'actions' => '<button type="button">Export</button>',
            ],
        );

        self::assertStringContainsString('card-header', $html);
        self::assertStringContainsString('card-actions', $html);
        self::assertStringContainsString('Export', $html);
        self::assertStringNotContainsString('card-title', $html);
    }

    public function testOmitsHeaderToolbarFooterAndStatusWhenEmpty(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Card',
            ['padding' => 'none'],
            '<p>Nur Body</p>',
        );

        self::assertStringContainsString('card-body p-0', $html);
        self::assertStringContainsString('Nur Body', $html);
        self::assertStringNotContainsString('card-header', $html);
        self::assertStringNotContainsString('card-actions', $html);
        self::assertStringNotContainsString('card-footer', $html);
        self::assertStringNotContainsString('card-status-top', $html);
        self::assertStringNotContainsString('pagination', $html);
    }

    public function testUnknownStatusAndSizeRenderNoExtraChrome(): void
    {
        $html = (string) $this->renderTwigComponent('Card', [
            'title' => 'Hinweis',
            'size' => 'xl',
            'status' => 'azure',
            'padding' => 'compact',
        ]);

        self::assertStringContainsString('class="card"', $html);
        self::assertStringNotContainsString('card-xl', $html);
        self::assertStringNotContainsString('card-status-top', $html);
        self::assertStringContainsString('class="card-body"', $html);
        self::assertStringNotContainsString('p-0', $html);
        self::assertStringContainsString('Hinweis', $html);
    }

    public function testKpiCompositionUsesBodySlot(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Card',
            ['status' => 'primary'],
            '<div class="subheader">Fälle</div><div class="h1 mb-0">1.240</div><div class="text-secondary">im Zeitraum</div>',
        );

        self::assertStringContainsString('card-status-top bg-primary', $html);
        self::assertStringContainsString('subheader', $html);
        self::assertStringContainsString('class="h1 mb-0"', $html);
        self::assertStringContainsString('1.240', $html);
        self::assertStringContainsString('im Zeitraum', $html);
        self::assertStringNotContainsString('card-header', $html);
    }

    public function testNestedCardStaysInsideTheBody(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Card',
            [
                'title' => 'Außen',
                'padding' => 'none',
            ],
            '<div class="card"><div class="card-body">Innen</div></div>',
        );

        self::assertStringContainsString('card-body p-0', $html);
        self::assertStringContainsString('Innen', $html);
        self::assertGreaterThan(1, substr_count($html, 'class="card'));
    }

    public function testHrefRendersAnAnchorAndOmitsTheLinkClassWithoutHref(): void
    {
        $linked = (string) $this->renderTwigComponent('Card', [
            'href' => '/explore/allocation',
            'class' => 'h-100 text-reset text-decoration-none',
            'data-testid' => 'dashboard-metric-allocations',
        ], '<div class="subheader">Fälle</div>');

        self::assertStringContainsString('<a', $linked);
        self::assertStringContainsString('card card-link h-100 text-reset text-decoration-none', $linked);
        self::assertStringContainsString('href="/explore/allocation"', $linked);
        self::assertStringContainsString('data-testid="dashboard-metric-allocations"', $linked);

        $plain = (string) $this->renderTwigComponent('Card', [
            'href' => '   ',
        ], '<p>Ohne Link</p>');

        self::assertStringStartsWith('<div', trim($plain));
        self::assertStringNotContainsString('card-link', $plain);
        self::assertStringNotContainsString('href=', $plain);
    }

    public function testHeaderSlotReplacesTheTitleRow(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Card',
            [
                'title' => 'Wird ersetzt',
                'headerClass' => 'py-2',
            ],
            '<p>Verteilung</p>',
            [
                'header' => '<h3 class="card-title mb-0"><a href="/indicators">Indikatoren</a></h3>',
                'actions' => '<button type="button">Ignoriert</button>',
            ],
        );

        self::assertStringContainsString('card-header py-2', $html);
        self::assertStringContainsString('href="/indicators"', $html);
        self::assertStringContainsString('Verteilung', $html);
        self::assertStringNotContainsString('Wird ersetzt', $html);
        self::assertStringNotContainsString('Ignoriert', $html);
        self::assertStringNotContainsString('card-actions', $html);
        self::assertSame(1, substr_count($html, 'card-header'));
    }

    public function testBodyFalseRendersContentWithoutABodyWrapper(): void
    {
        $html = (string) $this->renderTwigComponent(
            'Card',
            [
                'title' => 'Letzte Beiträge',
                'body' => false,
                'padding' => 'none',
                'footerClass' => 'd-flex justify-content-end gap-2',
            ],
            '<div class="list-group list-group-flush">Eintrag</div>',
            [
                'footer' => '<span>Hinweis</span>',
            ],
        );

        self::assertStringContainsString('list-group-flush', $html);
        self::assertStringContainsString('Eintrag', $html);
        self::assertStringNotContainsString('card-body', $html);
        self::assertStringNotContainsString('p-0', $html);
        self::assertStringContainsString('card-footer d-flex justify-content-end gap-2', $html);
    }
}
