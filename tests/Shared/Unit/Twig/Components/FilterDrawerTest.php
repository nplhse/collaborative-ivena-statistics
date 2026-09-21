<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\FilterDrawer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FilterDrawerTest extends TestCase
{
    public function testLabelIdIsDerivedFromDrawerId(): void
    {
        $component = $this->drawer();
        $component->id = 'allocation-filters';

        self::assertSame('allocation-filters-label', $component->getLabelId());
    }

    public function testResetIsVisibleWhenResetUrlIsSet(): void
    {
        $component = $this->drawer();
        $component->resetUrl = '/explore/allocation';

        self::assertTrue($component->isResetVisible());
    }

    public function testResetIsHiddenWhenResetUrlIsEmpty(): void
    {
        $component = $this->drawer();
        $component->resetUrl = '';

        self::assertFalse($component->isResetVisible());
    }

    public function testResetIsHiddenWhenResetUrlIsNull(): void
    {
        $component = $this->drawer();
        $component->resetUrl = null;

        self::assertFalse($component->isResetVisible());
    }

    public function testResetCanBeDisabledExplicitly(): void
    {
        $component = $this->drawer();
        $component->resetUrl = '/explore/allocation';
        $component->showReset = false;

        self::assertFalse($component->isResetVisible());
    }

    public function testRootAttributesIncludeOffcanvasChromeAndOptionalTestId(): void
    {
        $component = $this->drawer();
        $component->id = 'hospital-filters';
        $component->testId = 'hospital-filters-drawer';

        self::assertSame([
            'class' => 'offcanvas offcanvas-end filter-drawer',
            'tabindex' => '-1',
            'id' => 'hospital-filters',
            'aria-labelledby' => 'hospital-filters-label',
            'data-testid' => 'hospital-filters-drawer',
        ], $component->getRootAttributes());
    }

    public function testRootAttributesOmitEmptyTestId(): void
    {
        $component = $this->drawer();
        $component->id = 'hospital-filters';
        $component->testId = '';

        self::assertSame([
            'class' => 'offcanvas offcanvas-end filter-drawer',
            'tabindex' => '-1',
            'id' => 'hospital-filters',
            'aria-labelledby' => 'hospital-filters-label',
        ], $component->getRootAttributes());
    }

    public function testPreservedQueryFieldsAreEmptyWithoutARequest(): void
    {
        $component = $this->drawer();
        $component->keepQueryKeys = ['search'];
        $component->preserveQuery = true;

        self::assertSame([], $component->getPreservedQueryFields());
    }

    public function testKeepQueryKeysSkipsMissingNonScalarAndEmptyValues(): void
    {
        $component = $this->drawer(new Request([
            'search' => 'klinik',
            'sortBy' => ['name'],
            'filters' => ['tier' => 'full'],
        ]));
        $component->keepQueryKeys = ['search', 'sortBy', 'orderBy', 'filters'];

        self::assertSame(['search' => 'klinik'], $component->getPreservedQueryFields());
    }

    public function testKeepQueryKeysCopiesWhitelistAndSkipsEmptyValues(): void
    {
        $component = $this->drawer(new Request([
            'search' => 'klinik',
            'sortBy' => 'name',
            'orderBy' => 'desc',
            'page' => '2',
            'empty' => '',
        ]));
        $component->keepQueryKeys = ['search', 'sortBy', 'orderBy', 'empty'];

        self::assertSame([
            'search' => 'klinik',
            'sortBy' => 'name',
            'orderBy' => 'desc',
        ], $component->getPreservedQueryFields());
    }

    public function testKeepQueryKeysNeverPreservesPagination(): void
    {
        $component = $this->drawer(new Request([
            'sortBy' => 'name',
            'page' => '3',
            'cursor' => 'abc',
            'after' => '1',
            'before' => '2',
        ]));
        $component->keepQueryKeys = ['sortBy', 'page', 'cursor', 'after', 'before'];

        self::assertSame(['sortBy' => 'name'], $component->getPreservedQueryFields());
    }

    public function testPreserveQueryCopiesRemainingKeysAndDropsPaginationAndOmitted(): void
    {
        $component = $this->drawer(new Request([
            'scope' => 'public',
            'period' => 'all',
            'gender' => '2',
            'page' => '2',
            'cursor' => 'xyz',
            'after' => '1',
            'before' => '2',
        ]));
        $component->preserveQuery = true;
        $component->omitQueryKeys = ['gender'];

        self::assertSame([
            'scope' => 'public',
            'period' => 'all',
        ], $component->getPreservedQueryFields());
    }

    public function testPreserveQueryTakesPrecedenceOverKeepQueryKeys(): void
    {
        $component = $this->drawer(new Request([
            'scope' => 'public',
            'sortBy' => 'name',
        ]));
        $component->preserveQuery = true;
        $component->keepQueryKeys = ['sortBy'];

        self::assertSame([
            'scope' => 'public',
            'sortBy' => 'name',
        ], $component->getPreservedQueryFields());
    }

    private function drawer(?Request $request = null): FilterDrawer
    {
        $stack = new RequestStack();
        if ($request instanceof Request) {
            $stack->push($request);
        }

        return new FilterDrawer($stack);
    }
}
