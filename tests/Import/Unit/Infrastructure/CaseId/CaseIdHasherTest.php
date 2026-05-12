<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\CaseId;

use App\Import\Infrastructure\CaseId\CaseIdHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaseIdHasherTest extends TestCase
{
    private CaseIdHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new CaseIdHasher('$ecretf0rt3st');
    }

    public function testHashFromReturnsNullForNullOrEmptyInput(): void
    {
        self::assertNull($this->hasher->hashFrom(null));
        self::assertNull($this->hasher->hashFrom(''));
        self::assertNull($this->hasher->hashFrom('   '));
        self::assertNull($this->hasher->hashFrom('abc'));
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalizeKeepsDigitsOnly(?string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->hasher->normalize($input));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function normalizeProvider(): iterable
    {
        yield 'digits only' => ['123456', '123456'];
        yield 'leading zeros' => ['00123', '00123'];
        yield 'non digits stripped' => ['ENR-12-34', '1234'];
        yield 'empty after strip' => ['---', null];
    }

    public function testHashFromIsDeterministicForSameInput(): void
    {
        $first = $this->hasher->hashFrom('123456');
        $second = $this->hasher->hashFrom('123456');

        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    public function testHashFromNormalizesBeforeHashing(): void
    {
        self::assertSame(
            $this->hasher->hashFrom('123456'),
            $this->hasher->hashFrom('ENR-123-456'),
        );
    }

    public function testHashFromReturnsThirtyTwoRawBytes(): void
    {
        $hash = $this->hasher->hashFrom('42');

        self::assertNotNull($hash);
        self::assertSame(32, strlen($hash));
    }

    public function testDifferentSecretsProduceDifferentHashes(): void
    {
        $otherHasher = new CaseIdHasher('another-secret');

        self::assertNotSame(
            $this->hasher->hashFrom('123456'),
            $otherHasher->hashFrom('123456'),
        );
    }
}
