<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\Sex;

/**
 * The fields of an ED visit the matcher compares (ADR 030). MTS category, MTS indicator and the admitting
 * department are deliberately absent: later analyses compare them with IVENA. The MTS diagram is read only for the
 * plausibility bonus of pass 2 (matcher 1.2.0, ADR 033), which records per pair whether it decided the link.
 */
final readonly class MatchingEncounter
{
    public function __construct(
        public int $id,
        /** ED admission, otherwise the first assessment contact (see $anchorSource). */
        public \DateTimeImmutable $anchorAt,
        public AnchorSource $anchorSource,
        public Sex $sex,
        public int $age,
        public ?ArrivalMode $arrivalMode,
        public ArrivalRoute $arrivalRoute,
        /** true = documented, null = not documented */
        public ?bool $workAccident,
        /** MTS diagram code of the initial triage as stored (may be an alias), null when not documented. */
        public ?string $mtsDiagram = null,
    ) {
    }

    /**
     * Matching anchor of ADR 030: the documented ED admission first, the first assessment contact as
     * marked substitute. (The fingerprint anchor of ADR 029 uses the opposite order.).
     *
     * @return array{0: \DateTimeImmutable, 1: AnchorSource}|null
     */
    public static function anchorOf(?\DateTimeImmutable $edAdmissionAt, ?\DateTimeImmutable $firstAssessmentContactAt): ?array
    {
        if ($edAdmissionAt instanceof \DateTimeImmutable) {
            return [$edAdmissionAt, AnchorSource::Admission];
        }

        if ($firstAssessmentContactAt instanceof \DateTimeImmutable) {
            return [$firstAssessmentContactAt, AnchorSource::Assessment];
        }

        return null;
    }
}
