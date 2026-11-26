<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One version of a data extract request (ADR 045). The applicant may change a version until it is submitted; after
 * that it is frozen, and every change is a new version. Votes and comments refer to the version they were given for,
 * and the approved version, including its export scope, is the Board's decision.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_version')]
#[ORM\UniqueConstraint(name: 'uniq_data_request_version_number', columns: ['request_id', 'number'])]
class DataRequestVersion
{
    public const int MAX_LINE_LENGTH = 255;
    public const int MAX_TEXT_LENGTH = 10000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequest $request;

    /** 1 for the first version, counting up. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $number;

    #[ORM\Column(length: self::MAX_LINE_LENGTH)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    #[ORM\Column(length: self::MAX_LINE_LENGTH)]
    private string $institution;

    #[ORM\Column]
    private bool $enrichmentPlanned;

    #[ORM\Column(type: Types::TEXT)]
    private string $purpose;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: Types::TEXT)]
    private string $population;

    #[ORM\Column(type: Types::TEXT)]
    private string $variables;

    #[ORM\Column(type: Types::TEXT)]
    private string $granularity;

    #[ORM\Column(type: Types::TEXT)]
    private string $recipients;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $enrichmentDetails;

    #[ORM\Column]
    private bool $singleCases;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::TEXT)]
    private string $dataNeeds;

    #[ORM\Column(type: Types::TEXT)]
    private string $responsiblePersons;

    #[ORM\Column(type: Types::TEXT)]
    private string $conflictsOfInterest;

    #[ORM\Column(type: Types::TEXT)]
    private string $ethics;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedEnd;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedDeletion;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $plannedPublication;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    /** When the applicant submitted this version; it cannot change afterwards. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    public function __construct(DataRequest $request, int $number, DataRequestContent $content, ?User $createdBy, \DateTimeImmutable $at)
    {
        $this->request = $request;
        $this->number = $number;
        $this->createdBy = $createdBy;
        $this->createdAt = $at;
        $this->write($content, $at);
    }

    /**
     * @throws DataRequestException when the version was already submitted
     */
    public function replaceContent(DataRequestContent $content, \DateTimeImmutable $at): void
    {
        if ($this->isSubmitted()) {
            throw new DataRequestException('A submitted version cannot change; create a new version instead.');
        }

        $this->write($content, $at);
    }

    public function markSubmitted(\DateTimeImmutable $at): void
    {
        $this->submittedAt ??= $at;
    }

    public function toContent(): DataRequestContent
    {
        return new DataRequestContent(
            title: $this->title,
            summary: $this->summary,
            institution: $this->institution,
            enrichmentPlanned: $this->enrichmentPlanned,
            purpose: $this->purpose,
            periodFrom: $this->periodFrom,
            periodTo: $this->periodTo,
            population: $this->population,
            variables: $this->variables,
            granularity: $this->granularity,
            recipients: $this->recipients,
            enrichmentDetails: $this->enrichmentDetails,
            singleCases: $this->singleCases,
            description: $this->description,
            dataNeeds: $this->dataNeeds,
            responsiblePersons: $this->responsiblePersons,
            conflictsOfInterest: $this->conflictsOfInterest,
            ethics: $this->ethics,
            plannedEnd: $this->plannedEnd,
            plannedDeletion: $this->plannedDeletion,
            plannedPublication: $this->plannedPublication,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DataRequest
    {
        return $this->request;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getInstitution(): string
    {
        return $this->institution;
    }

    public function isEnrichmentPlanned(): bool
    {
        return $this->enrichmentPlanned;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function getPopulation(): string
    {
        return $this->population;
    }

    public function getVariables(): string
    {
        return $this->variables;
    }

    public function getGranularity(): string
    {
        return $this->granularity;
    }

    public function getRecipients(): string
    {
        return $this->recipients;
    }

    public function getEnrichmentDetails(): ?string
    {
        return $this->enrichmentDetails;
    }

    public function isSingleCases(): bool
    {
        return $this->singleCases;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDataNeeds(): string
    {
        return $this->dataNeeds;
    }

    public function getResponsiblePersons(): string
    {
        return $this->responsiblePersons;
    }

    public function getConflictsOfInterest(): string
    {
        return $this->conflictsOfInterest;
    }

    public function getEthics(): string
    {
        return $this->ethics;
    }

    public function getPlannedEnd(): ?\DateTimeImmutable
    {
        return $this->plannedEnd;
    }

    public function getPlannedDeletion(): ?\DateTimeImmutable
    {
        return $this->plannedDeletion;
    }

    public function getPlannedPublication(): ?string
    {
        return $this->plannedPublication;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function isSubmitted(): bool
    {
        return $this->submittedAt instanceof \DateTimeImmutable;
    }

    private function write(DataRequestContent $content, \DateTimeImmutable $at): void
    {
        if ($content->periodTo < $content->periodFrom) {
            throw new DataRequestException('The period ends before it starts.');
        }

        $this->title = trim($content->title);
        $this->summary = trim($content->summary);
        $this->institution = trim($content->institution);
        $this->enrichmentPlanned = $content->enrichmentPlanned;
        $this->purpose = trim($content->purpose);
        $this->periodFrom = $content->periodFrom;
        $this->periodTo = $content->periodTo;
        $this->population = trim($content->population);
        $this->variables = trim($content->variables);
        $this->granularity = trim($content->granularity);
        $this->recipients = trim($content->recipients);
        $this->enrichmentDetails = $content->enrichmentPlanned ? $this->clean($content->enrichmentDetails) : null;
        $this->singleCases = $content->singleCases;
        $this->description = trim($content->description);
        $this->dataNeeds = trim($content->dataNeeds);
        $this->responsiblePersons = trim($content->responsiblePersons);
        $this->conflictsOfInterest = trim($content->conflictsOfInterest);
        $this->ethics = trim($content->ethics);
        $this->plannedEnd = $content->plannedEnd;
        $this->plannedDeletion = $content->plannedDeletion;
        $this->plannedPublication = $this->clean($content->plannedPublication);
        $this->updatedAt = $at;
    }

    private function clean(?string $text): ?string
    {
        $text = null === $text ? '' : trim($text);

        return '' === $text ? null : $text;
    }
}
