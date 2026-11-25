<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Navigation;

use App\Allocation\Infrastructure\Security\Voter\ExportVoter;
use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use App\Shared\Application\Navigation\Tool\ToolTopic;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lists the allocation export in the Tools section (#1633, ADR 031 amendment); it used to be an entry of the top
 * navigation. The export voter reads the user's hospitals with the export permission, as the former navigation
 * entry did on every page.
 *
 * @psalm-suppress UnusedClass Tagged via ToolProviderInterface.
 */
final readonly class AllocationExportToolProvider implements ToolProviderInterface
{
    public const string KEY = 'allocation_export';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[\Override]
    public function tools(): array
    {
        if (!$this->authorizationChecker->isGranted(ExportVoter::EXPORT)) {
            return [];
        }

        return [
            new Tool(
                key: self::KEY,
                titleKey: 'tools.allocation_export.title',
                descriptionKey: 'tools.allocation_export.description',
                translationDomain: 'allocation',
                route: 'app_tools_allocation_export',
                icon: 'tabler:database-export',
                category: ToolCategory::DataExport,
                topics: [ToolTopic::Allocations],
            ),
        ];
    }
}
