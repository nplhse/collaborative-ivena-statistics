<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain;

use App\Content\Domain\Enum\PageKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageKeyNavIconTest extends TestCase
{
    #[DataProvider('headerNavIconProvider')]
    public function testHeaderPagesHaveNavIcons(PageKey $key, string $expectedIcon): void
    {
        self::assertSame($expectedIcon, $key->navIcon());
    }

    /**
     * @return iterable<string, array{0: PageKey, 1: string}>
     */
    public static function headerNavIconProvider(): iterable
    {
        yield 'about' => [PageKey::About, 'tabler:users-group'];
        yield 'features' => [PageKey::Features, 'tabler:sparkles'];
        yield 'faq' => [PageKey::Faq, 'tabler:info-square-rounded'];
    }

    public function testFooterPagesHaveFallbackNavIcon(): void
    {
        self::assertSame('tabler:file-text', PageKey::Imprint->navIcon());
    }
}
