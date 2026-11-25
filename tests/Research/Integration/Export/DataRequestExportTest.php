<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Export;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestQueryService;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReleaseService;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Application\Export\ExportStorageInterface;
use App\Research\Application\Message\CleanUpDataExportsMessage;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestEvent;
use App\Research\Domain\Entity\DataRequestExportRun;
use App\Research\Domain\Entity\DataRequestRelease;
use App\Research\Domain\Enum\ExportRunStatus;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Infrastructure\Scheduler\CleanUpDataExportsMessageHandler;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Release, export run, archive and download of a data extract request on the fixture of ExportFixtureTrait (ADR 045).
 * The Messenger transport is synchronous in tests, so starting the export runs it.
 */
#[ResetDatabase]
final class DataRequestExportTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ExportFixtureTrait;
    use Factories;

    private const string URBAN = "SELECT h.location, count(*) AS allocations, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a JOIN hospital h ON h.id = a.hospital_id WHERE h.location = 'Urban' GROUP BY h.location";
    private const string TOTAL = 'SELECT count(*) AS allocations, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a';
    private const string BY_LOCATION = 'SELECT h.location, count(*) AS allocations, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a JOIN hospital h ON h.id = a.hospital_id GROUP BY h.location ORDER BY h.location';
    private const string FAILING = 'SELECT count(*) / 0 AS broken, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a';

    public function testTheApplicantCreatesAnExportWithSeveralFilesAndDownloadsIt(): void
    {
        self::mockTime('2026-12-01 09:00');
        $this->seedHospitals();
        $applicant = UserFactory::new()->asParticipant()->create(['locale' => 'de']);
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved($applicant, ['urban' => self::URBAN, 'total' => self::TOTAL], $admin);

        $release = $this->releases()->release($admin, $request, false);
        self::assertFalse($this->releases()->canRelease($admin, $request), 'one open release at a time');
        self::assertFalse($this->releases()->canStart($admin, $release), 'only the recipient starts');

        $this->releases()->start($applicant, $release);
        $run = $this->exportRun($release);

        self::assertSame(ExportRunStatus::Available, $run->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-08 09:00'), $run->getAvailableUntil());
        $files = array_column($run->getRecord()['files'], null, 'file');
        // Urban: 4 allocations from 4 hospitals; total: 6 allocations from all 5 hospitals.
        self::assertSame([1, 4], [$files['urban.csv']['rows'], $files['urban.csv']['hospitals']]);
        self::assertSame([['hospitals' => 4, 'groups' => 1]], $files['urban.csv']['groups_by_hospitals']);
        self::assertSame([1, 5], [$files['total.csv']['rows'], $files['total.csv']['hospitals']]);
        self::assertSame(5, $run->getRecord()['data_state']['imports']);
        self::assertSame($applicant->getUsername(), $run->getRecord()['recipient'], 'the recipient is named in the internal record only');

        $path = $this->releases()->download($applicant, $run);
        $folder = 'data-request-'.$request->getId().'-release-1';
        $archive = new \PharData($path);
        self::assertSame("location,allocations\nUrban,4\n", file_get_contents($archive[$folder.'/urban.csv']->getPathname()));
        self::assertSame("allocations\n6\n", file_get_contents($archive[$folder.'/total.csv']->getPathname()));

        $manifest = json_decode((string) file_get_contents($archive[$folder.'/manifest.json']->getPathname()), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['urban.csv', 'total.csv'], array_column($manifest['files'], 'file'));
        self::assertSame(hash('sha256', "allocations\n6\n"), $manifest['files'][1]['sha256']);
        self::assertStringNotContainsString((string) $applicant->getUsername(), (string) json_encode($manifest), 'no user name in the archive');

        $readme = (string) file_get_contents($archive[$folder.'/LIESMICH.txt']->getPathname());
        self::assertStringContainsString('Antrag Nr. '.$request->getId(), $readme);
        self::assertStringContainsString('1 Zeile aus 4 Kliniken', $readme);
        self::assertStringContainsString('Zitiervorschlag', $readme);

        self::assertCount(1, $run->getDownloads());
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn (): string => $this->releases()->download(UserFactory::new()->asParticipant()->create(), $run)));
        $this->releases()->download($admin, $run);
        self::assertCount(2, $run->getDownloads(), 'administrators download for quality checks');

        self::mockTime('2026-12-08 09:00');
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn (): string => $this->releases()->download($applicant, $run)), 'the download period ended');
        self::assertTrue($this->releases()->canRelease($admin, $request), 'a new release is possible after the period');
    }

    public function testTheCleanupExpiresExportsAndRemovesTheirFiles(): void
    {
        self::mockTime('2026-12-01 09:00');
        $this->seedHospitals();
        $applicant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved($applicant, ['total' => self::TOTAL], $admin);
        $release = $this->releases()->release($admin, $request, false);
        $this->releases()->start($applicant, $release);
        $run = $this->exportRun($release);
        $storage = self::getContainer()->get(ExportStorageInterface::class);
        $archive = (string) $run->getArchiveFile();
        self::assertNotNull($storage->path($archive));

        $directory = (string) self::getContainer()->getParameter('app.research.exports_dir');
        $orphan = $directory.'/ffffffffffffffffffffffffffffffff.tar.gz';
        file_put_contents($orphan, 'x');
        touch($orphan, new \DateTimeImmutable('2026-12-07 09:00')->getTimestamp());
        $leftover = $directory.'/work/run-999-1';
        mkdir($leftover, 0o700, true);
        touch($leftover, new \DateTimeImmutable('2026-12-07 09:00')->getTimestamp());
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->releases()->expire($run)), 'not due yet');
        $cleanup = self::getContainer()->get(CleanUpDataExportsMessageHandler::class);

        self::mockTime('2026-12-08 08:59');
        $cleanup(new CleanUpDataExportsMessage());
        self::assertSame(ExportRunStatus::Available, $run->getStatus(), 'still within the seven days');
        self::assertFileExists($orphan, 'an orphan younger than a day stays');

        self::mockTime('2026-12-08 09:30');
        $cleanup(new CleanUpDataExportsMessage());
        self::assertSame(ExportRunStatus::Expired, $run->getStatus());
        self::assertNull($run->getArchiveFile());
        self::assertNull($storage->path($archive), 'the archive is removed');
        self::assertFileDoesNotExist($orphan, 'an archive no run refers to is removed');
        self::assertDirectoryDoesNotExist($leftover, 'a working directory of an interrupted run is removed');

        $cleanup(new CleanUpDataExportsMessage());
        self::assertSame([], glob($directory.'/*.tar.gz') ?: [], 'nothing is due any more');
    }

    public function testAGroupBelowTheMinimumBlocksTheExport(): void
    {
        self::mockTime('2026-12-01 09:00');
        $this->seedHospitals();
        $applicant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved($applicant, ['by_location' => self::BY_LOCATION], $admin);
        $release = $this->releases()->release($admin, $request, false);

        $this->releases()->start($applicant, $release);
        $run = $this->exportRun($release);

        self::assertSame(ExportRunStatus::Blocked, $run->getStatus());
        self::assertNull($run->getArchiveFile());
        $file = $run->getRecord()['files'][0];
        self::assertSame(1, $file['below_minimum']);
        self::assertSame([['location' => 'Rural', 'allocations' => 2]], $file['below_minimum_rows']);
        self::assertSame([['hospitals' => 1, 'groups' => 1], ['hospitals' => 4, 'groups' => 1]], $file['groups_by_hospitals']);
        self::assertSame('internal', $this->lastEvent($request)->getVisibility()->value);
        self::assertFalse($this->releases()->canDownload($applicant, $run));
        self::assertTrue($this->releases()->canRelease($admin, $request), 'a blocked run does not hold the next release');
    }

    public function testAQueryLeftOutOfTheReleaseIsNotExported(): void
    {
        self::mockTime('2026-12-01 09:00');
        $this->seedHospitals();
        $applicant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved($applicant, ['urban' => self::URBAN, 'by_location' => self::BY_LOCATION], $admin);

        self::assertSame(DataRequestRefused::RELEASE_INVALID, $this->refusal(fn (): DataRequestRelease => $this->releases()->release($admin, $request, false, [])), 'a release needs a query');
        $release = $this->releases()->release($admin, $request, false, ['urban']);
        $this->releases()->start($applicant, $release);
        $run = $this->exportRun($release);

        self::assertSame(ExportRunStatus::Available, $run->getStatus(), 'the query with the small group is not part of it');
        self::assertSame(['urban.csv'], array_column($run->getRecord()['files'], 'file'));
        self::assertSame(['by_location'], $release->getExcludedQueries());
        $events = array_values(array_filter($request->getEvents(), static fn (DataRequestEvent $event): bool => 'released' === $event->getType()->value));
        self::assertSame('by_location', $events[0]->getDetails()['excluded']);
    }

    public function testAFailedRunIsTriedAgainInTheSameRun(): void
    {
        self::mockTime('2026-12-01 09:00');
        $this->seedHospitals();
        $applicant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved($applicant, ['broken' => self::FAILING], $admin);
        $release = $this->releases()->release($admin, $request, false);

        $this->releases()->start($applicant, $release);
        $run = $this->exportRun($release);
        self::assertSame(ExportRunStatus::Failed, $run->getStatus());
        self::assertStringContainsString('division by zero', (string) $run->getError());

        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->releases()->retry($applicant, $run)));
        $this->releases()->retry($admin, $run);
        self::assertSame(ExportRunStatus::Failed, $run->getStatus());
        self::assertSame(2, $run->getAttempts());
        self::assertSame($run, $release->getRun(), 'still the one run of the release');
        self::assertFalse($this->releases()->claim($run), 'a run that is not queued is not taken over again');
        self::assertSame([], glob(self::getContainer()->getParameter('app.research.exports_dir').'/work/*') ?: [], 'no working files are left');
    }

    public function testSingleCasesNeedTheConfirmationAndTheExportStartsOnce(): void
    {
        self::mockTime('2026-12-01 09:00');
        $this->seedHospitals();
        $applicant = UserFactory::new()->asParticipant()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved($applicant, [], $admin);
        self::assertFalse($this->releases()->canRelease($admin, $request), 'no release without queries');
        self::getContainer()->get(DataRequestQueryService::class)->add($admin, $request, 'cases', 'Allocations as single cases.', QueryKind::SingleCases, 'SELECT a.age, a.hospital_id AS _hospital_id FROM allocation a');

        self::assertTrue($this->releases()->needsSingleCaseConfirmation($request));
        self::assertSame(DataRequestRefused::RELEASE_INVALID, $this->refusal(fn (): DataRequestRelease => $this->releases()->release($admin, $request, false)));
        $release = $this->releases()->release($admin, $request, true);

        $this->releases()->start($applicant, $release);
        self::assertSame(ExportRunStatus::Available, $this->exportRun($release)->getStatus());
        self::assertSame(6, $this->exportRun($release)->getRecord()['files'][0]['rows']);
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->releases()->start($applicant, $release)), 'one run per release');

        $storage = self::getContainer()->get(ExportStorageInterface::class);
        self::assertNull($storage->path('../../etc/passwd'));
    }

    /**
     * @param array<string, string> $queries name => SQL, aggregated
     */
    private function approved(User $applicant, array $queries, User $admin): DataRequest
    {
        $draft = new DataRequestDraft();
        $draft->title = 'Stroke pathways';
        $draft->summary = 'Summary';
        $draft->institution = 'University';
        $draft->purpose = 'Purpose';
        $draft->periodFrom = new \DateTimeImmutable('2024-01-01');
        $draft->periodTo = new \DateTimeImmutable('2024-12-31');
        $draft->population = 'All allocations';
        $draft->variables = 'Month';
        $draft->granularity = 'Month';
        $draft->recipients = 'Team';
        $draft->description = 'Description';
        $draft->dataNeeds = 'Data needs';
        $draft->responsiblePersons = 'Dr. A';
        $draft->conflictsOfInterest = 'none';
        $draft->ethics = 'Not needed';

        $service = self::getContainer()->get(DataRequestService::class);
        $request = $service->createDraft($applicant, $draft);
        $service->submit($applicant, $request);
        $member = UserFactory::new()->asBoardMember()->create();
        $review = self::getContainer()->get(DataRequestReviewService::class);
        $review->openRound($member, $request);
        $review->castVote($member, $request, VoteChoice::Approve, null);

        foreach ($queries as $name => $sql) {
            self::getContainer()->get(DataRequestQueryService::class)->add($admin, $request, $name, 'Description of '.$name.'.', QueryKind::Aggregated, $sql);
        }

        return $request;
    }

    private function exportRun(DataRequestRelease $release): DataRequestExportRun
    {
        $run = $release->getRun();
        self::assertInstanceOf(DataRequestExportRun::class, $run);

        return $run;
    }

    private function lastEvent(DataRequest $request): DataRequestEvent
    {
        $events = $request->getEvents();
        $event = end($events);
        self::assertInstanceOf(DataRequestEvent::class, $event);

        return $event;
    }

    private function refusal(callable $step): string
    {
        try {
            $step();
        } catch (DataRequestRefused $refused) {
            return $refused->reason;
        }

        self::fail('The step was not refused.');
    }

    private function releases(): DataRequestReleaseService
    {
        return self::getContainer()->get(DataRequestReleaseService::class);
    }
}
