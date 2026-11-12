<?php

declare(strict_types=1);

namespace App\Encounter\Application\Matching;

use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\Sex;

/**
 * The fields of an ED visit the matcher compares (ADR 029). MTS category and diagram and the admitting
 * department are deliberately absent: later analyses compare them with IVENA.
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
    ) {
    }

    /**
     * Matching anchor of ADR 029: the documented ED admission first, the first assessment contact as
     * marked substitute. (The fingerprint anchor of ADR 028 uses the opposite order.).
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
