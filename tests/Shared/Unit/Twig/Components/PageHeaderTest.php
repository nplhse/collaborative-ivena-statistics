<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Twig\Components;

use App\Shared\UI\Twig\Components\PageHeader;
use PHPUnit\Framework\TestCase;

final class PageHeaderTest extends TestCase
{
    public function testDefaultPageHeaderClass(): void
    {
        $header = new PageHeader();

        self::assertSame('page-header d-print-none', $header->getPageHeaderClass());
    }

    public function testExtraClassIsAppended(): void
    {
        $header = new PageHeader();
        $header->class = 'mb-0';

        self::assertSame('page-header d-print-none mb-0', $header->getPageHeaderClass());
    }

    public function testEmptyClassIsIgnored(): void
    {
        $header = new PageHeader();
        $header->class = '';

        self::assertSame('page-header d-print-none', $header->getPageHeaderClass());
    }
}
