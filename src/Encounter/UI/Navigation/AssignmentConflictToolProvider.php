<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The assignment conflicts of encounter schemas (#1274, #1307) on the Tools page, for the encounter beta role like
 * the page itself; the page shows only the hospitals the user may review. The page belongs to the core, so its
 * entry stays when the ED visit plugin is not installed (#1300).
 *
 * @psalm-suppress UnusedClass Tagged via ToolProviderInterface.
 */
final readonly class AssignmentConflictToolProvider implements ToolProviderInterface
{
    public const string ASSIGNMENTS = 'encounter_assignments';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[\Override]
    public function tools(): array
    {
        if (!$this->authorizationChecker->isGranted('ROLE_ENCOUNTER_BETA')) {
            return [];
        }

        return [
            new Tool(
                key: self::ASSIGNMENTS,
                titleKey: 'tools.encounter_assignments.title',
                descriptionKey: 'tools.encounter_assignments.description',
                translationDomain: 'encounter',
                route: 'app_tools_encounter_assignments',
                icon: 'tabler:link-off',
                category: ToolCategory::Matching,
                beta: true,
            ),
        ];
    }
}
