<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Hospital;

use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestDecider;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestRefused;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentOutcome;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Domain\Enum\PreviousOwnerAccess;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Decisions on hospital assignment requests (#975). Fixture: the requester asks for a hospital whose owner is
 * "previous owner"; permission masks are VIEW 1, STATISTICS 2, IMPORT 4, EXPORT 8, BENCHMARKING 16 (all = 31).
 */
#[ResetDatabase]
final class HospitalAssignmentRequestDeciderTest extends KernelTestCase
{
    use Factories;

    private User $requester;
    private User $previousOwner;
    private User $reviewer;
    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->requester = UserFactory::new()->asParticipant()->create();
        $this->previousOwner = UserFactory::new()->asParticipant()->create();
        $this->reviewer = UserFactory::new()->asAdmin()->create();
        $this->hospital = HospitalFactory::createOne(['owner' => $this->previousOwner]);
    }

    public function testAnAccessGrantApprovalCreatesTheGrantWithThePermissions(): void
    {
        $request = $this->request();

        $this->decider()->approveAsAccessGrant($request, $this->reviewer, 7, 'Welcome', 1);

        $grant = $this->grantOf($this->requester);
        self::assertInstanceOf(HospitalAccessGrant::class, $grant);
        self::assertSame(7, $grant->getPermissions());
        $request = $this->reload($request);
        self::assertSame(HospitalAssignmentRequestStatus::Approved, $request->getStatus());
        self::assertSame(HospitalAssignmentOutcome::AccessGrant, $request->getOutcome());
        self::assertSame(7, $request->getGrantedPermissions());
        self::assertSame($this->reviewer->getId(), $request->getDecidedBy()?->getId());
        self::assertSame($this->previousOwner->getId(), $this->ownerId(), 'a grant leaves the owner alone');
    }

    public function testAnApprovalWithImportStoresTheUploadAuthorityOfTheRequest(): void
    {
        $request = $this->request(uploadAuthority: true);

        $this->decider()->approveAsAccessGrant($request, $this->reviewer, 5, null, 1);

        $authority = $this->uploadAuthorityOf($this->requester);
        self::assertInstanceOf(TermsAcceptance::class, $authority);
        self::assertSame($this->hospital->getId(), $authority->getHospital()?->getId());
        self::assertSame('2026-11', $authority->getTermsVersion(), 'the version of the request');
        self::assertSame('2026-11-21 00:00', $authority->getAcceptedAt()->format('Y-m-d H:i'), 'the time of the request');
    }

    public function testAnApprovalWithoutImportStoresNoUploadAuthority(): void
    {
        $this->decider()->approveAsAccessGrant($this->request(uploadAuthority: true), $this->reviewer, 3, null, 1);

        self::assertNull($this->uploadAuthorityOf($this->requester));
    }

    public function testAnApprovalOfARequestWithoutConfirmationStoresNoUploadAuthority(): void
    {
        $this->decider()->approveAsAccessGrant($this->request(), $this->reviewer, 7, null, 1);

        self::assertNull($this->uploadAuthorityOf($this->requester));
    }

    public function testAnOwnershipApprovalStoresTheUploadAuthorityOfTheRequest(): void
    {
        $request = $this->request(uploadAuthority: true);

        $this->decider()->approveAsOwnership($request, $this->reviewer, $this->previousOwner->getId(), PreviousOwnerAccess::RemoveAccess, null, 1);

        self::assertSame($this->hospital->getId(), $this->uploadAuthorityOf($this->requester)?->getHospital()?->getId());
    }

    public function testPermissionsAreCompletedWithTheirRequirements(): void
    {
        $request = $this->request();

        $this->decider()->approveAsAccessGrant($request, $this->reviewer, 16, null, 1);

        self::assertSame(19, $this->grantOf($this->requester)?->getPermissions(), 'BENCHMARKING needs VIEW and STATISTICS: 16 + 2 + 1');
    }

    public function testAGrantWithoutViewIsRefused(): void
    {
        $this->expectRefusal(HospitalAssignmentRequestRefused::INVALID_PERMISSIONS, fn () => $this->decider()->approveAsAccessGrant($this->request(), $this->reviewer, 0, null, 1));
    }

    public function testARequesterWhoGotAccessMeanwhileIsRefusedAndTheRequestStaysPending(): void
    {
        $request = $this->request();
        HospitalAccessGrantFactory::createOne(['user' => $this->requester, 'hospital' => $this->hospital, 'permissions' => 1]);

        $this->expectRefusal(HospitalAssignmentRequestRefused::ALREADY_ASSIGNED, fn () => $this->decider()->approveAsAccessGrant($request, $this->reviewer, 7, null, 1));

        self::assertTrue($this->reload($request)->isPending());
        self::assertSame(1, $this->grantOf($this->requester)?->getPermissions());
    }

    public function testATransferThatKeepsThePreviousOwnerGivesThemAFullGrant(): void
    {
        $request = $this->request();
        HospitalAccessGrantFactory::createOne(['user' => $this->requester, 'hospital' => $this->hospital, 'permissions' => 1]);

        $this->decider()->approveAsOwnership($request, $this->reviewer, $this->previousOwner->getId(), PreviousOwnerAccess::KeepFullAccess, null, 1);

        self::assertSame($this->requester->getId(), $this->ownerId());
        self::assertSame(31, $this->grantOf($this->previousOwner)?->getPermissions());
        self::assertNull($this->grantOf($this->requester), 'the new owner needs no grant besides the ownership');
        $request = $this->reload($request);
        self::assertSame(HospitalAssignmentOutcome::Ownership, $request->getOutcome());
        self::assertSame($this->previousOwner->getId(), $request->getPreviousOwner()?->getId());
        self::assertSame(PreviousOwnerAccess::KeepFullAccess, $request->getPreviousOwnerAccess());
    }

    public function testATransferThatRemovesThePreviousOwnerLeavesThemWithoutAccess(): void
    {
        $request = $this->request();
        HospitalAccessGrantFactory::createOne(['user' => $this->previousOwner, 'hospital' => $this->hospital, 'permissions' => 3]);

        $this->decider()->approveAsOwnership($request, $this->reviewer, $this->previousOwner->getId(), PreviousOwnerAccess::RemoveAccess, null, 1);

        self::assertSame($this->requester->getId(), $this->ownerId());
        self::assertNull($this->grantOf($this->previousOwner));
    }

    public function testAHospitalWithoutOwnerGetsTheRequesterAsOwner(): void
    {
        $hospital = HospitalFactory::createOne(['owner' => null]);
        $request = $this->request($hospital);

        $this->decider()->approveAsOwnership($request, $this->reviewer, null, null, null, 1);

        self::assertSame($this->requester->getId(), $this->reload($request)->getHospital()?->getOwner()?->getId());
        self::assertNull($this->reload($request)->getPreviousOwner());
    }

    public function testATransferNeedsAChoiceForThePreviousOwner(): void
    {
        $request = $this->request();

        $this->expectRefusal(HospitalAssignmentRequestRefused::PREVIOUS_OWNER_CHOICE_REQUIRED, fn () => $this->decider()->approveAsOwnership($request, $this->reviewer, $this->previousOwner->getId(), null, null, 1));

        self::assertSame($this->previousOwner->getId(), $this->ownerId());
    }

    public function testAnOwnerThatChangedSinceTheReviewerLookedIsRefused(): void
    {
        $request = $this->request();
        $other = UserFactory::createOne();

        $this->expectRefusal(HospitalAssignmentRequestRefused::OWNER_CHANGED, fn () => $this->decider()->approveAsOwnership($request, $this->reviewer, $other->getId(), PreviousOwnerAccess::RemoveAccess, null, 1));

        self::assertSame($this->previousOwner->getId(), $this->ownerId());
        self::assertTrue($this->reload($request)->isPending());
    }

    public function testADecisionOnAnOutdatedVersionIsRefused(): void
    {
        $request = $this->request();

        $this->expectRefusal(HospitalAssignmentRequestRefused::CHANGED_MEANWHILE, fn () => $this->decider()->approveAsAccessGrant($request, $this->reviewer, 7, null, 99));

        self::assertNull($this->grantOf($this->requester));
    }

    public function testADecidedRequestCannotBeDecidedAgain(): void
    {
        $request = $this->request();
        $this->decider()->reject($request, $this->reviewer, 'No', 1);
        $request = $this->reload($request);

        $this->expectRefusal(HospitalAssignmentRequestRefused::NOT_PENDING, fn () => $this->decider()->approveAsAccessGrant($request, $this->reviewer, 7, null, $request->getVersion()));
    }

    public function testARejectionKeepsTheMessage(): void
    {
        $request = $this->request();

        $this->decider()->reject($request, $this->reviewer, 'Please ask your ED lead.', 1);

        $request = $this->reload($request);
        self::assertSame(HospitalAssignmentRequestStatus::Rejected, $request->getStatus());
        self::assertSame('Please ask your ED lead.', $request->getDecisionMessage());
        self::assertNull($this->grantOf($this->requester));
    }

    public function testARequestByNameIsMatchedBeforeItIsApproved(): void
    {
        $request = new HospitalAssignmentRequest($this->requester, null, 'Klinikum Nord', HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable('2026-11-21'));
        $this->store($request);

        $this->expectRefusal(HospitalAssignmentRequestRefused::HOSPITAL_REQUIRED, fn () => $this->decider()->approveAsAccessGrant($request, $this->reviewer, 7, null, 1));

        $request = $this->reload($request);
        $this->decider()->resolveHospital($request, $this->managed($this->hospital), $request->getVersion());
        $request = $this->reload($request);
        $reviewer = $this->entityManager()->find(User::class, $this->reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        $this->decider()->approveAsAccessGrant($request, $reviewer, 7, null, $request->getVersion());

        self::assertSame(7, $this->grantOf($this->requester)?->getPermissions());
        self::assertSame('Klinikum Nord', $this->reload($request)->getRequestedHospitalName());
    }

    private function request(?Hospital $hospital = null, bool $uploadAuthority = false): HospitalAssignmentRequest
    {
        $request = new HospitalAssignmentRequest($this->requester, $hospital ?? $this->hospital, null, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable('2026-11-21'));
        if ($uploadAuthority) {
            $request->confirmUploadAuthority($request->getCreatedAt(), '2026-11');
        }
        $this->store($request);

        return $request;
    }

    private function store(HospitalAssignmentRequest $request): void
    {
        $this->entityManager()->persist($request);
        $this->entityManager()->flush();
    }

    /**
     * @param callable(): void $decision
     */
    private function expectRefusal(string $reason, callable $decision): void
    {
        try {
            $decision();
            self::fail('The decision must be refused.');
        } catch (HospitalAssignmentRequestRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }

        // A refused decision rolls back and closes the entity manager; later assertions read with a new one.
        self::getContainer()->get(ManagerRegistry::class)->resetManager();
    }

    private function grantOf(User $user): ?HospitalAccessGrant
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(HospitalAccessGrant::class)->findOneBy(['user' => $user->getId(), 'hospital' => $this->hospital->getId()]);
    }

    private function uploadAuthorityOf(User $user): ?TermsAcceptance
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(TermsAcceptance::class)->findOneBy(['user' => $user->getId(), 'kind' => TermsAcceptanceKind::UploadAuthority]);
    }

    private function ownerId(): ?int
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(Hospital::class, $this->hospital->getId())?->getOwner()?->getId();
    }

    private function reload(HospitalAssignmentRequest $request): HospitalAssignmentRequest
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(HospitalAssignmentRequest::class, $request->getId());
        self::assertInstanceOf(HospitalAssignmentRequest::class, $reloaded);

        return $reloaded;
    }

    private function managed(Hospital $hospital): Hospital
    {
        $managed = $this->entityManager()->find(Hospital::class, $hospital->getId());
        self::assertInstanceOf(Hospital::class, $managed);

        return $managed;
    }

    private function decider(): HospitalAssignmentRequestDecider
    {
        return self::getContainer()->get(HospitalAssignmentRequestDecider::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
