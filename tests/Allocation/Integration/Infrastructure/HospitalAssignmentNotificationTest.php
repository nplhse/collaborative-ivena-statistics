<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Infrastructure;

use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestDecider;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestService;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestSubmission;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\PreviousOwnerAccess;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Notifications of hospital assignment requests (#975): administrators hear of a request, the requester of the
 * decision, and the previous owner of a transfer, each once.
 */
#[ResetDatabase]
final class HospitalAssignmentNotificationTest extends KernelTestCase
{
    use Factories;

    public function testAdministratorsHearOfARequestAndTheLinkOpensTheReview(): void
    {
        self::bootKernel();
        $admin = UserFactory::new()->asAdmin()->create();
        $requester = UserFactory::new()->asParticipant()->create(['username' => 'dr-mueller']);
        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Mitte', 'owner' => UserFactory::createOne()]);

        $submission = new HospitalAssignmentRequestSubmission();
        $submission->hospital = $hospital;
        $submission->reason = HospitalAssignmentReason::EmergencyDepartmentLeadership;
        self::getContainer()->get(HospitalAssignmentRequestService::class)->submit($requester, $submission);

        $rows = $this->notifications('admin.hospital_assignment_requested', $admin);
        self::assertCount(1, $rows);
        self::assertSame(['username' => 'dr-mueller', 'hospitalName' => 'Klinikum Mitte', 'reason' => 'ed_leadership'], json_decode((string) $rows[0]['parameters'], true));
        self::assertSame('app_admin_dashboard_hospital_assignment_request_detail', $rows[0]['action_route']);
        self::assertSame('ROLE_ADMIN', $rows[0]['required_role']);
    }

    public function testATransferNotifiesTheRequesterAndThePreviousOwner(): void
    {
        self::bootKernel();
        $reviewer = UserFactory::new()->asAdmin()->create();
        $requester = UserFactory::new()->asParticipant()->create(['username' => 'dr-mueller']);
        $previousOwner = UserFactory::new()->asParticipant()->create();
        $hospital = HospitalFactory::createOne(['name' => 'Klinikum Mitte', 'owner' => $previousOwner]);
        $request = new HospitalAssignmentRequest($requester, $hospital, null, HospitalAssignmentReason::HospitalManagement, null, null, new \DateTimeImmutable('2026-11-21'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($request);
        $entityManager->flush();

        self::getContainer()->get(HospitalAssignmentRequestDecider::class)->approveAsOwnership($request, $reviewer, $previousOwner->getId(), PreviousOwnerAccess::KeepFullAccess, null, 1);

        $decided = $this->notifications('hospital_assignment.decided', $requester);
        self::assertCount(1, $decided);
        self::assertSame(['hospitalName' => 'Klinikum Mitte', 'decision' => 'approved', 'outcome' => 'ownership'], json_decode((string) $decided[0]['parameters'], true));

        $transferred = $this->notifications('hospital_assignment.ownership_transferred', $previousOwner);
        self::assertCount(1, $transferred);
        self::assertSame(['hospitalName' => 'Klinikum Mitte', 'newOwner' => 'dr-mueller', 'access' => 'keep_full_access'], json_decode((string) $transferred[0]['parameters'], true));
    }

    public function testARejectionNotifiesTheRequesterOnly(): void
    {
        self::bootKernel();
        $reviewer = UserFactory::new()->asAdmin()->create();
        $requester = UserFactory::new()->asParticipant()->create();
        $previousOwner = UserFactory::new()->asParticipant()->create();
        $hospital = HospitalFactory::createOne(['owner' => $previousOwner]);
        $request = new HospitalAssignmentRequest($requester, $hospital, null, HospitalAssignmentReason::DataSubmission, null, null, new \DateTimeImmutable('2026-11-21'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($request);
        $entityManager->flush();

        self::getContainer()->get(HospitalAssignmentRequestDecider::class)->reject($request, $reviewer, 'No', 1);

        $decided = $this->notifications('hospital_assignment.decided', $requester);
        self::assertCount(1, $decided);
        self::assertSame('rejected', json_decode((string) $decided[0]['parameters'], true)['decision'] ?? null);
        self::assertSame('app_hospital_assignment_request_index', $decided[0]['action_route']);
        self::assertSame([], $this->notifications('hospital_assignment.ownership_transferred', $previousOwner));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(string $type, User $recipient): array
    {
        return self::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT parameters, action_route, required_role FROM notification WHERE recipient_id = ? AND type = ?',
            [$recipient->getId(), $type],
        );
    }
}
