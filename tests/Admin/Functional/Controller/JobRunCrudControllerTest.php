<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunRecorder;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The admin job run list (ADR 018): access, filters, shortcuts and the detail page. The metrics of every job are
 * formatted in JobRunFormatterTest, their labels are checked in JobRunCatalogTranslationTest.
 */
#[ResetDatabase]
final class JobRunCrudControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string INDEX = '/admin/job-run';

    public function testAdminCanOpenTheList(): void
    {
        $client = $this->adminClient();
        $this->record('run-a', 'allocation_import', 'succeeded');

        $crawler = $client->request(Request::METHOD_GET, self::INDEX);

        self::assertResponseIsSuccessful();
        self::assertSame(['run-a'], $this->rowIds($crawler));
    }

    public function testTheListIsSortedByStartNewestFirst(): void
    {
        $client = $this->adminClient();
        $this->record('run-old', 'allocation_import', 'succeeded', startedAt: '2026-10-20 08:00:00');
        $this->record('run-new', 'allocation_import', 'succeeded', startedAt: '2026-10-25 08:00:00');
        $this->record('run-mid', 'closure_rebuild', 'succeeded', startedAt: '2026-10-22 08:00:00');

        $crawler = $client->request(Request::METHOD_GET, self::INDEX);

        self::assertSame(['run-new', 'run-mid', 'run-old'], $this->rowIds($crawler));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'list' => [self::INDEX];
        yield 'detail' => [self::INDEX.'/run-a'];
    }

    #[DataProvider('pages')]
    public function testUsersWithoutAdminRoleAreForbidden(string $url): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());
        $this->record('run-a', 'allocation_import', 'succeeded');

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, self::INDEX);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testTheListOffersNoActionsThatChangeRuns(): void
    {
        $client = $this->adminClient();
        $this->record('run-a', 'allocation_import', 'failed');

        $crawler = $client->request(Request::METHOD_GET, self::INDEX);

        self::assertCount(0, $crawler->filter('a.action-new, a.action-edit, a.action-delete, button.action-batchDelete'));
    }

    public function testTheDetailOffersNoActionsThatChangeTheRun(): void
    {
        $client = $this->adminClient();
        $this->record('run-a', 'allocation_import', 'failed');

        $crawler = $client->request(Request::METHOD_GET, self::INDEX.'/run-a');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.action-edit, a.action-delete'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function changingRequests(): iterable
    {
        yield 'new' => [Request::METHOD_GET, self::INDEX.'/new'];
        yield 'edit' => [Request::METHOD_GET, self::INDEX.'/run-a/edit'];
        yield 'delete' => [Request::METHOD_POST, self::INDEX.'/run-a/delete'];
    }

    #[DataProvider('changingRequests')]
    public function testRunsCannotBeCreatedEditedOrDeleted(string $method, string $url): void
    {
        $client = $this->adminClient();
        $this->record('run-a', 'allocation_import', 'failed');

        $client->request($method, $url);

        self::assertFalse($client->getResponse()->isSuccessful());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM job_run'));
    }

    /**
     * @param array<string, mixed> $filter
     * @param list<string>         $expected
     */
    #[DataProvider('filters')]
    public function testFilters(array $filter, array $expected): void
    {
        $client = $this->adminClient();
        $hospital = $this->hospital('Klinikum Filter');
        $this->record('run-import', 'allocation_import', 'succeeded', trigger: 'upload', importId: 7, importRunId: '0199f0a4-7d3c-7a51-9b1e-4f2a6c8d9e01', hospitalId: $hospital->getId(), startedAt: '2026-10-25 08:00:00');
        $this->record('run-failed', 'closure_import', 'failed', trigger: 'console', importId: 8, startedAt: '2026-10-24 08:00:00');
        $this->record('run-slow', 'closure_rebuild', 'succeeded', trigger: 'scheduler', thresholds: ['slow'], startedAt: '2026-10-10 08:00:00');

        $crawler = $client->request(Request::METHOD_GET, self::INDEX.'?'.http_build_query(['filters' => $filter]));

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing($expected, $this->rowIds($crawler));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function filters(): iterable
    {
        yield 'job' => [['job' => ['comparison' => '=', 'value' => ['closure_import', 'closure_rebuild']]], ['run-failed', 'run-slow']];
        yield 'status' => [['status' => ['comparison' => '=', 'value' => ['failed']]], ['run-failed']];
        yield 'trigger' => [['triggeredBy' => ['comparison' => '=', 'value' => ['upload', 'scheduler']]], ['run-import', 'run-slow']];
        yield 'started after' => [['startedAt' => ['comparison' => '>=', 'value' => '2026-10-20 00:00:00']], ['run-import', 'run-failed']];
        yield 'started between' => [['startedAt' => ['comparison' => 'between', 'value' => '2026-10-01 00:00:00', 'value2' => '2026-10-24 12:00:00']], ['run-failed', 'run-slow']];
        yield 'thresholds exceeded' => [['thresholdsExceeded' => 'not_null'], ['run-slow']];
        yield 'no thresholds exceeded' => [['thresholdsExceeded' => 'null'], ['run-import', 'run-failed']];
        yield 'import' => [['importId' => ['comparison' => '=', 'value' => 8]], ['run-failed']];
        yield 'import run' => [['importRunId' => ['comparison' => '=', 'value' => '0199f0a4-7d3c-7a51-9b1e-4f2a6c8d9e01']], ['run-import']];
    }

    public function testHospitalFilter(): void
    {
        $client = $this->adminClient();
        $first = $this->hospital('Klinikum Nord');
        $second = $this->hospital('Klinikum Süd');
        $this->record('run-north', 'allocation_import', 'succeeded', hospitalId: $first->getId());
        $this->record('run-south', 'allocation_projection_rebuild', 'succeeded', hospitalId: $second->getId());
        $this->record('run-none', 'closure_rebuild', 'succeeded');

        $crawler = $client->request(Request::METHOD_GET, self::INDEX.'?'.http_build_query(['filters' => [
            'hospitalId' => ['comparison' => '=', 'value' => $second->getId()],
        ]]));

        self::assertResponseIsSuccessful();
        self::assertSame(['run-south'], $this->rowIds($crawler));
        self::assertStringNotContainsString('Klinikum Süd', $crawler->filter('tr[data-id="run-south"]')->text(), 'the hospital is shown on the detail page only');
    }

    public function testTheListShowsOnlyTheKeyColumnsAndTheTriggerAsBadge(): void
    {
        $client = $this->adminClient();
        $this->record('run-a', 'allocation_import', 'succeeded', trigger: 'upload', importId: 7);

        $crawler = $client->request(Request::METHOD_GET, self::INDEX);

        $columns = $crawler->filter('table.datagrid thead th[data-column]')->each(static fn (Crawler $th): string => (string) $th->attr('data-column'));
        self::assertSame(['startedAt', 'job', 'triggeredBy', 'status', 'durationMs', 'peakMemoryBytes', 'thresholdBadges'], $columns);
        self::assertCount(1, $crawler->filter('tr[data-id="run-a"] td[data-column="triggeredBy"] .badge-primary'));
        self::assertCount(0, $crawler->filter('tr[data-id="run-a"] a[href*="/admin/import/7"]'));
    }

    public function testEncounterImportRunsAreListedAndFilteredAsTheirOwnJob(): void
    {
        $client = $this->adminClient();
        $this->record('run-encounter', 'encounter_import', 'failed', trigger: 'upload', importId: 9);
        $this->record('run-allocation', 'allocation_import', 'succeeded', trigger: 'upload', importId: 7);

        $crawler = $client->request(Request::METHOD_GET, self::INDEX.'?'.http_build_query(['filters' => ['job' => ['comparison' => '=', 'value' => ['encounter_import']]]]));

        self::assertResponseIsSuccessful();
        self::assertSame(['run-encounter'], $this->rowIds($crawler));
        $label = $crawler->filter('tr[data-id="run-encounter"] td[data-column="job"]')->text();
        self::assertMatchesRegularExpression('/Encounter data import|Behandlungsdatenimport/', $label);
        self::assertStringNotContainsString('admin.job_run.job', $crawler->text());
    }

    /**
     * @return iterable<string, array{int, list<string>}>
     */
    public static function shortcuts(): iterable
    {
        yield 'failed' => [0, ['run-failed']];
        yield 'thresholds exceeded' => [1, ['run-slow']];
        yield 'last 24 hours' => [2, ['run-failed']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('shortcuts')]
    public function testShortcutsLinkToFilteredLists(int $position, array $expected): void
    {
        $client = $this->adminClient();
        self::mockTime('2026-10-25 12:00:00');
        $this->record('run-ok', 'allocation_import', 'succeeded', startedAt: '2026-10-23 12:00:00');
        $this->record('run-failed', 'allocation_import', 'failed', startedAt: '2026-10-25 10:00:00');
        $this->record('run-slow', 'closure_rebuild', 'succeeded', thresholds: ['long_run'], startedAt: '2026-10-22 12:00:00');
        $link = $client->request(Request::METHOD_GET, self::INDEX)->filter('a.dropdown-item[href*="filters"]')->eq($position)->link();

        $crawler = $client->click($link);

        self::assertSame($expected, $this->rowIds($crawler));
    }

    public function testTheDetailPageShowsDurationMemoryAndTheLabelledMetrics(): void
    {
        $client = $this->adminClient();
        $hospital = $this->hospital('Klinikum Kennzahlen');
        $this->record('run-detail', 'closure_rebuild', 'succeeded', metrics: ['hospital_ids' => [$hospital->getId()], 'hours_written' => 7_000], durationMs: 92_000, peakBytes: 37_748_736);

        $crawler = $client->request(Request::METHOD_GET, self::INDEX.'/run-detail');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('1 min 32 s', $text);
        self::assertStringContainsString('36.0 MB', $text);
        self::assertStringContainsString('Klinikum Kennzahlen', $text);
        self::assertStringContainsString('7 000', $text);
        self::assertCount(1, $crawler->filter('th[title="hours_written"]'), 'the metric key is the title of its label');
        self::assertStringNotContainsString('admin.job_run.metric.', $text);
        self::assertStringNotContainsString('Error class', $text);
        self::assertStringNotContainsString('Fehlerklasse', $text);
    }

    public function testTheDetailPageShowsTheErrorAndLinksToTheImport(): void
    {
        $client = $this->adminClient();
        $this->record('run-error', 'allocation_import', 'failed', importId: 77, importRunId: '0199f0a4-7d3c-7a51-9b1e-4f2a6c8d9e02', error: new \RuntimeException('Row 12 <b>broken</b>'));

        $crawler = $client->request(Request::METHOD_GET, self::INDEX.'/run-error');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(\RuntimeException::class, $crawler->text());
        self::assertMatchesRegularExpression('/Error class|Fehlerklasse/', $crawler->text());
        self::assertStringContainsString('Row 12 <b>broken</b>', $crawler->text());
        self::assertCount(0, $crawler->filter('b:contains("broken")'));
        self::assertCount(1, $crawler->filter('a[href*="/admin/import/77"]'));

        $hrefs = $crawler->filter('a')->each(static fn (Crawler $link): string => urldecode((string) $link->attr('href')));
        self::assertNotEmpty(array_filter($hrefs, static fn (string $href): bool => str_contains($href, 'filters[importRunId][value]=0199f0a4-7d3c-7a51-9b1e-4f2a6c8d9e02')));
        self::assertNotEmpty(array_filter($hrefs, static fn (string $href): bool => str_contains($href, 'filters[importId][value]=77')));
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }

    private function hospital(string $name): Hospital
    {
        return HospitalFactory::createOne([
            'name' => $name,
            'owner' => UserFactory::createOne(),
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);
    }

    /**
     * @param list<string>                                  $thresholds
     * @param array<string, int|bool|string|list<int>|null> $metrics
     */
    private function record(
        string $id,
        string $job,
        string $status,
        string $trigger = 'messenger',
        ?int $importId = null,
        ?string $importRunId = null,
        ?int $hospitalId = null,
        array $thresholds = [],
        array $metrics = [],
        string $startedAt = '2026-10-25 12:00:00',
        int $durationMs = 1_000,
        int $peakBytes = 1_048_576,
        ?\Throwable $error = null,
    ): void {
        $recorder = new JobRunRecorder($this->connection(), new MockClock(new \DateTimeImmutable($startedAt)), new NullLogger());
        $recorder->start($id, $job, $trigger, importId: $importId, importRunId: $importRunId, hospitalId: $hospitalId);
        if ('running' !== $status) {
            $recorder->finish($id, $job, $status, $durationMs, $peakBytes, $thresholds, $metrics, $error);
        }
    }

    /**
     * @return list<string>
     */
    private function rowIds(Crawler $crawler): array
    {
        return array_values($crawler->filter('table.datagrid tbody tr[data-id]')->each(static fn (Crawler $row): string => (string) $row->attr('data-id')));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
