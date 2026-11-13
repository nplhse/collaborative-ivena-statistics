<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * One generated ED visit with its truth (#1052).
 *
 * `cells` holds the values of the `ed_visit` v1 fields in their exchange form (exchange-format.md),
 * without the two keys: `visit_fingerprint` and `case_key` follow from the case number and the key of
 * the hospital when a file or a row is written. A missing field is an empty cell.
 */
final readonly class SyntheticVisit
{
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i';

    /**
     * @param string                $caseNumber     synthetic administrative case number, digits only
     * @param array<string, string> $cells          exchange values by field name
     * @param int|null              $allocationId   the allocation this visit truly belongs to; null for every group but Linked
     * @param bool                  $timeOutlier    ED admission outside the pass-1 window (−20 … +45 min) of the allocation
     * @param int                   $ageDifference  visit age minus IVENA age (0 = exact)
     * @param bool                  $lateAssessment first assessment contact documented only after the first export that contains the visit
     */
    public function __construct(
        public string $caseNumber,
        public SyntheticVisitKind $kind,
        public array $cells,
        public ?int $allocationId = null,
        public bool $timeOutlier = false,
        public int $ageDifference = 0,
        public bool $lateAssessment = false,
    ) {
        if (1 !== preg_match('/^\d{1,20}$/', $caseNumber)) {
            throw new \InvalidArgumentException(sprintf('A synthetic case number has digits only, got "%s".', $caseNumber));
        }

        if ((SyntheticVisitKind::Linked === $kind) !== (null !== $allocationId)) {
            throw new \InvalidArgumentException('Exactly the linked visits point to an allocation.');
        }

        if ('' === ($cells['ed_admission_at'] ?? '') && '' === ($cells['first_assessment_contact_at'] ?? '')) {
            throw new \InvalidArgumentException('A visit needs an ED admission or a first assessment contact.');
        }

        if ($lateAssessment && ('' === ($cells['ed_admission_at'] ?? '') || '' === ($cells['first_assessment_contact_at'] ?? ''))) {
            throw new \InvalidArgumentException('A late assessment needs both the ED admission and the later first assessment contact.');
        }
    }

    /**
     * The cells as an export sees them: before the late assessment is documented, it is empty.
     *
     * @return array<string, string>
     */
    public function cellsAsExported(bool $assessmentDocumented): array
    {
        if (!$this->lateAssessment || $assessmentDocumented) {
            return $this->cells;
        }

        $cells = $this->cells;
        foreach (['first_assessment_contact_at', 'assessment_start_at', 'assessment_end_at', 'mts_category', 'mts_diagram', 'mts_indicator', 'pain_score', 'physician_target_at'] as $field) {
            $cells[$field] = '';
        }

        return $cells;
    }

    public function edAdmissionAt(): ?\DateTimeImmutable
    {
        return $this->parse($this->cells['ed_admission_at'] ?? '');
    }

    public function firstAssessmentContactAt(): ?\DateTimeImmutable
    {
        return $this->parse($this->cells['first_assessment_contact_at'] ?? '');
    }

    /**
     * The arrival anchor of matching (ADR 030): ED admission, otherwise first assessment contact.
     */
    public function matchingAnchor(): \DateTimeImmutable
    {
        return $this->edAdmissionAt() ?? $this->firstAssessmentContactAt() ?? throw new \LogicException('A visit always has an anchor.');
    }

    /**
     * The day an export selects the visit by: the day of its earliest anchor.
     */
    public function day(): string
    {
        $admission = $this->edAdmissionAt();
        $assessment = $this->firstAssessmentContactAt();
        $earliest = $admission instanceof \DateTimeImmutable && $assessment instanceof \DateTimeImmutable ? min($admission, $assessment) : ($admission ?? $assessment);

        return ($earliest ?? throw new \LogicException('A visit always has an anchor.'))->format('Y-m-d');
    }

    private function parse(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!'.self::TIMESTAMP_FORMAT, $value);

        return false === $parsed ? throw new \LogicException(sprintf('Invalid timestamp "%s".', $value)) : $parsed;
    }
}
