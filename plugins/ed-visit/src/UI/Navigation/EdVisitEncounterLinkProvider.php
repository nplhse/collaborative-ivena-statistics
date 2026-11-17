<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Navigation;

use App\Encounter\Contract\Display\EncounterLinkProviderInterface;
use Cois\Plugin\EdVisit\Application\Contract\VisitListReaderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Links encounters with an ED visit to the visit page (assignment conflicts, #1300).
 *
 * @psalm-suppress UnusedClass Tagged via EncounterLinkProviderInterface.
 */
final readonly class EdVisitEncounterLinkProvider implements EncounterLinkProviderInterface
{
    public function __construct(
        private VisitListReaderInterface $visits,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function linksForEncounters(array $encounterIds): array
    {
        return array_map(
            fn (string $visitUid): string => $this->urlGenerator->generate('app_explore_ed_visit_show', ['visitUid' => $visitUid]),
            $this->visits->visitUids($encounterIds),
        );
    }
}
