<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\VisitList;

use Cois\Plugin\EdVisit\Application\Review\ReviewPeriod;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalRoute;
use Cois\Plugin\EdVisit\Domain\Enum\Disposition;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;

/**
 * Filters of the visit list of one hospital (#1178). `includeProposals` is true only for callers that checked
 * REVIEW on the hospital; without it `Pending` is never a state and a `Pending` filter is dropped. With `importId`
 * the list holds the visits of that import of the hospital and the period does not apply (#1413).
 */
final readonly class VisitListFilter
{
    public ?VisitLinkState $linkState;

    /**
     * @param list<ArrivalRoute> $routes     empty: all
     * @param list<MtsCategory>  $categories empty: all
     */
    public function __construct(
        public int $hospitalId,
        public ReviewPeriod $period,
        public bool $includeProposals,
        public array $routes = [],
        ?VisitLinkState $linkState = null,
        public array $categories = [],
        public ?string $diagram = null,
        public ?Disposition $disposition = null,
        public ?int $importId = null,
    ) {
        $this->linkState = VisitLinkState::Pending === $linkState && !$includeProposals ? null : $linkState;
    }

    /**
     * Number of active filters besides hospital and period, the import included.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the template.
     */
    public function activeCount(): int
    {
        return ([] === $this->routes ? 0 : 1)
            + ($this->linkState instanceof VisitLinkState ? 1 : 0)
            + ([] === $this->categories ? 0 : 1)
            + (null === $this->diagram ? 0 : 1)
            + ($this->disposition instanceof Disposition ? 1 : 0)
            + (null === $this->importId ? 0 : 1);
    }
}
