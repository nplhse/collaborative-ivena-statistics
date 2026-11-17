<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\VisitList;

/**
 * Link state of one ED visit in the visit list and on its detail page (#1178).
 *
 * `Linked`: an effective link (`auto_accepted` or `confirmed`). `Pending`: no effective link and at least one
 * open proposal; review information, shown only with REVIEW. `Unlinked`: everything else, including visits
 * with only rejected pairs and, without REVIEW, visits with open proposals.
 */
enum VisitLinkState: string
{
    case Linked = 'linked';
    case Pending = 'pending';
    case Unlinked = 'unlinked';
}
