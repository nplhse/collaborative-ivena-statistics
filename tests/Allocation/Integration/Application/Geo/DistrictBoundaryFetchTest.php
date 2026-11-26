<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Geo;

use App\Allocation\Application\Contracts\DistrictBoundaryClientInterface;
use App\Allocation\Application\Geo\DistrictBoundaryFetch;
use App\Allocation\Application\Geo\DTO\DistrictBoundaryOutcome;
use App\Allocation\Application\Geo\DTO\DistrictBoundaryStatus;
use App\Allocation\Application\Message\FetchDistrictBoundaryMessage;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;
use App\Allocation\Infrastructure\Repository\DistrictRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class DistrictBoundaryFetchTest extends KernelTestCase
{
    private const array FIRST = ['type' => 'Polygon', 'coordinates' => [[[8.0, 50.0], [8.1, 50.0], [8.1, 50.1], [8.0, 50.0]]]];
    private const array SECOND = ['type' => 'Polygon', 'coordinates' => [[[9.0, 51.0], [9.1, 51.0], [9.1, 51.1], [9.0, 51.0]]]];

    private MockClock $clock;
    /** @var list<FetchDistrictBoundaryMessage> */
    private array $queue = [];
    /** @var list<DistrictBoundaryOutcome> */
    private array $outcomes = [];
    private int $calls = 0;
    private bool $queueFails = false;
    /** @var (\Closure(): void)|null */
    private ?\Closure $duringFetch = null;
    private ?DistrictBoundaryOutcome $lateOutcome = null;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-11-23 12:00:00');
    }

    public function testALateFirstJobCannotOverwriteASuccessfulSecondAttempt(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();

        self::assertTrue($fetch->request($district));
        $firstJob = $this->queue[0];

        // The first job stays in the queue past the limit, so the admin starts a second attempt.
        $this->clock->modify('+16 minutes');
        self::assertTrue($fetch->request($district));
        $secondJob = $this->queue[1];
        self::assertNotSame($firstJob->attemptId, $secondJob->attemptId);

        $this->outcomes = [$this->found(self::SECOND, '© BKG (2026) second')];
        $fetch->run($secondJob->districtId, $secondJob->attemptId);
        $storedAt = $this->geometry($district)->getGeometryFetchedAt();

        // The first job finishes late: it may neither call the service nor write anything.
        $this->clock->modify('+5 minutes');
        $this->outcomes = [$this->found(self::FIRST, '© BKG (2026) first')];
        $fetch->run($firstJob->districtId, $firstJob->attemptId);

        $geometry = $this->geometry($district);
        self::assertSame(1, $this->calls);
        self::assertSame((string) json_encode(self::SECOND), $geometry->getGeometry());
        self::assertSame('© BKG (2026) second', $geometry->getGeometryAttribution());
        self::assertEquals($storedAt, $geometry->getGeometryFetchedAt());
        self::assertSame(DistrictBoundaryStatus::READY, $this->boundaryStatus($district)->state);
    }

    public function testAResultArrivingAfterANewerAttemptSucceededIsDiscarded(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();
        $fetch->request($district);
        $firstJob = $this->queue[0];

        // While the first job waits for the service, a newer attempt starts and stores its geometry.
        $this->duringFetch = function () use ($district, $fetch): void {
            $this->duringFetch = null;
            $this->clock->modify('+16 minutes');
            self::assertTrue($fetch->request($district));
            $this->outcomes = [$this->found(self::SECOND, 'second')];
            $fetch->run($this->queue[1]->districtId, $this->queue[1]->attemptId);
            $this->outcomes = [];
        };
        $this->lateOutcome = $this->found(self::FIRST, 'first');

        $fetch->run($firstJob->districtId, $firstJob->attemptId);

        $geometry = $this->geometry($district);
        self::assertSame((string) json_encode(self::SECOND), $geometry->getGeometry());
        self::assertSame('second', $geometry->getGeometryAttribution());
    }

    public function testAJobAlreadyInFlightCannotReplaceAGeometryStoredMeanwhile(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();
        $fetch->request($district);
        $job = $this->queue[0];
        $repository = $this->repository();

        self::assertTrue($repository->completeBoundaryFetch($job->districtId, $job->attemptId, (string) json_encode(self::SECOND), 'bkg', null, 'kept', $this->clock->now()));
        self::assertFalse($repository->completeBoundaryFetch($job->districtId, $job->attemptId, (string) json_encode(self::FIRST), 'bkg', null, 'late', $this->clock->now()));

        $repository->failBoundaryFetch($job->districtId, $job->attemptId, DistrictBoundaryFetchFailure::Unavailable);

        $geometry = $this->geometry($district);
        self::assertSame((string) json_encode(self::SECOND), $geometry->getGeometry());
        self::assertSame('kept', $geometry->getGeometryAttribution());
        self::assertSame(DistrictBoundaryStatus::READY, $this->boundaryStatus($district)->state);
        self::assertFalse($fetch->request($district));
    }

    public function testARunningFetchCannotBeStartedTwice(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();

        self::assertTrue($fetch->request($district));
        $this->clock->modify('+15 minutes');
        self::assertFalse($fetch->request($district));
        self::assertCount(1, $this->queue);
    }

    public function testASuccessfulFetchStoresProvenanceAndFillsAnEmptyKind(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();
        $fetch->request($district);
        $this->outcomes = [$this->found(self::FIRST, '© BKG (2026) dl-de/by-2-0')];

        $fetch->run($this->queue[0]->districtId, $this->queue[0]->attemptId);

        $geometry = $this->geometry($district);
        self::assertSame('bkg', $geometry->getGeometryProvider());
        self::assertSame('dl-de/by-2-0', $geometry->getGeometryLicence());
        self::assertEquals(new \DateTimeImmutable('2026-11-23 12:00:00'), $geometry->getGeometryFetchedAt());
        self::assertSame('Landkreis', $this->reloaded($district)->getKind());
        self::assertSame('Test district', $this->reloaded($district)->getName());
    }

    public function testAFailedFetchCanBeRetried(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();
        $fetch->request($district);
        $this->outcomes = [DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::Unavailable)];
        $fetch->run($this->queue[0]->districtId, $this->queue[0]->attemptId);

        self::assertSame(DistrictBoundaryFetchFailure::Unavailable, $this->boundaryStatus($district)->failure);
        self::assertTrue($fetch->request($district));

        $this->outcomes = [$this->found(self::FIRST, 'retry')];
        $fetch->run($this->queue[1]->districtId, $this->queue[1]->attemptId);
        self::assertSame(DistrictBoundaryStatus::READY, $this->boundaryStatus($district)->state);
    }

    public function testAnUnusableBoundaryIsNotStored(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();
        $fetch->request($district);
        $this->outcomes = [$this->found(['type' => 'Polygon', 'coordinates' => [[[50.0, 8.0], [50.0, 8.1], [50.1, 8.1], [50.0, 8.0]]]], 'swapped')];

        $fetch->run($this->queue[0]->districtId, $this->queue[0]->attemptId);

        self::assertNull($this->geometry($district)->getGeometry());
        self::assertSame(DistrictBoundaryFetchFailure::InvalidGeometry, $this->boundaryStatus($district)->failure);
    }

    public function testAFailedDispatchIsVisibleAndCanBeRetried(): void
    {
        $district = $this->district();
        $fetch = $this->fetch();
        $this->queueFails = true;

        self::assertFalse($fetch->request($district));
        self::assertSame(DistrictBoundaryFetchFailure::DispatchFailed, $this->boundaryStatus($district)->failure);

        $this->queueFails = false;
        self::assertTrue($fetch->request($district));
    }

    public function testAJobForADeletedDistrictDoesNothing(): void
    {
        $this->fetch()->run(999999, 'd290f1ee-6c54-4b01-90e6-d701748f0851');

        self::assertSame(0, $this->calls);
    }

    private function district(): District
    {
        $district = new District('06411', 'Test district');
        $this->em()->persist($district);
        $this->em()->flush();

        return $district;
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private function found(array $geometry, string $attribution): DistrictBoundaryOutcome
    {
        return DistrictBoundaryOutcome::found($geometry, 'Landkreis', 'bkg', 'dl-de/by-2-0', $attribution);
    }

    private function geometry(District $district): DistrictGeometry
    {
        $this->em()->clear();
        $geometry = $this->em()->find(DistrictGeometry::class, $district->getId());
        self::assertInstanceOf(DistrictGeometry::class, $geometry);

        return $geometry;
    }

    private function reloaded(District $district): District
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(District::class, $district->getId());
        self::assertInstanceOf(District::class, $reloaded);

        return $reloaded;
    }

    private function boundaryStatus(District $district): DistrictBoundaryStatus
    {
        return $this->repository()->boundaryStatus((int) $district->getId(), $this->clock->now());
    }

    private function fetch(): DistrictBoundaryFetch
    {
        $test = $this;
        $client = new readonly class($test) implements DistrictBoundaryClientInterface {
            public function __construct(private DistrictBoundaryFetchTest $test)
            {
            }

            #[\Override]
            public function fetch(string $ags): DistrictBoundaryOutcome
            {
                return $this->test->nextOutcome();
            }
        };
        $bus = new readonly class($test) implements MessageBusInterface {
            public function __construct(private DistrictBoundaryFetchTest $test)
            {
            }

            #[\Override]
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->test->enqueue($message);

                return new Envelope($message);
            }
        };

        return new DistrictBoundaryFetch($this->repository(), $client, $bus, $this->em(), $this->clock, new NullLogger());
    }

    public function nextOutcome(): DistrictBoundaryOutcome
    {
        ++$this->calls;
        if ($this->duringFetch instanceof \Closure) {
            ($this->duringFetch)();

            return $this->lateOutcome ?? DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::Unavailable);
        }

        return array_shift($this->outcomes) ?? DistrictBoundaryOutcome::failed(DistrictBoundaryFetchFailure::Unavailable);
    }

    public function enqueue(object $message): void
    {
        if ($this->queueFails) {
            throw new TransportException('queue down');
        }

        self::assertInstanceOf(FetchDistrictBoundaryMessage::class, $message);
        $this->queue[] = $message;
    }

    private function repository(): DistrictRepository
    {
        return self::getContainer()->get(DistrictRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
