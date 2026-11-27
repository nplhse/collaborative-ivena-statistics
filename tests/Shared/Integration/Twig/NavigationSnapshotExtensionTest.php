<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig;

use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\UI\Twig\NavigationSnapshotExtension;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Twig\Environment;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The stored copy of the public navigation for the error pages (#1684): built as for a visitor who is not signed in,
 * renewed at most once an hour, read without the database.
 */
#[ResetDatabase]
final class NavigationSnapshotExtensionTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
    }

    public function testNothingIsStoredBeforeANormalPageRenewedIt(): void
    {
        self::assertNull($this->extension()->snapshot('primary', 'en'));
        self::assertNull($this->extension()->snapshot('footer', 'en'));
        self::assertNull($this->extension()->snapshot('unknown', 'en'));
    }

    public function testTheCopyHoldsOnlyThePublicLinks(): void
    {
        $this->extension()->refresh($this->twig(), 'en');

        $primary = (string) $this->extension()->snapshot('primary', 'en');
        self::assertStringContainsString('data-testid="nav-blog"', $primary);
        self::assertStringNotContainsString('data-testid="nav-statistics"', $primary, 'only for signed-in users');
        self::assertStringNotContainsString('active', $primary, 'no entry is marked active');

        $footer = (string) $this->extension()->snapshot('footer', 'en');
        self::assertStringContainsString('data-testid="footer-nav-sitemap"', $footer);
        self::assertStringNotContainsString('data-testid="footer-nav-glossary"', $footer, 'only for participants');

        self::assertNull($this->extension()->snapshot('primary', 'de'), 'kept per language');
    }

    public function testTheCopyIsRenewedAtMostOnceAnHour(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-27 10:00:00'));
        $this->extension()->refresh($this->twig(), 'en');
        self::assertSame(strtotime('2026-11-27 10:00:00'), $this->storedAt('primary'));

        self::mockTime(new \DateTimeImmutable('2026-11-27 10:59:59'));
        $this->extension()->refresh($this->twig(), 'en');
        self::assertSame(strtotime('2026-11-27 10:00:00'), $this->storedAt('primary'), 'younger than an hour: kept');

        self::mockTime(new \DateTimeImmutable('2026-11-27 11:00:00'));
        $this->extension()->refresh($this->twig(), 'en');
        self::assertSame(strtotime('2026-11-27 11:00:00'), $this->storedAt('primary'), 'an hour old: renewed');
        self::assertSame(strtotime('2026-11-27 11:00:00'), $this->storedAt('footer'));
    }

    public function testABrokenCacheNeitherBreaksANormalPageNorTheErrorPage(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('The cache directory is not writable.'));
        $logger = new BufferingLogger();
        $extension = new NavigationSnapshotExtension(
            self::getContainer()->get(NavigationBuilder::class),
            $cache,
            self::getContainer()->get(ClockInterface::class),
            $logger,
        );

        self::assertSame('', $extension->refresh($this->twig(), 'en'));
        self::assertNull($extension->snapshot('footer', 'en'));
        self::assertCount(3, $logger->cleanLogs(), 'two menus not renewed, one not read');
    }

    private function storedAt(string $menu): ?int
    {
        $stored = self::getContainer()->get('cache.app')->getItem('navigation_snapshot.'.$menu.'.en')->get();

        return \is_array($stored) && \is_int($stored['at'] ?? null) ? $stored['at'] : null;
    }

    private function extension(): NavigationSnapshotExtension
    {
        return self::getContainer()->get(NavigationSnapshotExtension::class);
    }

    private function twig(): Environment
    {
        return self::getContainer()->get('twig');
    }
}
