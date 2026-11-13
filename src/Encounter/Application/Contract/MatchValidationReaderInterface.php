<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;

/**
 * Reads of the match validation (#1057).
 */
interface MatchValidationReaderInterface
{
    /**
     * Samples of the hospital, newest first.
     *
     * @return list<MatchValidationSample>
     */
    public function samples(int $hospitalId): array;

    public function sample(int $hospitalId, int $sampleId): ?MatchValidationSample;

    /**
     * Items of the sample in sample order.
     *
     * @return list<MatchValidationItem>
     */
    public function items(MatchValidationSample $sample): array;

    public function item(MatchValidationSample $sample, int $itemId): ?MatchValidationItem;

    /**
     * Every item of the hospital's samples by the matcher version of its sample, for the combined report.
     *
     * @return array<string, list<array{stratum: ValidationStratum, band: ?string, verdict: ?ValidationVerdict}>>
     */
    public function itemsByMatcherVersion(int $hospitalId): array;

    /**
     * Verdicts on pairs of the hospital's samples as "allocationId:encounterId" => belongs together
     * (correct: true, wrong: false); unclear and open items are left out, the latest verdict wins.
     *
     * @return array<string, bool>
     */
    public function pairVerdicts(int $hospitalId): array;

    /**
     * Visit ids of the hospital by their visit fingerprint, for comparing with a synthetic truth.
     *
     * @param list<string> $fingerprints
     *
     * @return array<string, int>
     */
    public function encounterIdsByFingerprint(int $hospitalId, array $fingerprints): array;
}
