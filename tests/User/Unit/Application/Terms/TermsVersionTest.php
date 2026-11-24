<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Terms;

use App\User\Application\Terms\TermsVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TermsVersionTest extends TestCase
{
    public function testWithoutAVersionThereIsNone(): void
    {
        self::assertNull(new TermsVersion()->current());
        self::assertNull(new TermsVersion('')->current(), 'APP_TERMS_VERSION= in .env');
        self::assertNull(new TermsVersion('  ')->current());
    }

    public function testTheConfiguredVersionIsCurrent(): void
    {
        self::assertSame('2026-12', new TermsVersion('2026-12')->current());
        self::assertSame('v1.2_final', new TermsVersion(' v1.2_final ')->current());
    }

    #[DataProvider('invalidVersions')]
    public function testAnInvalidVersionIsRejected(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_TERMS_VERSION');

        new TermsVersion($version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'spaces' => ['new terms'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'leading dot' => ['.2026'];
    }
}
