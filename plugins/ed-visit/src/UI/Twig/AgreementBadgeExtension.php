<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Twig;

use Cois\Plugin\EdVisit\Application\Matching\MatchingSettings;
use Cois\Plugin\EdVisit\Application\Validation\ScoreBands;

/**
 * Colour of an agreement badge (#1401), from the matcher's thresholds and the validation's score bands:
 * below the proposal threshold red, proposals orange and, from the first band bound between both thresholds,
 * yellow, from the auto-accept threshold green. Display only: the score is a ranking, not a probability
 * (ADR 030).
 *
 * @psalm-suppress UnusedClass Registered through the AsTwigFunction attribute.
 */
final readonly class AgreementBadgeExtension
{
    public function __construct(
        private MatchingSettings $settings,
        private ScoreBands $bands,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'encounter_agreement_badge_class')]
    public function badgeClass(float $score): string
    {
        $proposal = $this->settings->proposalThreshold;
        $autoAccept = $this->settings->autoAcceptThreshold;

        if ($score >= $autoAccept) {
            return 'bg-green-lt';
        }

        if ($score < $proposal) {
            return 'bg-red-lt';
        }

        foreach ($this->bands->lowerBounds as $bound) {
            if ($bound > $proposal && $bound < $autoAccept) {
                return $score >= $bound ? 'bg-yellow-lt' : 'bg-orange-lt';
            }
        }

        return 'bg-yellow-lt';
    }
}
