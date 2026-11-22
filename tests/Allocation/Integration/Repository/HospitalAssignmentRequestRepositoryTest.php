<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Database rules of hospital assignment requests (#975): one pending request per participant and hospital, and
 * no two decisions on the same request.
 */
#[ResetDatabase]
final class HospitalAssignmentRequestRepositoryTest extends KernelTestCase
{
    use Factories;

    public function testASecondPendingRequestForTheSameHospitalIsRefused(): void
    {
        self::bootKernel();
        [$user, $hospital] = $this->participantAndHospital();
        $this->store($this->request($user, $hospital));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->store($this->request($user, $hospital));
    }

    public function testADecidedRequestAllowsANewOne(): void
    {
        self::bootKernel();
        [$user, $hospital] = $this->participantAndHospital();
        $first = $this->request($user, $hospital);
        $this->store($first);
        $first->reject(UserFactory::new()->asAdmin()->create(), null, new \DateTimeImmutable('2026-11-22'));
        $this->entityManager()->flush();

        $this->store($this->request($user, $hospital));

        $repository = self::getContainer()->get(HospitalAssignmentRequestRepository::class);
        self::assertCount(2, $repository->findForRequester($user));
        self::assertTrue($repository->hasPendingForRequesterAndHospital($user, $hospital));
        self::assertSame(1, $repository->countPending());
        self::assertSame($first, $repository->findOneByPublicId($first->getPublicIdString()));
        self::assertNull($repository->findOneByPublicId('not-a-uuid'));
    }

    public function testTheCurrentRequestIsTheNewestPendingOneOtherwiseTheNewest(): void
    {
        self::bootKernel();
        $user = UserFactory::new()->asParticipant()->create();
        $repository = self::getContainer()->get(HospitalAssignmentRequestRepository::class);
        self::assertNull($repository->findCurrentForRequester($user));

        $olderPending = $this->requestAt($user, HospitalFactory::createOne(), '2026-11-10');
        $newerPending = $this->requestAt($user, HospitalFactory::createOne(), '2026-11-12');
        $newestRejected = $this->requestAt($user, HospitalFactory::createOne(), '2026-11-14');
        $newestRejected->reject(UserFactory::new()->asAdmin()->create(), null, new \DateTimeImmutable('2026-11-15'));
        $this->entityManager()->flush();
        $this->requestAt(UserFactory::new()->asParticipant()->create(), HospitalFactory::createOne(), '2026-11-20');

        self::assertSame($newerPending, $repository->findCurrentForRequester($user));

        $newerPending->withdraw(new \DateTimeImmutable('2026-11-16'));
        $this->entityManager()->flush();
        self::assertSame($olderPending, $repository->findCurrentForRequester($user));

        $olderPending->withdraw(new \DateTimeImmutable('2026-11-17'));
        $this->entityManager()->flush();
        self::assertSame($newestRejected, $repository->findCurrentForRequester($user));
    }

    public function testADecisionOnAnOutdatedVersionIsRefused(): void
    {
        self::bootKernel();
        [$user, $hospital] = $this->participantAndHospital();
        $request = $this->request($user, $hospital);
        $this->store($request);

        $this->entityManager()->lock($request, LockMode::OPTIMISTIC, 1);
        $request->reject(UserFactory::new()->asAdmin()->create(), null, new \DateTimeImmutable('2026-11-22'));
        $this->entityManager()->flush();

        $this->expectException(OptimisticLockException::class);
        $this->entityManager()->lock($request, LockMode::OPTIMISTIC, 1);
    }

    /**
     * @return array{User, Hospital}
     */
    private function participantAndHospital(): array
    {
        return [UserFactory::new()->asParticipant()->create(), HospitalFactory::createOne()];
    }

    private function request(User $user, Hospital $hospital): HospitalAssignmentRequest
    {
        return new HospitalAssignmentRequest($user, $hospital, null, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable('2026-11-21'));
    }

    private function requestAt(User $user, Hospital $hospital, string $createdAt): HospitalAssignmentRequest
    {
        $request = new HospitalAssignmentRequest($user, $hospital, null, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable($createdAt));
        $this->store($request);

        return $request;
    }

    private function store(HospitalAssignmentRequest $request): void
    {
        $this->entityManager()->persist($request);
        $this->entityManager()->flush();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
