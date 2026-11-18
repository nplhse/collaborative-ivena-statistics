<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Service\ImportHospitalOverride;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportHospitalOverrideTest extends TestCase
{
    #[DataProvider('availableEnvironments')]
    public function testIsAvailableInDevelopmentAndDemo(string $environment): void
    {
        self::assertTrue(new ImportHospitalOverride($environment)->isAvailable());
    }

    #[DataProvider('otherEnvironments')]
    public function testIsUnavailableElsewhere(string $environment): void
    {
        self::assertFalse(new ImportHospitalOverride($environment)->isAvailable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function availableEnvironments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'demo' => ['demo'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherEnvironments(): iterable
    {
        yield 'test' => ['test'];
        yield 'prod' => ['prod'];
        yield 'empty' => [''];
    }
}
