<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\DTO\HealthCheckItemDto;
use App\Admin\Application\DTO\HealthCheckItemLinks;
use App\Admin\Application\Service\HealthCheckItemMapper;
use App\Admin\Application\Service\StorageHealthItemFactory;
use App\Shared\Application\Health\HealthCheckReport;
use App\Shared\Application\Health\HealthCheckStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The health items of the admin dashboard card (ADR 019); the card itself is rendered in DashboardOperationsTest.
 */
final class HealthCheckItemMapperTest extends TestCase
{
    private const array ALL_MESSENGER_OK = [
        'messenger_failed' => 'ok',
        'messenger_backlog' => 'ok',
        'messenger_stalled' => 'ok',
        'messenger_retries' => 'ok',
    ];

    public function testAllMessengerChecksOkCollapseIntoOneItemAtThePlaceOfTheFirst(): void
    {
        $report = $this->report(['database' => 'ok', ...self::ALL_MESSENGER_OK, 'media' => 'ok']);

        $items = $this->mapper()->map($report, $this->links());

        self::assertSame(['database', 'messenger', 'media'], $this->keys($items));
        self::assertEquals(
            new HealthCheckItemDto('messenger', 'ops.health.check.messenger', 'ok', 'success', 'fas fa-tower-broadcast', '/messenger'),
            $items[1],
        );
    }

    public function testAMessengerCheckThatIsNotOkShowsOnlyTheChecksThatAreNotOk(): void
    {
        $report = $this->report(
            [...self::ALL_MESSENGER_OK, 'messenger_backlog' => 'oldest message waits 50 min (low)', 'messenger_stalled' => '1 message(s) waiting'],
            ['messenger_backlog' => HealthCheckStatus::Degraded, 'messenger_stalled' => HealthCheckStatus::Unhealthy],
        );

        $items = $this->mapper()->map($report, $this->links());

        self::assertEquals([
            new HealthCheckItemDto('messenger_backlog', 'ops.health.check.messenger_backlog', 'oldest message waits 50 min (low)', 'warning', 'fas fa-triangle-exclamation', '/messenger'),
            new HealthCheckItemDto('messenger_stalled', 'ops.health.check.messenger_stalled', '1 message(s) waiting', 'danger', 'fas fa-triangle-exclamation', '/messenger'),
        ], $items);
    }

    public function testFailedMessagesAreAWarningThatLinksToTheFailureQueue(): void
    {
        $report = $this->report([...self::ALL_MESSENGER_OK, 'messenger_failed' => '1 failed message(s)']);

        $items = $this->mapper()->map($report, $this->links());

        self::assertEquals(
            [new HealthCheckItemDto('messenger_failed', 'ops.health.check.messenger_failed', '1 failed message(s)', 'warning', 'fas fa-triangle-exclamation', '/failed-messages')],
            $items,
        );
    }

    public function testASkippedFailureCheckHasNoLink(): void
    {
        $report = $this->report([...self::ALL_MESSENGER_OK, 'messenger_failed' => 'skipped']);

        $items = $this->mapper()->map($report, $this->links());

        self::assertNull($items[0]->detailUrl);
    }

    public function testWithoutMessengerChecksThereIsNoMessengerItem(): void
    {
        $report = $this->report(['database' => 'ok']);

        $items = $this->mapper()->map($report, $this->links());

        self::assertSame(['database'], $this->keys($items));
    }

    /**
     * @return iterable<string, array{string, string, array<string, HealthCheckStatus>, string, string, ?string}>
     */
    public static function singleChecks(): iterable
    {
        yield 'database ok' => ['database', 'ok', [], 'success', 'fas fa-database', null];
        yield 'database down' => ['database', 'error', [], 'danger', 'fas fa-database', null];
        yield 'media links to the media library' => ['media', 'ok', [], 'success', 'fas fa-photo-film', '/media'];
        yield 'media with missing files' => ['media', '2 missing', ['media' => HealthCheckStatus::Degraded], 'warning', 'fas fa-triangle-exclamation', '/media'];
        yield 'scheduled jobs link to the job runs' => ['scheduled_jobs', 'ok', [], 'success', 'fas fa-stopwatch', '/job-runs'];
        yield 'two-factor key has no page' => ['two_factor', 'missing', ['two_factor' => HealthCheckStatus::Unhealthy], 'danger', 'fas fa-triangle-exclamation', null];
        yield 'research export has no page' => ['research_export', 'ok', [], 'success', 'fas fa-file-export', null];
        yield 'maintenance mode left on' => ['maintenance', 'on', ['maintenance' => HealthCheckStatus::Degraded], 'warning', 'fas fa-triangle-exclamation', null];
        yield 'an unknown check is shown as it is' => ['something_new', 'fine', [], 'success', 'fas fa-circle-check', null];
    }

    /**
     * @param array<string, HealthCheckStatus> $statuses
     */
    #[DataProvider('singleChecks')]
    public function testEachCheckGetsItsSeverityIconAndLink(string $key, string $value, array $statuses, string $severity, string $icon, ?string $detailUrl): void
    {
        $report = $this->report([$key => $value], $statuses);

        $items = $this->mapper()->map($report, $this->links());

        self::assertEquals([new HealthCheckItemDto($key, 'ops.health.check.'.$key, $value, $severity, $icon, $detailUrl)], $items);
    }

    public function testTheStorageItemComesFromTheStorageFactory(): void
    {
        $report = $this->report(['storage' => 'warning']);

        $items = $this->mapper()->map($report, $this->links());

        self::assertEquals(
            [new HealthCheckItemDto('storage', 'ops.health.check.storage', 'warning', 'warning', 'fas fa-triangle-exclamation', '/storage')],
            $items,
        );
    }

    private function mapper(): HealthCheckItemMapper
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/storage');

        return new HealthCheckItemMapper(new StorageHealthItemFactory($urlGenerator));
    }

    private function links(): HealthCheckItemLinks
    {
        return new HealthCheckItemLinks(messenger: '/messenger', failedMessages: '/failed-messages', media: '/media', scheduledJobs: '/job-runs');
    }

    /**
     * @param array<string, string>            $checks
     * @param array<string, HealthCheckStatus> $statuses
     */
    private function report(array $checks, array $statuses = []): HealthCheckReport
    {
        return new HealthCheckReport(HealthCheckStatus::Healthy, '1.0.0', $checks, statuses: $statuses);
    }

    /**
     * @param list<HealthCheckItemDto> $items
     *
     * @return list<string>
     */
    private function keys(array $items): array
    {
        return array_map(static fn (HealthCheckItemDto $item): string => $item->key, $items);
    }
}
