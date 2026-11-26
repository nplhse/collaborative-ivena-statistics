<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequestVersion;
use App\Research\Domain\ValueObject\DataRequestContent;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the applicant enters in the data extract request form (ADR 045). Every field except the planned project end,
 * deletion date and publication is required; the details of an enrichment are required when one is planned.
 */
final class DataRequestDraft
{
    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_LINE_LENGTH)]
    public ?string $title = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $summary = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_LINE_LENGTH)]
    public ?string $institution = null;

    public bool $enrichmentPlanned = false;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $purpose = null;

    #[Assert\NotNull(message: 'data_request.field.required')]
    public ?\DateTimeImmutable $periodFrom = null;

    #[Assert\NotNull(message: 'data_request.field.required')]
    public ?\DateTimeImmutable $periodTo = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $population = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $variables = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $granularity = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $recipients = null;

    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $enrichmentDetails = null;

    public bool $singleCases = false;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $description = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $dataNeeds = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $responsiblePersons = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $conflictsOfInterest = null;

    #[Assert\NotBlank(message: 'data_request.field.required')]
    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $ethics = null;

    public ?\DateTimeImmutable $plannedEnd = null;

    public ?\DateTimeImmutable $plannedDeletion = null;

    #[Assert\Length(max: DataRequestVersion::MAX_TEXT_LENGTH)]
    public ?string $plannedPublication = null;

    public static function fromVersion(DataRequestVersion $version): self
    {
        $content = $version->toContent();
        $draft = new self();
        foreach (get_object_vars($content) as $field => $value) {
            $draft->{$field} = $value;
        }

        return $draft;
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the validator through Assert\Callback. */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->periodFrom instanceof \DateTimeImmutable && $this->periodTo instanceof \DateTimeImmutable && $this->periodTo < $this->periodFrom) {
            $context->buildViolation('data_request.period.order')
                ->atPath('periodTo')
                ->addViolation();
        }

        if ($this->enrichmentPlanned && '' === trim((string) $this->enrichmentDetails)) {
            $context->buildViolation('data_request.field.required')
                ->atPath('enrichmentDetails')
                ->addViolation();
        }
    }

    /**
     * @throws \LogicException when called on a draft that did not pass validation
     */
    public function toContent(): DataRequestContent
    {
        if (!$this->periodFrom instanceof \DateTimeImmutable || !$this->periodTo instanceof \DateTimeImmutable) {
            throw new \LogicException('A validated draft has a period.');
        }

        return new DataRequestContent(
            title: (string) $this->title,
            summary: (string) $this->summary,
            institution: (string) $this->institution,
            enrichmentPlanned: $this->enrichmentPlanned,
            purpose: (string) $this->purpose,
            periodFrom: $this->periodFrom,
            periodTo: $this->periodTo,
            population: (string) $this->population,
            variables: (string) $this->variables,
            granularity: (string) $this->granularity,
            recipients: (string) $this->recipients,
            enrichmentDetails: $this->enrichmentDetails,
            singleCases: $this->singleCases,
            description: (string) $this->description,
            dataNeeds: (string) $this->dataNeeds,
            responsiblePersons: (string) $this->responsiblePersons,
            conflictsOfInterest: (string) $this->conflictsOfInterest,
            ethics: (string) $this->ethics,
            plannedEnd: $this->plannedEnd,
            plannedDeletion: $this->plannedDeletion,
            plannedPublication: $this->plannedPublication,
        );
    }
}
