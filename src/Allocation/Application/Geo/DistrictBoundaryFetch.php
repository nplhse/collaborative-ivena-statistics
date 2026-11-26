<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo;

use App\Allocation\Application\Contracts\DistrictBoundaryClientInterface;
use App\Allocation\Application\Geo\DTO\DistrictBoundaryStatus;
use App\Allocation\Application\Message\FetchDistrictBoundaryMessage;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;
use App\Allocation\Infrastructure\Repository\DistrictRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * First fetch of a missing district boundary, started by an admin. A new attempt replaces a failed or stale one; only
 * the current attempt may write, and a geometry that exists by then is never replaced (ADR 041).
 */
final readonly class DistrictBoundaryFetch
{
    public function __construct(
        private DistrictRepository $districts,
        private DistrictBoundaryClientInterface $client,
        private MessageBusInterface $bus,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether a new attempt was started
     */
    public function request(District $district): bool
    {
        $districtId = (int) $district->getId();
        $attemptId = Uuid::v4()->toRfc4122();
        $now = $this->clock->now();

        if (!$this->districts->startBoundaryFetch($districtId, $attemptId, $now, $now->modify(sprintf('-%d minutes', DistrictBoundaryStatus::STALE_AFTER_MINUTES)))) {
            return false;
        }

        try {
            $this->bus->dispatch(new FetchDistrictBoundaryMessage($districtId, $attemptId));
        } catch (ExceptionInterface $exception) {
            $this->logger->error('District boundary fetch could not be queued.', ['exception' => $exception, 'district_id' => $districtId]);
            $this->districts->failBoundaryFetch($districtId, $attemptId, DistrictBoundaryFetchFailure::DispatchFailed);

            return false;
        }

        return true;
    }

    public function run(int $districtId, string $attemptId): void
    {
        if (!$this->districts->isCurrentBoundaryFetch($districtId, $attemptId)) {
            $this->logger->info('Skipped an outdated district boundary fetch.', ['district_id' => $districtId, 'attempt_id' => $attemptId]);

            return;
        }

        $district = $this->districts->find($districtId);
        if (!$district instanceof District) {
            return;
        }

        $outcome = $this->client->fetch($district->getAgs());
        if (null === $outcome->geometry || null === $outcome->provider) {
            $this->districts->failBoundaryFetch($districtId, $attemptId, $outcome->failure ?? DistrictBoundaryFetchFailure::Unavailable);

            return;
        }

        if (!DistrictGeometryValidator::isUsable($outcome->geometry)) {
            $this->districts->failBoundaryFetch($districtId, $attemptId, DistrictBoundaryFetchFailure::InvalidGeometry);

            return;
        }

        $stored = $this->districts->completeBoundaryFetch(
            $districtId,
            $attemptId,
            json_encode($outcome->geometry, \JSON_THROW_ON_ERROR),
            $outcome->provider,
            $outcome->licence,
            $outcome->attribution,
            $this->clock->now(),
        );
        if (!$stored) {
            $this->logger->info('Discarded the result of an outdated district boundary fetch.', ['district_id' => $districtId, 'attempt_id' => $attemptId]);

            return;
        }

        if (null === $district->getKind() && null !== $outcome->kind) {
            $district->setKind($outcome->kind);
            $this->entityManager->flush();
        }
    }
}
