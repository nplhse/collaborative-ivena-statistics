<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Navigation;

use App\Allocation\Infrastructure\Security\Voter\IndicationRawReviewVoter;
use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lists the indication matching review in the Tools section (ADR 031). Lives next to the voter it
 * asks (Infrastructure), so the UI layer does not depend on Infrastructure.
 *
 * @psalm-suppress UnusedClass Tagged via ToolProviderInterface.
 */
final readonly class IndicationReviewToolProvider implements ToolProviderInterface
{
    public const string KEY = 'indication_review';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[\Override]
    public function tools(): array
    {
        if (!$this->authorizationChecker->isGranted(IndicationRawReviewVoter::VIEW)) {
            return [];
        }

        return [
            new Tool(
                key: self::KEY,
                titleKey: 'tools.indication_review.title',
                descriptionKey: 'tools.indication_review.description',
                translationDomain: 'allocation',
                route: 'app_explore_indication_raw_review_worklist',
                icon: 'tabler:list-check',
                category: ToolCategory::Matching,
                position: 10,
            ),
        ];
    }
}
