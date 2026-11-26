<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Encounter\UI\DataPrep\DataPrepContributions;
use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use App\Shared\Application\Navigation\Tool\ToolTopic;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The data preparation tool on the Tools page (#1051), for the encounter beta role and only while a schema
 * contributes to the tool (#1301).
 *
 * @psalm-suppress UnusedClass Tagged via ToolProviderInterface.
 */
final readonly class DataPrepToolProvider implements ToolProviderInterface
{
    public const string DATA_PREP = 'encounter_data_prep';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private DataPrepContributions $contributions,
    ) {
    }

    #[\Override]
    public function tools(): array
    {
        if ($this->contributions->isEmpty() || !$this->authorizationChecker->isGranted('ROLE_ENCOUNTER_BETA')) {
            return [];
        }

        return [
            new Tool(
                key: self::DATA_PREP,
                titleKey: 'tools.encounter_data_prep.title',
                descriptionKey: 'tools.encounter_data_prep.description',
                translationDomain: 'encounter',
                route: 'app_tools_data_prep',
                icon: 'tabler:file-export',
                category: ToolCategory::DataPreparation,
                beta: true,
                topics: [ToolTopic::Encounters, ToolTopic::Imports],
            ),
        ];
    }
}
