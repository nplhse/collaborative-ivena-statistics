<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Terms;

use App\User\Application\Terms\TermsVersion;
use PHPUnit\Framework\TestCase;

final class TermsVersionTest extends TestCase
{
    public function testWithoutAVersionThereIsNoneAndNoDate(): void
    {
        $version = new TermsVersion();

        self::assertNull($version->current());
        self::assertNull($version->publishedAt());
    }

    public function testTheConfiguredVersionIsCurrentWithItsDate(): void
    {
        $version = new TermsVersion('2026-12', '2026-12-01');

        self::assertSame('2026-12', $version->current());
        self::assertSame('2026-12-01 00:00', $version->publishedAt()?->format('Y-m-d H:i'));
    }
}
