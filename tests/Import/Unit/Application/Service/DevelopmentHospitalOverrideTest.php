<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Service\DevelopmentHospitalOverride;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentHospitalOverrideTest extends TestCase
{
    public function testIsAvailableInTheDevelopmentEnvironment(): void
    {
        self::assertTrue(new DevelopmentHospitalOverride('dev')->isAvailable());
    }

    #[DataProvider('otherEnvironments')]
    public function testIsUnavailableOutsideTheDevelopmentEnvironment(string $environment): void
    {
        self::assertFalse(new DevelopmentHospitalOverride($environment)->isAvailable());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherEnvironments(): iterable
    {
        yield 'test' => ['test'];
        yield 'prod' => ['prod'];
        yield 'demo' => ['demo'];
        yield 'empty' => [''];
    }
}
