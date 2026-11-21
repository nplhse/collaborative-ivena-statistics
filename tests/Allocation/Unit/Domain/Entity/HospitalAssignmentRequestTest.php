<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentOutcome;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Domain\Enum\PreviousOwnerAccess;
use App\Allocation\Domain\Exception\HospitalAssignmentRequestException;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class HospitalAssignmentRequestTest extends TestCase
{
    private const string SUBMITTED = '2026-11-21 10:00';
    private const string DECIDED = '2026-11-22 09:30';

    public function testANewRequestIsPendingAndKeepsWhatWasEntered(): void
    {
        $requester = new User();
        $hospital = new Hospital();

        $request = new HospitalAssignmentRequest($requester, $hospital, 'ignored name', HospitalAssignmentReason::DataSubmission, null, '  We submit the monthly exports.  ', new \DateTimeImmutable(self::SUBMITTED));

        self::assertSame(HospitalAssignmentRequestStatus::Pending, $request->getStatus());
        self::assertTrue($request->isPending());
        self::assertSame($requester, $request->getRequester());
        self::assertSame($hospital, $request->getHospital());
        self::assertNull($request->getRequestedHospitalName(), 'a chosen hospital needs no entered name');
        self::assertSame('We submit the monthly exports.', $request->getComment());
        self::assertFalse($request->needsHospitalResolution());
        self::assertNull($request->getClosedAt());
    }

    public function testAHospitalThatWasNotFoundIsRequestedByName(): void
    {
        $request = new HospitalAssignmentRequest(new User(), null, ' Klinikum Nord ', HospitalAssignmentReason::HospitalManagement, null, null, new \DateTimeImmutable(self::SUBMITTED));

        self::assertSame('Klinikum Nord', $request->getRequestedHospitalName());
        self::assertTrue($request->needsHospitalResolution());
    }

    public function testARequestNeedsAHospitalOrAName(): void
    {
        $this->expectException(HospitalAssignmentRequestException::class);

        new HospitalAssignmentRequest(new User(), null, '   ', HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable(self::SUBMITTED));
    }

    public function testTheReasonOtherNeedsAnExplanation(): void
    {
        $this->expectException(HospitalAssignmentRequestException::class);

        new HospitalAssignmentRequest(new User(), new Hospital(), null, HospitalAssignmentReason::Other, ' ', null, new \DateTimeImmutable(self::SUBMITTED));
    }

    public function testAWithdrawnRequestIsClosedWithoutDecision(): void
    {
        $request = $this->request();

        $request->withdraw(new \DateTimeImmutable(self::DECIDED));

        self::assertSame(HospitalAssignmentRequestStatus::Withdrawn, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable(self::DECIDED), $request->getClosedAt());
        self::assertNull($request->getDecidedBy());
    }

    public function testARejectionKeepsTheReviewerAndTheMessage(): void
    {
        $request = $this->request();
        $reviewer = new User();

        $request->reject($reviewer, 'Please ask your ED lead to confirm.', new \DateTimeImmutable(self::DECIDED));

        self::assertSame(HospitalAssignmentRequestStatus::Rejected, $request->getStatus());
        self::assertSame($reviewer, $request->getDecidedBy());
        self::assertSame('Please ask your ED lead to confirm.', $request->getDecisionMessage());
        self::assertNull($request->getOutcome());
    }

    public function testAnApprovalAsAccessGrantKeepsThePermissions(): void
    {
        $request = $this->request();

        $request->approveAsAccessGrant(new User(), 7, null, new \DateTimeImmutable(self::DECIDED));

        self::assertSame(HospitalAssignmentRequestStatus::Approved, $request->getStatus());
        self::assertSame(HospitalAssignmentOutcome::AccessGrant, $request->getOutcome());
        self::assertSame(7, $request->getGrantedPermissions());
        self::assertNull($request->getPreviousOwner());
    }

    public function testATransferKeepsThePreviousOwnerAndTheChoiceForTheirAccess(): void
    {
        $request = $this->request();
        $previousOwner = new User();

        $request->approveAsOwnership(new User(), $previousOwner, PreviousOwnerAccess::KeepFullAccess, null, new \DateTimeImmutable(self::DECIDED));

        self::assertSame(HospitalAssignmentOutcome::Ownership, $request->getOutcome());
        self::assertSame($previousOwner, $request->getPreviousOwner());
        self::assertSame(PreviousOwnerAccess::KeepFullAccess, $request->getPreviousOwnerAccess());
        self::assertNull($request->getGrantedPermissions());
    }

    public function testATransferNeedsAChoiceForThePreviousOwner(): void
    {
        $this->expectException(HospitalAssignmentRequestException::class);

        $this->request()->approveAsOwnership(new User(), new User(), null, null, new \DateTimeImmutable(self::DECIDED));
    }

    public function testOwnershipOfAHospitalWithoutOwnerNeedsNoChoice(): void
    {
        $request = $this->request();

        $request->approveAsOwnership(new User(), null, PreviousOwnerAccess::RemoveAccess, null, new \DateTimeImmutable(self::DECIDED));

        self::assertNull($request->getPreviousOwner());
        self::assertNull($request->getPreviousOwnerAccess(), 'without a previous owner there is nothing to decide');
    }

    public function testARequestByNameMustBeMatchedBeforeApproval(): void
    {
        $request = new HospitalAssignmentRequest(new User(), null, 'Klinikum Nord', HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable(self::SUBMITTED));

        try {
            $request->approveAsAccessGrant(new User(), 7, null, new \DateTimeImmutable(self::DECIDED));
            self::fail('An unmatched request must not be approved.');
        } catch (HospitalAssignmentRequestException) {
        }

        $hospital = new Hospital();
        $request->resolveHospital($hospital);
        $request->approveAsAccessGrant(new User(), 7, null, new \DateTimeImmutable(self::DECIDED));

        self::assertSame($hospital, $request->getHospital());
        self::assertSame('Klinikum Nord', $request->getRequestedHospitalName(), 'the entered name stays for the history');
    }

    public function testADecidedRequestCannotBeDecidedOrWithdrawnAgain(): void
    {
        $request = $this->request();
        $request->reject(new User(), null, new \DateTimeImmutable(self::DECIDED));

        foreach ([
            fn () => $request->withdraw(new \DateTimeImmutable(self::DECIDED)),
            fn () => $request->approveAsAccessGrant(new User(), 1, null, new \DateTimeImmutable(self::DECIDED)),
            fn () => $request->reject(new User(), null, new \DateTimeImmutable(self::DECIDED)),
            fn () => $request->resolveHospital(new Hospital()),
        ] as $step) {
            try {
                $step();
                self::fail('A closed request must refuse every step.');
            } catch (HospitalAssignmentRequestException) {
            }
        }

        self::assertSame(HospitalAssignmentRequestStatus::Rejected, $request->getStatus());
    }

    private function request(): HospitalAssignmentRequest
    {
        return new HospitalAssignmentRequest(new User(), new Hospital(), null, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable(self::SUBMITTED));
    }
}
