<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\RequestTracking;

use App\Analytics\Application\RequestTracking\AnalyticsUserKeyGenerator;
use PHPUnit\Framework\TestCase;

final class AnalyticsUserKeyGeneratorTest extends TestCase
{
    public function testGenerateIsStableAndDoesNotContainUserId(): void
    {
        $generator = new AnalyticsUserKeyGenerator('test-secret');

        $key = $generator->generate(42);

        self::assertSame(64, \strlen($key));
        self::assertSame($key, $generator->generate(42));
        self::assertNotSame($key, $generator->generate(43));
        self::assertStringNotContainsString('42', $key);
    }

    public function testDifferentSecretsProduceDifferentKeys(): void
    {
        $a = new AnalyticsUserKeyGenerator('secret-a');
        $b = new AnalyticsUserKeyGenerator('secret-b');

        self::assertNotSame($a->generate(1), $b->generate(1));
    }
}
