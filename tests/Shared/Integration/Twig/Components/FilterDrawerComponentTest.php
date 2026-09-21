<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig\Components;

use App\Shared\UI\Twig\Components\FilterDrawer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class FilterDrawerComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersOffcanvasChromeFooterAndContent(): void
    {
        $html = (string) $this->renderTwigComponent(
            'FilterDrawer',
            [
                'id' => 'hospital-filters',
                'formId' => 'hospital-filter-form',
                'formAction' => '/explore/hospital',
                'testId' => 'hospital-filters-drawer',
                'formTestId' => 'hospital-filter-form',
                'resetUrl' => '/explore/hospital',
            ],
            '<select name="tier"><option value="full">Full</option></select>',
        );

        self::assertStringContainsString('offcanvas offcanvas-end filter-drawer', $html);
        self::assertStringContainsString('id="hospital-filters"', $html);
        self::assertStringContainsString('aria-labelledby="hospital-filters-label"', $html);
        self::assertStringContainsString('data-testid="hospital-filters-drawer"', $html);
        self::assertStringContainsString('Filter Results', $html);
        self::assertStringContainsString('id="hospital-filter-form"', $html);
        self::assertStringContainsString('data-testid="hospital-filter-form"', $html);
        self::assertStringContainsString('action="/explore/hospital"', $html);
        self::assertStringContainsString('name="tier"', $html);
        self::assertStringContainsString('offcanvas-footer', $html);
        self::assertStringContainsString('data-testid="filter-drawer-apply"', $html);
        self::assertStringContainsString('data-testid="filter-drawer-cancel"', $html);
        self::assertStringContainsString('data-testid="filter-drawer-reset"', $html);
        self::assertStringContainsString('Apply', $html);
        self::assertStringContainsString('Reset', $html);
        self::assertStringContainsString('Cancel', $html);
    }

    public function testCustomTitleAndMissingResetOmitsResetLink(): void
    {
        $html = (string) $this->renderTwigComponent('FilterDrawer', [
            'id' => 'import-filters',
            'formId' => 'import-filter-form',
            'formAction' => '/import',
            'title' => 'Narrow imports',
        ]);

        self::assertStringContainsString('Narrow imports', $html);
        self::assertStringNotContainsString('Filter Results', $html);
        self::assertStringNotContainsString('data-testid="filter-drawer-reset"', $html);
    }

    public function testKeepQueryKeysRenderAsHiddenInputsAndDropPagination(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/explore/hospital', 'GET', [
            'search' => 'klinik',
            'sortBy' => 'name',
            'page' => '2',
        ]));

        try {
            $rendered = $this->renderTwigComponent('FilterDrawer', [
                'id' => 'hospital-filters',
                'formId' => 'hospital-filter-form',
                'formAction' => '/explore/hospital',
                'keepQueryKeys' => ['search', 'sortBy', 'orderBy'],
            ]);
            $form = $rendered->crawler()->filter('#hospital-filter-form');

            self::assertCount(1, $form->filter('input[type="hidden"][name="search"][value="klinik"]'));
            self::assertCount(1, $form->filter('input[type="hidden"][name="sortBy"][value="name"]'));
            self::assertCount(0, $form->filter('input[type="hidden"][name="page"]'));
        } finally {
            $requestStack->pop();
        }
    }

    public function testPreserveQueryOmitsDrawerKeysAndPagination(): void
    {
        $requestStack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/statistics/top-lists/top_diagnoses', 'GET', [
            'scope' => 'public',
            'period' => 'all',
            'gender' => '2',
            'page' => '2',
        ]));

        try {
            $component = $this->mountTwigComponent('FilterDrawer', [
                'id' => 'statistics-filters-drawer',
                'formId' => 'statistics-filter-form',
                'formAction' => '/statistics/top-lists/top_diagnoses',
                'preserveQuery' => true,
                'omitQueryKeys' => ['gender'],
            ]);
            self::assertInstanceOf(FilterDrawer::class, $component);
            self::assertSame([
                'scope' => 'public',
                'period' => 'all',
            ], $component->getPreservedQueryFields());

            $rendered = $this->renderTwigComponent('FilterDrawer', [
                'id' => 'statistics-filters-drawer',
                'formId' => 'statistics-filter-form',
                'formAction' => '/statistics/top-lists/top_diagnoses',
                'preserveQuery' => true,
                'omitQueryKeys' => ['gender'],
            ]);
            $form = $rendered->crawler()->filter('#statistics-filter-form');

            self::assertCount(1, $form->filter('input[type="hidden"][name="scope"][value="public"]'));
            self::assertCount(1, $form->filter('input[type="hidden"][name="period"][value="all"]'));
            self::assertCount(0, $form->filter('input[type="hidden"][name="gender"]'));
            self::assertCount(0, $form->filter('input[type="hidden"][name="page"]'));
        } finally {
            $requestStack->pop();
        }
    }
}
