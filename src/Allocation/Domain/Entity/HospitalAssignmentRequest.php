<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Domain\Enum\HospitalAssignmentOutcome;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Domain\Enum\PreviousOwnerAccess;
use App\Allocation\Domain\Exception\HospitalAssignmentRequestException;
use App\Shared\Domain\Traits\HasRequiredPublicId;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A participant asks to be assigned to a hospital (#975). Administrators approve it as an access grant or as
 * ownership, or reject it; the requester can withdraw it while it is pending. The request records the decision, so
 * it stays clear who granted access, when, and on which request.
 *
 * A request names an existing hospital or, when the participant could not find it, a hospital name. A named
 * hospital must be resolved to an existing one before approval; no hospital is created from a request.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'hospital_assignment_request')]
#[ORM\Index(name: 'idx_hospital_assignment_request_status', columns: ['status', 'created_at'])]
// One pending request per participant and hospital; decided requests stay as history.
#[ORM\UniqueConstraint(name: 'uniq_hospital_assignment_request_pending', columns: ['requester_id', 'hospital_id'], options: ['where' => "((status)::text = 'pending'::text)"])]
#[ORM\HasLifecycleCallbacks]
class HospitalAssignmentRequest
{
    use HasRequiredPublicId;

    public const int MAX_NAME_LENGTH = 255;
    public const int MAX_TEXT_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Guards against two administrators deciding the same request at the same time. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $requester;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Hospital $hospital;

    /** Name the participant entered for a hospital they could not find; kept after the reviewer resolved it. */
    #[ORM\Column(length: self::MAX_NAME_LENGTH, nullable: true)]
    private ?string $requestedHospitalName;

    #[ORM\Column(length: 32, enumType: HospitalAssignmentReason::class)]
    private HospitalAssignmentReason $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $explanation;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column(length: 16, enumType: HospitalAssignmentRequestStatus::class)]
    private HospitalAssignmentRequestStatus $status = HospitalAssignmentRequestStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the request was approved, rejected or withdrawn. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    /** Message of the reviewer to the requester, shown with a rejection. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionMessage = null;

    #[ORM\Column(length: 16, nullable: true, enumType: HospitalAssignmentOutcome::class)]
    private ?HospitalAssignmentOutcome $outcome = null;

    /** Permission mask of the access grant an approval created. */
    #[ORM\Column(nullable: true)]
    private ?int $grantedPermissions = null;

    /** Owner the hospital had before an approval transferred its ownership. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $previousOwner = null;

    #[ORM\Column(length: 32, nullable: true, enumType: PreviousOwnerAccess::class)]
    private ?PreviousOwnerAccess $previousOwnerAccess = null;

    /** When the requester confirmed the authority to upload for this hospital (#1565); null without. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $uploadAuthorityConfirmedAt = null;

    /** Terms version in force at that confirmation; null while none was configured. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $uploadAuthorityTermsVersion = null;

    /**
     * @throws HospitalAssignmentRequestException when neither a hospital nor a name is given, or a reason that needs
     *                                            an explanation has none
     */
    public function __construct(
        User $requester,
        ?Hospital $hospital,
        ?string $requestedHospitalName,
        HospitalAssignmentReason $reason,
        ?string $explanation,
        ?string $comment,
        \DateTimeImmutable $createdAt,
    ) {
        $requestedHospitalName = $this->clean($requestedHospitalName);
        if (!$hospital instanceof Hospital && null === $requestedHospitalName) {
            throw new HospitalAssignmentRequestException('A request needs a hospital or the name of one.');
        }

        $explanation = $this->clean($explanation);
        if ($reason->requiresExplanation() && null === $explanation) {
            throw new HospitalAssignmentRequestException('This reason needs an explanation.');
        }

        $this->requester = $requester;
        $this->hospital = $hospital;
        $this->requestedHospitalName = $hospital instanceof Hospital ? null : $requestedHospitalName;
        $this->reason = $reason;
        $this->explanation = $explanation;
        $this->comment = $this->clean($comment);
        $this->createdAt = $createdAt;
    }

    /**
     * The requester confirms being authorised to upload data for the requested hospital (#1565). An approval with the
     * import permission stores it as the upload authority, with this time and version.
     */
    public function confirmUploadAuthority(\DateTimeImmutable $at, ?string $termsVersion): void
    {
        $this->uploadAuthorityConfirmedAt = $at;
        $this->uploadAuthorityTermsVersion = $termsVersion;
    }

    public function getUploadAuthorityConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->uploadAuthorityConfirmedAt;
    }

    public function getUploadAuthorityTermsVersion(): ?string
    {
        return $this->uploadAuthorityTermsVersion;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Version the reviewer saw; a decision on another version is refused (optimistic lock). */
    public function getVersion(): int
    {
        return $this->version;
    }

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function getHospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function getRequestedHospitalName(): ?string
    {
        return $this->requestedHospitalName;
    }

    public function getReason(): HospitalAssignmentReason
    {
        return $this->reason;
    }

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getStatus(): HospitalAssignmentRequestStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return HospitalAssignmentRequestStatus::Pending === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecisionMessage(): ?string
    {
        return $this->decisionMessage;
    }

    public function getOutcome(): ?HospitalAssignmentOutcome
    {
        return $this->outcome;
    }

    public function getGrantedPermissions(): ?int
    {
        return $this->grantedPermissions;
    }

    public function getPreviousOwner(): ?User
    {
        return $this->previousOwner;
    }

    public function getPreviousOwnerAccess(): ?PreviousOwnerAccess
    {
        return $this->previousOwnerAccess;
    }

    /** The request names a hospital that still has to be matched to an existing one. */
    public function needsHospitalResolution(): bool
    {
        return !$this->hospital instanceof Hospital;
    }

    /**
     * Matches a request for a named hospital to an existing hospital. The entered name is kept.
     *
     * @throws HospitalAssignmentRequestException when the request is no longer pending
     */
    public function resolveHospital(Hospital $hospital): void
    {
        $this->assertPending();
        $this->hospital = $hospital;
    }

    /**
     * @throws HospitalAssignmentRequestException when the request is no longer pending
     */
    public function withdraw(\DateTimeImmutable $at): void
    {
        $this->assertPending();
        $this->status = HospitalAssignmentRequestStatus::Withdrawn;
        $this->closedAt = $at;
    }

    /**
     * @throws HospitalAssignmentRequestException when the request is no longer pending
     */
    public function reject(User $reviewer, ?string $message, \DateTimeImmutable $at): void
    {
        $this->assertPending();
        $this->close(HospitalAssignmentRequestStatus::Rejected, $reviewer, $message, $at);
    }

    /**
     * Records an approval that created an access grant with $permissions.
     *
     * @throws HospitalAssignmentRequestException when the request is not pending or has no hospital yet
     */
    public function approveAsAccessGrant(User $reviewer, int $permissions, ?string $message, \DateTimeImmutable $at): void
    {
        $this->assertApprovable();
        $this->outcome = HospitalAssignmentOutcome::AccessGrant;
        $this->grantedPermissions = $permissions;
        $this->close(HospitalAssignmentRequestStatus::Approved, $reviewer, $message, $at);
    }

    /**
     * Records an approval that made the requester owner of the hospital, with the previous owner (if any) and what
     * the reviewer chose for their access.
     *
     * @throws HospitalAssignmentRequestException when the request is not pending or has no hospital yet, or when a
     *                                            previous owner is given without a choice for their access
     */
    public function approveAsOwnership(User $reviewer, ?User $previousOwner, ?PreviousOwnerAccess $previousOwnerAccess, ?string $message, \DateTimeImmutable $at): void
    {
        $this->assertApprovable();
        if ($previousOwner instanceof User && !$previousOwnerAccess instanceof PreviousOwnerAccess) {
            throw new HospitalAssignmentRequestException('A transfer needs a decision about the access of the previous owner.');
        }

        $this->outcome = HospitalAssignmentOutcome::Ownership;
        $this->previousOwner = $previousOwner;
        $this->previousOwnerAccess = $previousOwner instanceof User ? $previousOwnerAccess : null;
        $this->close(HospitalAssignmentRequestStatus::Approved, $reviewer, $message, $at);
    }

    private function close(HospitalAssignmentRequestStatus $status, User $reviewer, ?string $message, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->decidedBy = $reviewer;
        $this->decisionMessage = $this->clean($message);
        $this->closedAt = $at;
    }

    private function assertPending(): void
    {
        if (!$this->isPending()) {
            throw new HospitalAssignmentRequestException(sprintf('The request is %s, not pending.', $this->status->value));
        }
    }

    private function assertApprovable(): void
    {
        $this->assertPending();
        if (!$this->hospital instanceof Hospital) {
            throw new HospitalAssignmentRequestException('Match the request to an existing hospital before approving it.');
        }
    }

    private function clean(?string $text): ?string
    {
        $text = null === $text ? '' : trim($text);

        return '' === $text ? null : $text;
    }
}
