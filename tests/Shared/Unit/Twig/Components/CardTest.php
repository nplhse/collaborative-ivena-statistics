<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\Card;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardTest extends TestCase
{
    public function testDefaultClasses(): void
    {
        $card = new Card();

        self::assertSame('card', $card->getCssClass());
        self::assertSame('card-body', $card->getBodyCssClass());
        self::assertNull($card->getStatusClass());
    }

    #[DataProvider('sizeProvider')]
    public function testSizeClass(?string $size, string $expectedClass): void
    {
        $card = new Card();
        $card->size = $size;

        self::assertSame($expectedClass, $card->getCssClass());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: string}>
     */
    public static function sizeProvider(): iterable
    {
        yield 'empty' => [null, 'card'];
        yield 'blank' => ['', 'card'];
        yield 'small' => ['sm', 'card card-sm'];
        yield 'medium' => ['md', 'card card-md'];
        yield 'large' => ['lg', 'card card-lg'];
        yield 'uppercase medium' => ['MD', 'card card-md'];
        yield 'unknown' => ['xl', 'card'];
    }

    public function testExtraClassIsAppended(): void
    {
        $card = new Card();
        $card->size = 'md';
        $card->class = 'mb-3';

        self::assertSame('card card-md mb-3', $card->getCssClass());
    }

    public function testEmptyClassIsIgnored(): void
    {
        $card = new Card();
        $card->class = '';

        self::assertSame('card', $card->getCssClass());
    }

    #[DataProvider('paddingProvider')]
    public function testPaddingClass(string $padding, string $expectedClass): void
    {
        $card = new Card();
        $card->padding = $padding;

        self::assertSame($expectedClass, $card->getBodyCssClass());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function paddingProvider(): iterable
    {
        yield 'default' => ['default', 'card-body'];
        yield 'none' => ['none', 'card-body p-0'];
        yield 'uppercase none' => ['NONE', 'card-body p-0'];
        yield 'unknown' => ['compact', 'card-body'];
        yield 'empty' => ['', 'card-body'];
    }

    #[DataProvider('statusProvider')]
    public function testStatusClass(?string $status, ?string $expectedClass): void
    {
        $card = new Card();
        $card->status = $status;

        self::assertSame($expectedClass, $card->getStatusClass());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'empty' => [null, null];
        yield 'blank' => ['', null];
        yield 'primary' => ['primary', 'card-status-top bg-primary'];
        yield 'success' => ['success', 'card-status-top bg-success'];
        yield 'info' => ['info', 'card-status-top bg-info'];
        yield 'warning' => ['warning', 'card-status-top bg-warning'];
        yield 'danger' => ['danger', 'card-status-top bg-danger'];
        yield 'secondary' => ['secondary', 'card-status-top bg-secondary'];
        yield 'uppercase danger' => ['DANGER', 'card-status-top bg-danger'];
        yield 'unknown' => ['azure', null];
    }

    public function testHrefAddsLinkClassAndAnchorTag(): void
    {
        $card = new Card();
        $card->href = '/explore/allocation';
        $card->class = 'h-100';

        self::assertSame('a', $card->getRootTag());
        self::assertSame('card card-link h-100', $card->getCssClass());
        self::assertSame([
            'class' => 'card card-link h-100',
            'href' => '/explore/allocation',
        ], $card->getRootAttributes());
    }

    #[DataProvider('blankHrefProvider')]
    public function testBlankHrefStaysADiv(?string $href): void
    {
        $card = new Card();
        $card->href = $href;

        self::assertSame('div', $card->getRootTag());
        self::assertSame('card', $card->getCssClass());
        self::assertSame(['class' => 'card'], $card->getRootAttributes());
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function blankHrefProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    public function testRegionClassesAppendExtrasAndIgnoreBlanks(): void
    {
        $card = new Card();
        $card->headerClass = 'py-2';
        $card->bodyClass = 'pt-2';
        $card->footerClass = 'mt-auto';
        $card->padding = 'none';

        self::assertSame('card-header py-2', $card->getHeaderCssClass());
        self::assertSame('card-body p-0 pt-2', $card->getBodyCssClass());
        self::assertSame('card-footer mt-auto', $card->getFooterCssClass());

        $card->headerClass = '  ';
        $card->bodyClass = '';
        $card->footerClass = null;
        $card->padding = 'default';

        self::assertSame('card-header', $card->getHeaderCssClass());
        self::assertSame('card-body', $card->getBodyCssClass());
        self::assertSame('card-footer', $card->getFooterCssClass());
    }
}
