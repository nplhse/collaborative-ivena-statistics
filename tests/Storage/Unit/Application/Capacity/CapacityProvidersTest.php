<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Capacity;

use App\Storage\Application\Capacity\BudgetCapacityProvider;
use App\Storage\Application\Capacity\CapacityReference;
use App\Storage\Application\Capacity\CapacityResolver;
use App\Storage\Application\Capacity\FilesystemCapacityProvider;
use App\Storage\Application\Capacity\FilesystemSpace;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Enum\CapacitySource;
use PHPUnit\Framework\TestCase;

final class CapacityProvidersTest extends TestCase
{
    public function testBudgetComparesTheMeasuredUsage(): void
    {
        $reference = new BudgetCapacityProvider($this->settings(budget: 10_000))->provide(4000, new FilesystemSpace(100_000, 1000));

        self::assertEquals(new CapacityReference(CapacitySource::Budget, 10_000, 4000), $reference);
        self::assertNull(new BudgetCapacityProvider($this->settings(budget: 0))->provide(4000, null));
    }

    public function testFilesystemComparesTheFilesystemWideUsage(): void
    {
        $provider = new FilesystemCapacityProvider();

        self::assertSame(CapacitySource::Filesystem, $provider->source());
        self::assertEquals(new CapacityReference(CapacitySource::Filesystem, 100_000, 99_000), $provider->provide(4000, new FilesystemSpace(100_000, 1000)));
        self::assertNull($provider->provide(4000, null));
    }

    public function testResolverUsesTheConfiguredSource(): void
    {
        self::assertSame(CapacitySource::Budget, $this->resolver('budget', 10_000)->resolve(4000, new FilesystemSpace(100_000, 1000))?->source);
        self::assertSame(CapacitySource::Filesystem, $this->resolver('filesystem', 10_000)->resolve(4000, new FilesystemSpace(100_000, 1000))?->source);
    }

    public function testResolverFallsBackToTheBudget(): void
    {
        self::assertSame(CapacitySource::Budget, $this->resolver('filesystem', 10_000)->resolve(4000, null)?->source);
        self::assertNull($this->resolver('filesystem', 0)->resolve(4000, null));
        self::assertNull($this->resolver('budget', 0)->resolve(4000, new FilesystemSpace(100_000, 1000)));
    }

    public function testResolverWithoutProvidersHasNoReference(): void
    {
        self::assertNull(new CapacityResolver($this->settings(budget: 10_000), [])->resolve(4000, null));
    }

    public function testRejectsImplausibleValues(): void
    {
        foreach ([[0, 0], [100, -1], [100, 101]] as [$total, $free]) {
            try {
                new FilesystemSpace($total, $free);
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new CapacityReference(CapacitySource::None, 1000, 10);
    }

    public function testFilesystemSpaceKnowsItsUsage(): void
    {
        self::assertSame(60, new FilesystemSpace(100, 40)->usedBytes());
    }

    private function resolver(string $source, int $budget): CapacityResolver
    {
        $settings = $this->settings($budget, $source);

        return new CapacityResolver($settings, [new BudgetCapacityProvider($settings), new FilesystemCapacityProvider()]);
    }

    private function settings(int $budget, string $source = 'budget'): StorageSettings
    {
        return new StorageSettings(80, 90, 99, $budget, $source, 36);
    }
}
