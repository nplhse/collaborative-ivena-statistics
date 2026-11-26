<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Application\Export\ExportAllowlist;
use App\Research\Application\Export\ExportDataStateInterface;
use App\Research\Application\Export\ExportFile;
use App\Research\Application\Export\ExportPackage;
use App\Research\Application\Export\ExportRunnerInterface;
use App\Research\Application\Export\ExportStorageInterface;
use App\Research\Application\Message\RunDataExportMessage;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestExportRun;
use App\Research\Domain\Entity\DataRequestQuery;
use App\Research\Domain\Entity\DataRequestQueryVersion;
use App\Research\Domain\Entity\DataRequestRelease;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\ExportRunStatus;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\ReleaseStatus;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Release and export run of an approved data extract request (ADR 045).
 *
 * An administrator releases the current version of every query for the applicant. The applicant starts the export;
 * the worker runs all queries in one read-only transaction, checks the minimum of hospitals per group and packs the
 * files with a manifest and a read-me. The archive is downloadable for seven days by the recipient and by
 * administrators; each download is recorded. A technical failure may be tried again in the same run; a blocked run
 * needs a changed query and a new release.
 */
final readonly class DataRequestReleaseService
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DataRequestChangeAnnouncer $changes,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private ExportRunnerInterface $runner,
        private ExportStorageInterface $storage,
        private ExportDataStateInterface $dataState,
        private ExportPackage $package,
        private UrlGeneratorInterface $urls,
        #[Autowire(param: 'kernel.default_locale')]
        private string $defaultLocale,
        #[Autowire(param: 'app.version')]
        private string $appVersion,
    ) {
    }

    public function canRelease(User $user, DataRequest $request): bool
    {
        if (!$this->isAdmin($user) || DataRequestStatus::Approved !== $request->getStatus() || !$request->getRequester() instanceof User) {
            return false;
        }

        $queries = $request->getQueries();
        $now = $this->clock->now();

        return array_any($queries, static fn (DataRequestQuery $query): bool => $query->getCurrentVersion()->passesCheck())
            && array_all($request->getReleases(), static fn (DataRequestRelease $release): bool => !$release->isOpenAt($now));
    }

    public function needsSingleCaseConfirmation(DataRequest $request): bool
    {
        return array_any($request->getQueries(), static fn (DataRequestQuery $query): bool => QueryKind::SingleCases === $query->getKind());
    }

    /**
     * @param list<string>|null $queryNames the queries to release; null for every query that passes the check
     * @param string|null       $conditions what the recipient accepts before the export; empty for none
     *
     * @throws DataRequestRefused
     */
    public function release(User $admin, DataRequest $request, bool $singleCasesConfirmed, ?array $queryNames = null, ?string $conditions = null): DataRequestRelease
    {
        if (!$this->canRelease($admin, $request)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $now = $this->clock->now();
        try {
            $release = $request->release($queryNames, $singleCasesConfirmed, $admin, $now, $conditions);
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::RELEASE_INVALID, $exception);
        }

        $request->recordEvent(TimelineEventType::Released, $admin, Visibility::Private, $now, [
            'release' => $release->getNumber(),
            'until' => $release->getValidUntil()->format(\DATE_ATOM),
            'excluded' => implode(', ', $release->getExcludedQueries()),
        ]);
        $this->flush('research.data_request.released', $request);

        return $release;
    }

    public function canStart(User $user, DataRequestRelease $release): bool
    {
        return $release->isStartableBy($user, $this->clock->now());
    }

    /**
     * The read-me is written in the recipient's saved language, else in the language of the session that starts it.
     * A release with conditions starts only when the recipient accepts them.
     *
     * @throws DataRequestRefused
     */
    public function start(User $recipient, DataRequestRelease $release, ?string $sessionLocale = null, bool $acceptsConditions = false): void
    {
        $now = $this->clock->now();
        if (null !== $release->getConditions() && !$acceptsConditions) {
            throw new DataRequestRefused(DataRequestRefused::CONDITIONS_NOT_ACCEPTED);
        }

        $locale = SupportedLocales::normalize($recipient->getLocale()) ?? SupportedLocales::normalize($sessionLocale) ?? $this->defaultLocale;
        try {
            $run = $release->startExport($recipient, $now, $locale, $acceptsConditions);
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED, $exception);
        }

        $release->getRequest()->recordEvent(TimelineEventType::ExportRequested, $recipient, Visibility::Private, $now, array_filter([
            'release' => $release->getNumber(),
            'accepted' => null !== $release->getConditions() ? 1 : 0,
        ]));
        try {
            $this->flush('research.data_request.export_requested', $release->getRequest());
        } catch (UniqueConstraintViolationException $exception) {
            // A parallel click created the run first; it is the same export.
            throw new DataRequestRefused(DataRequestRefused::CHANGED_MEANWHILE, $exception);
        }

        $this->bus->dispatch(new RunDataExportMessage((int) $run->getId()));
    }

    public function canRevoke(User $user, DataRequestRelease $release): bool
    {
        return $this->isAdmin($user) && ReleaseStatus::Active === $release->getStatus();
    }

    /**
     * An administrator revokes a release whose export was not started; the reason goes into the timeline.
     *
     * @throws DataRequestRefused
     */
    public function revoke(User $admin, DataRequestRelease $release, string $reason): void
    {
        if (!$this->canRevoke($admin, $release)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $now = $this->clock->now();
        try {
            $release->revoke($reason, $now);
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::REASON_REQUIRED, $exception);
        }

        $request = $release->getRequest();
        $request->recordEvent(TimelineEventType::ReleaseRevoked, $admin, Visibility::Private, $now, [
            'release' => $release->getNumber(),
            'reason' => (string) $release->getRevocationReason(),
        ]);
        $this->flush('research.data_request.release_revoked', $request);
    }

    /**
     * A release whose three months passed without an export expires (cleanup job).
     *
     * @throws DataRequestRefused when it is not due
     */
    public function expireRelease(DataRequestRelease $release): void
    {
        $now = $this->clock->now();
        try {
            $release->expire($now);
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED, $exception);
        }

        $request = $release->getRequest();
        $request->recordEvent(TimelineEventType::ReleaseExpired, null, Visibility::Private, $now, ['release' => $release->getNumber()]);
        $this->flush('research.data_request.release_expired', $request);
    }

    /**
     * The applicant withdrew the request: open releases are revoked, exports that are queued, running or downloadable
     * are cancelled. The caller flushes; the returned archives are removed after that with removeArchives().
     *
     * @return list<string> archives to remove
     */
    public function endWithWithdrawal(DataRequest $request): array
    {
        $now = $this->clock->now();
        $archives = [];
        foreach ($request->getReleases() as $release) {
            $run = $release->getRun();
            $wasOpen = $run instanceof DataRequestExportRun && ($run->getStatus()->isPending() || ExportRunStatus::Available === $run->getStatus());
            $archive = $release->endWithWithdrawal($now);
            if (null !== $archive) {
                $archives[] = $archive;
            }

            if ($wasOpen) {
                $request->recordEvent(TimelineEventType::ExportCancelled, null, Visibility::Private, $now, ['release' => $release->getNumber()]);
            }
        }

        return $archives;
    }

    /**
     * @param list<string> $archives
     */
    public function removeArchives(array $archives): void
    {
        foreach ($archives as $archive) {
            $this->storage->remove($archive);
        }
    }

    /**
     * @throws DataRequestRefused
     */
    public function retry(User $admin, DataRequestExportRun $run): void
    {
        if (!$this->isAdmin($admin)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $now = $this->clock->now();
        try {
            $run->retry($now);
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED, $exception);
        }

        $request = $run->getRelease()->getRequest();
        $request->recordEvent(TimelineEventType::ExportRetried, $admin, Visibility::Internal, $now, ['release' => $run->getRelease()->getNumber()]);
        $this->flush('research.data_request.export_retried', $request);
        $this->bus->dispatch(new RunDataExportMessage((int) $run->getId()));
    }

    /**
     * The worker takes a queued run over. False when it is not queued (handled already) or another worker was faster.
     */
    public function claim(DataRequestExportRun $run): bool
    {
        try {
            $run->start($this->clock->now());
            $this->flush('research.data_request.export_started', $run->getRelease()->getRequest());
        } catch (DataRequestException|OptimisticLockException) {
            return false;
        }

        return true;
    }

    /**
     * Runs a run the worker has just taken over (status running) and records the result.
     */
    public function execute(DataRequestExportRun $run): void
    {
        $release = $run->getRelease();
        $request = $release->getRequest();
        $key = 'run-'.(int) $run->getId().'-'.$run->getAttempts();
        $executedAt = $this->clock->now();
        $dataState = $this->dataState->current();
        $versions = $release->getQueryVersions();
        $record = [
            'executed_at' => $executedAt->format(\DATE_ATOM),
            'export_version' => ['application' => $this->appVersion, 'allowlist' => ExportAllowlist::VERSION],
            'data_state' => $dataState,
            'recipient' => $release->getRecipient()?->getUserIdentifier(),
            'queries' => array_map(static fn (DataRequestQueryVersion $version): array => [
                'name' => $version->getQuery()->getName(),
                'kind' => $version->getQuery()->getKind()->value,
                'version' => $version->getNumber(),
                'sql_sha256' => $version->getChecksum(),
            ], $versions),
        ];

        try {
            $outcome = $this->runner->run($versions, $this->storage->workDirectory($key));
            $record += [
                'settings' => $outcome->settings,
                'duration_ms' => $outcome->durationMs,
                'files' => array_map(static fn (ExportFile $file): array => $file->toRecord(), $outcome->files),
            ];

            if (null !== $outcome->error) {
                $this->storage->discard($key);
                $this->finishFailed($run, $outcome->error, $record);
            } elseif ($outcome->isBlocked()) {
                $this->storage->discard($key);
                $run->block($record, $this->clock->now());
                $request->recordEvent(TimelineEventType::ExportBlocked, null, Visibility::Internal, $this->clock->now(), ['release' => $release->getNumber()]);
            } else {
                $this->provide($run, $key, $outcome->files, $dataState, $executedAt, $record);
            }
        } catch (\Throwable $exception) {
            $this->storage->discard($key);
            $this->finishFailed($run, $exception::class.': '.$exception->getMessage(), $record);
        }

        try {
            $this->flush('research.data_request.export_finished', $request);
        } catch (OptimisticLockException) {
            // The request was withdrawn while the export ran: nothing is provided, the archive goes.
            $archive = $run->getArchiveFile();
            if (null !== $archive) {
                $this->storage->remove($archive);
            }
        }
    }

    public function canDownload(User $user, DataRequestExportRun $run): bool
    {
        return ($run->getRelease()->isFor($user) || $this->isAdmin($user)) && $run->isDownloadableAt($this->clock->now());
    }

    /**
     * Records the download and returns the archive's path.
     *
     * @throws DataRequestRefused
     */
    public function download(User $user, DataRequestExportRun $run): string
    {
        $path = $this->canDownload($user, $run) ? $this->storage->path((string) $run->getArchiveFile()) : null;
        if (null === $path) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }

        $run->recordDownload($user, $this->clock->now());
        $this->flush('research.data_request.export_downloaded', $run->getRelease()->getRequest());

        return $path;
    }

    /**
     * Ends the download period of a run and removes its archive (cleanup job).
     *
     * @throws DataRequestRefused when the run is not due
     */
    public function expire(DataRequestExportRun $run): void
    {
        try {
            $archive = $run->expire($this->clock->now());
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED, $exception);
        }

        $this->flush('research.data_request.export_expired', $run->getRelease()->getRequest());
        if (null !== $archive) {
            $this->storage->remove($archive);
        }
    }

    /**
     * Removes archives no run refers to and leftover working directories older than a day (cleanup job).
     *
     * @param list<string> $archivesInUse
     */
    public function removeOrphans(array $archivesInUse): int
    {
        return $this->storage->removeOrphans($archivesInUse, $this->clock->now()->modify('-1 day'));
    }

    public static function archiveName(DataRequestExportRun $run): string
    {
        return ExportPackage::folder($run).'.tar.gz';
    }

    /**
     * @param list<ExportFile>                                                       $files
     * @param array{latest_import_at: ?string, imports: int, latest_import_id: ?int} $dataState
     * @param array<string, mixed>                                                   $record
     */
    private function provide(DataRequestExportRun $run, string $key, array $files, array $dataState, \DateTimeImmutable $executedAt, array $record): void
    {
        $release = $run->getRelease();
        $request = $release->getRequest();
        $locale = $run->getLocale();
        $url = $this->urls->generate('app_data_requests_show', ['publicId' => $request->getPublicIdString()], UrlGeneratorInterface::ABSOLUTE_URL);
        $manifest = $this->package->manifest($run, $files, $dataState, $executedAt, $url);

        $archive = $this->storage->pack($key, ExportPackage::folder($run), $files, [
            'manifest.json' => json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n",
            ExportPackage::readmeName($locale) => $this->package->readme($manifest, $locale),
        ]);
        $path = (string) $this->storage->path($archive);
        $checksum = (string) hash_file('sha256', $path);
        $bytes = (int) filesize($path);
        $record['archive'] = ['sha256' => $checksum, 'bytes' => $bytes, 'readme' => ExportPackage::readmeName($locale)];

        $now = $this->clock->now();
        $run->provide($archive, $bytes, $checksum, $record, $now);
        $request->recordEvent(TimelineEventType::ExportProvided, null, Visibility::Private, $now, [
            'release' => $release->getNumber(),
            'until' => (string) $run->getAvailableUntil()?->format(\DATE_ATOM),
        ]);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function finishFailed(DataRequestExportRun $run, string $error, array $record): void
    {
        $now = $this->clock->now();
        $run->fail($error, $record, $now);
        $run->getRelease()->getRequest()->recordEvent(TimelineEventType::ExportFailed, null, Visibility::Internal, $now, ['release' => $run->getRelease()->getNumber()]);
    }

    private function isAdmin(User $user): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }

    private function flush(string $intent, DataRequest $request): void
    {
        $pending = $this->changes->pending($request);
        $this->auditContext->beginIntent($intent, ['data_request_id' => $request->getId()]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $this->changes->announce($request, $pending);
    }
}
