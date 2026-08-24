<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Explore;

use App\User\Application\Explore\ProjectActivityPage;
use PHPUnit\Framework\TestCase;

final class ProjectActivityPageTest extends TestCase
{
    public function testNextFrameIdIsNullWhenThereIsNoCursor(): void
    {
        $page = new ProjectActivityPage([], null);

        self::assertFalse($page->hasMore());
        self::assertNull($page->nextFrameId());
    }
}
