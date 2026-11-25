<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Domain\Entity;

use App\Research\Application\Export\ExportFile;
use App\Research\Application\Export\ExportOutcome;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\ExportRunStatus;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\ReleaseStatus;
use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Rules of releases and export runs (ADR 045): what a release binds, who starts its one run, the states of the run and
 * the seven-day download period.
 */
final class DataRequestReleaseTest extends TestCase
{
    private const string AT = '2026-12-01 09:00';

    public function testAReleaseBindsTheCheckedVersionsForTheApplicantForThreeMonths(): void
    {
        [$request, $applicant] = $this->approved();
        $admin = $this->user(2);

        $release = $request->release(false, $admin, new \DateTimeImmutable(self::AT));

        self::assertSame(1, $release->getNumber());
        self::assertSame($request, $release->getRequest());
        self::assertSame($applicant, $release->getRecipient());
        self::assertSame($admin, $release->getCreatedBy());
        self::assertEquals(new \DateTimeImmutable(self::AT), $release->getCreatedAt());
        self::assertEquals(new \DateTimeImmutable('2027-03-01 09:00'), $release->getValidUntil());
        self::assertSame(ReleaseStatus::Active, $release->getStatus());
        self::assertFalse($release->isSingleCasesConfirmed());
        self::assertSame([$request->getQueries()[0]->getCurrentVersion()], $release->getQueryVersions());
        self::assertSame($release, $request->getLatestRelease());
        self::assertSame($release, $request->findRelease(1));
        self::assertNull($request->findRelease(2));
        self::assertNull($release->getId());
        self::assertNull($release->getRun());
    }

    public function testAReleaseIsRefusedWhileAnotherIsOpenOrAQueryIsNotChecked(): void
    {
        [$request] = $this->approved();
        $at = new \DateTimeImmutable(self::AT);
        $request->release(false, null, $at);
        $this->assertRefused(static fn () => $request->release(false, null, $at), 'another release is open');
        self::assertCount(1, $request->getReleases(), 'the first release stays');

        [$unchecked] = $this->approved(checked: false);
        $this->assertRefused(static fn () => $unchecked->release(false, null, $at), 'the version is not checked');

        [$cases] = $this->approved(kind: QueryKind::SingleCases);
        $this->assertRefused(static fn () => $cases->release(false, null, $at), 'single cases without confirmation');
        self::assertTrue($cases->release(true, null, $at)->isSingleCasesConfirmed());

        $draft = new DataRequest($this->user(1), $this->content(), $at);
        $this->assertRefused(static fn (): \App\Research\Domain\Entity\DataRequestRelease => $draft->release(false, null, $at), 'the request is not approved');

        [$empty] = $this->approved(withQuery: false);
        $this->assertRefused(static fn () => $empty->release(false, null, $at), 'there is no query');
    }

    public function testOnlyTheRecipientStartsTheOneRunBeforeTheReleaseExpires(): void
    {
        [$request, $applicant] = $this->approved();
        $release = $request->release(false, null, new \DateTimeImmutable(self::AT));
        $at = new \DateTimeImmutable('2026-12-02 09:00');

        self::assertFalse($release->isStartableBy($this->user(3), $at));
        self::assertFalse($release->isStartableBy($applicant, new \DateTimeImmutable('2027-03-01 09:00')), 'expired after three months');
        $this->assertRefused(fn () => $release->startExport($this->user(3), $at, 'de'), 'not the recipient');

        $run = $release->startExport($applicant, $at, 'de');
        self::assertSame(ReleaseStatus::Used, $release->getStatus());
        self::assertSame($run, $release->getRun());
        self::assertSame($release, $run->getRelease());
        self::assertSame(ExportRunStatus::Queued, $run->getStatus());
        self::assertEquals($at, $run->getQueuedAt());
        self::assertSame('de', $run->getLocale());
        self::assertFalse($release->isStartableBy($applicant, $at), 'one run per release');
        self::assertTrue($release->isOpenAt($at), 'a queued run holds the next release');
    }

    public function testARunMovesThroughItsStates(): void
    {
        [$request, $applicant] = $this->approved();
        $release = $request->release(false, null, new \DateTimeImmutable(self::AT));
        $run = $release->startExport($applicant, new \DateTimeImmutable(self::AT), 'de');
        $this->assertRefused(static fn () => $run->provide('a.tar.gz', 1, 'x', [], new \DateTimeImmutable(self::AT)), 'not running yet');
        $this->assertRefused(static fn () => $run->retry(new \DateTimeImmutable(self::AT)), 'not failed');

        $run->start(new \DateTimeImmutable('2026-12-01 09:01'));
        self::assertSame(ExportRunStatus::Running, $run->getStatus());
        $this->assertRefused(static fn () => $run->start(new \DateTimeImmutable(self::AT)), 'already running');
        $run->fail(str_repeat('x', 3000), ['files' => []], new \DateTimeImmutable('2026-12-01 09:02'));
        self::assertSame(ExportRunStatus::Failed, $run->getStatus());
        self::assertSame(2000, mb_strlen((string) $run->getError()));
        self::assertFalse($release->isOpenAt(new \DateTimeImmutable('2026-12-01 09:03')), 'a failed run does not hold the next release');

        $run->retry(new \DateTimeImmutable('2026-12-01 10:00'));
        self::assertSame(ExportRunStatus::Queued, $run->getStatus());
        self::assertNull($run->getFinishedAt());
        $run->start(new \DateTimeImmutable('2026-12-01 10:01'));
        self::assertNull($run->getError());
        self::assertSame(2, $run->getAttempts());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 10:01'), $run->getStartedAt());

        $run->provide('0123456789abcdef0123456789abcdef.tar.gz', 512, str_repeat('a', 64), ['files' => [1]], new \DateTimeImmutable('2026-12-01 10:02'));
        self::assertSame(ExportRunStatus::Available, $run->getStatus());
        self::assertSame(512, $run->getArchiveBytes());
        self::assertSame(str_repeat('a', 64), $run->getArchiveChecksum());
        self::assertSame('0123456789abcdef0123456789abcdef.tar.gz', $run->getArchiveFile());
        self::assertSame(['files' => [1]], $run->getRecord());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 10:02'), $run->getFinishedAt());
        self::assertTrue($run->isDownloadableAt(new \DateTimeImmutable('2026-12-08 10:01')));
        self::assertFalse($run->isDownloadableAt(new \DateTimeImmutable('2026-12-08 10:02')), 'seven days');
        self::assertTrue($release->isOpenAt(new \DateTimeImmutable('2026-12-05')));
        self::assertFalse($release->isOpenAt(new \DateTimeImmutable('2026-12-09')));

        $run->recordDownload($applicant, new \DateTimeImmutable('2026-12-02'));
        $download = $run->getDownloads()[0];
        self::assertSame($run, $download->getRun());
        self::assertSame($applicant, $download->getUser());
        self::assertEquals(new \DateTimeImmutable('2026-12-02'), $download->getDownloadedAt());
        self::assertNull($download->getId());
        self::assertNull($run->getId());
    }

    public function testABlockedRunProvidesNothing(): void
    {
        [$request, $applicant] = $this->approved();
        $run = $request->release(false, null, new \DateTimeImmutable(self::AT))->startExport($applicant, new \DateTimeImmutable(self::AT), 'de');
        $run->start(new \DateTimeImmutable(self::AT));
        $run->block(['files' => []], new \DateTimeImmutable(self::AT));

        self::assertSame(ExportRunStatus::Blocked, $run->getStatus());
        self::assertNull($run->getArchiveBytes());
        self::assertNull($run->getAvailableUntil());
        self::assertFalse($run->isDownloadableAt(new \DateTimeImmutable(self::AT)));
    }

    public function testStatusesAndOutcomes(): void
    {
        self::assertSame(['blue', 'blue', 'green', 'red', 'red', 'secondary', 'secondary'], array_map(static fn (ExportRunStatus $status): string => $status->badgeVariant(), ExportRunStatus::cases()));
        self::assertSame([true, true, false, false, false, false, false], array_map(static fn (ExportRunStatus $status): bool => $status->isPending(), ExportRunStatus::cases()));
        self::assertSame('data_request.export.status.failed', ExportRunStatus::Failed->labelKey());
        self::assertSame('data_request.release.status.used', ReleaseStatus::Used->labelKey());

        $file = new ExportFile('urban', '/tmp/urban.csv', 2, 40, 'abc', 5, [3 => 1, 5 => 1]);
        $below = new ExportFile('rural', '/tmp/rural.csv', 1, 20, 'def', 1, [1 => 1], 1, [['location' => 'Rural']]);
        self::assertSame('urban.csv', $file->fileName());
        self::assertSame([['hospitals' => 3, 'groups' => 1], ['hospitals' => 5, 'groups' => 1]], $file->toRecord()['groups_by_hospitals']);
        self::assertFalse(new ExportOutcome([$file], [], 1)->isBlocked());
        self::assertTrue(new ExportOutcome([$file, $below], [], 1)->isBlocked());
        self::assertFalse(new ExportOutcome([$below], [], 1, 'timeout')->isBlocked(), 'an error is a failure, not a block');
    }

    /**
     * @return array{DataRequest, User}
     */
    private function approved(bool $checked = true, QueryKind $kind = QueryKind::Aggregated, bool $withQuery = true): array
    {
        $applicant = $this->user(1);
        $request = new DataRequest($applicant, $this->content(), new \DateTimeImmutable('2026-11-30 09:00'));
        $request->setMarking('approved');
        if ($withQuery) {
            $version = $request->addQuery('q', 'Description', $kind, 'SELECT 1', null, new \DateTimeImmutable('2026-11-30 10:00'))->getCurrentVersion();
            if ($checked) {
                $version->recordCheck([], new \DateTimeImmutable('2026-11-30 10:00'));
            }
        }

        return [$request, $applicant];
    }

    private function assertRefused(callable $step, string $case): void
    {
        try {
            $step();
        } catch (DataRequestException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail($case.': not refused.');
    }

    private function user(int $id): User
    {
        $user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }

    private function content(): DataRequestContent
    {
        return new DataRequestContent(
            title: 'Title',
            summary: 'Summary',
            institution: 'University',
            enrichmentPlanned: false,
            purpose: 'Purpose',
            periodFrom: new \DateTimeImmutable('2024-01-01'),
            periodTo: new \DateTimeImmutable('2024-12-31'),
            population: 'All allocations',
            variables: 'Month',
            granularity: 'Month',
            recipients: 'Team',
            enrichmentDetails: null,
            singleCases: false,
            description: 'Description',
            dataNeeds: 'Data needs',
            responsiblePersons: 'Dr. A',
            conflictsOfInterest: 'none',
            ethics: 'Not needed',
        );
    }
}
