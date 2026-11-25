<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Application\Export\QueryCheckerInterface;
use App\Research\Application\Export\QueryDryRunnerInterface;
use App\Research\Application\Export\QueryViolation;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestQuery;
use App\Research\Domain\Entity\DataRequestQueryVersion;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Export queries of an approved data extract request (ADR 045). Only administrators add and change them; every change
 * is a new version, checked against the allowlist when it is saved. A dry run is possible once the check passes.
 * Everyone who may see the request reads the SQL; the timeline records each saved version publicly.
 */
final readonly class DataRequestQueryService
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private ClockInterface $clock,
        private QueryCheckerInterface $checker,
        private QueryDryRunnerInterface $dryRunner,
    ) {
    }

    public function canManage(User $user, DataRequest $request): bool
    {
        return \in_array(UserRole::ADMIN, $user->getRoles(), true) && DataRequestStatus::Approved === $request->getStatus();
    }

    /**
     * @throws DataRequestRefused
     */
    public function add(User $admin, DataRequest $request, string $name, string $description, QueryKind $kind, string $sql): void
    {
        $this->assertManage($admin, $request);
        try {
            $query = $request->addQuery(trim($name), $description, $kind, $sql, $admin, $this->clock->now());
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::QUERY_INVALID, $exception);
        }

        $this->checkVersion($query->getCurrentVersion());
        $this->recordSaved($admin, $query);
        $this->flush('research.data_request.query_added', $request);
    }

    /**
     * A new version of the SQL; the description may change with it.
     *
     * @throws DataRequestRefused
     */
    public function revise(User $admin, DataRequestQuery $query, string $description, string $sql): void
    {
        $request = $query->getRequest();
        $this->assertManage($admin, $request);
        try {
            $query->describe($description);
            if (trim($sql) !== $query->getCurrentVersion()->getSql()) {
                $query->revise($sql, $admin, $this->clock->now());
                $this->checkVersion($query->getCurrentVersion());
                $this->recordSaved($admin, $query);
            }
        } catch (DataRequestException $exception) {
            throw new DataRequestRefused(DataRequestRefused::QUERY_INVALID, $exception);
        }

        $this->flush('research.data_request.query_revised', $request);
    }

    /**
     * @throws DataRequestRefused
     */
    public function dryRun(User $admin, DataRequestQuery $query): void
    {
        $request = $query->getRequest();
        $this->assertManage($admin, $request);
        $version = $query->getCurrentVersion();
        $this->checkVersion($version);
        if (!$version->passesCheck()) {
            $this->flush('research.data_request.query_checked', $request);

            throw new DataRequestRefused(DataRequestRefused::QUERY_CHECK_FAILED);
        }

        $version->recordDryRun($this->dryRunner->run($version->getSql(), $query->getKind())->toArray());
        $this->flush('research.data_request.query_dry_run', $request);
    }

    private function checkVersion(DataRequestQueryVersion $version): void
    {
        $violations = array_map(static fn (QueryViolation $violation): array => $violation->toArray(), $this->checker->check($version->getSql(), $version->getQuery()->getKind()));
        $version->recordCheck($violations, $this->clock->now());
    }

    private function recordSaved(User $admin, DataRequestQuery $query): void
    {
        $query->getRequest()->recordEvent(TimelineEventType::QuerySaved, $admin, Visibility::Public, $this->clock->now(), [
            'query' => $query->getName(),
            'version' => $query->getCurrentVersion()->getNumber(),
        ]);
    }

    /**
     * @throws DataRequestRefused
     */
    private function assertManage(User $user, DataRequest $request): void
    {
        if (!$this->canManage($user, $request)) {
            throw new DataRequestRefused(DataRequestRefused::NOT_ALLOWED);
        }
    }

    private function flush(string $intent, DataRequest $request): void
    {
        $this->auditContext->beginIntent($intent, ['data_request_id' => $request->getId()]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }
}
