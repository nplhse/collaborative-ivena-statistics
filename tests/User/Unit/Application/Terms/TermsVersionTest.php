<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Terms;

use App\User\Application\Terms\TermsVersion;
use PHPUnit\Framework\TestCase;

final class TermsVersionTest extends TestCase
{
    public function testWithoutAVersionThereIsNone(): void
    {
        self::assertNull(new TermsVersion()->current());
    }

    public function testTheConfiguredVersionIsCurrent(): void
    {
        self::assertSame('2026-12', new TermsVersion('2026-12')->current());
    }
}
