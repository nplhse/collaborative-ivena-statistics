<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Twig;

use App\Allocation\Application\Explore\Catalog\CatalogActionFactory;
use App\Allocation\UI\Twig\CatalogActionExtension;
use App\Statistics\Application\TopList\TopListCatalogCrossReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CatalogActionExtensionTest extends TestCase
{
    public function testCatalogTopListActionDelegatesToFactory(): void
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/statistics/top-lists/top_diagnoses');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Open top list');

        $action = $this->extension($urlGenerator, $translator)->catalogTopListAction('indication');

        self::assertNotNull($action);
        self::assertSame('/statistics/top-lists/top_diagnoses', $action->url);
        self::assertSame('Open top list', $action->label);
    }

    public function testCatalogTopListActionReturnsNullWhenDimensionHasNoTopList(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');
        $translator = $this->createStub(TranslatorInterface::class);

        self::assertNull($this->extension($urlGenerator, $translator)->catalogTopListAction('hospital'));
    }

    private function extension(UrlGeneratorInterface $urlGenerator, TranslatorInterface $translator): CatalogActionExtension
    {
        return new CatalogActionExtension(
            new CatalogActionFactory($urlGenerator, $translator, new TopListCatalogCrossReference()),
        );
    }
}
