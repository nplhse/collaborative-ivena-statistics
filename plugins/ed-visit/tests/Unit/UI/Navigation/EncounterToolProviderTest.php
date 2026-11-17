<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Unit\UI\Navigation;

use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use Cois\Plugin\EdVisit\UI\Navigation\EncounterToolProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class EncounterToolProviderTest extends TestCase
{
    public function testBetaUserGetsReviewValidationAndAssignmentConflicts(): void
    {
        $tools = new EncounterToolProvider($this->checker(['ROLE_ENCOUNTER_BETA']))->tools();

        self::assertSame(
            [
                EncounterToolProvider::REVIEW => ['app_tools_encounter_review', [], ToolCategory::Matching],
                EncounterToolProvider::VALIDATION => ['app_tools_encounter_review', ['mode' => 'validation'], ToolCategory::Matching],
                EncounterToolProvider::ASSIGNMENTS => ['app_tools_encounter_assignments', [], ToolCategory::Matching],
            ],
            array_combine(
                array_map(static fn (Tool $tool): string => $tool->key, $tools),
                array_map(static fn (Tool $tool): array => [$tool->route, $tool->routeParameters, $tool->category], $tools),
            ),
        );
        self::assertSame([true, true, true], array_map(static fn (Tool $tool): bool => $tool->beta, $tools));
    }

    public function testUserWithoutBetaRoleGetsNoTool(): void
    {
        self::assertSame([], new EncounterToolProvider($this->checker([]))->tools());
    }

    public function testCatalogReviewerAlsoGetsThePlausibilityTable(): void
    {
        $tools = new EncounterToolProvider($this->checker(['ROLE_ENCOUNTER_BETA', 'ROLE_ENCOUNTER_CATALOG_REVIEWER']))->tools();
        $plausibility = array_values(array_filter($tools, static fn (Tool $tool): bool => EncounterToolProvider::PLAUSIBILITY === $tool->key));

        self::assertCount(4, $tools, 'review, validation, assignment conflicts, plausibility; the data preparation tool comes from the core');
        self::assertCount(1, $plausibility);
        self::assertSame(['app_tools_encounter_plausibility', ToolCategory::Matching, true], [$plausibility[0]->route, $plausibility[0]->category, $plausibility[0]->beta]);
    }

    public function testCatalogReviewerWithoutBetaRoleGetsNoTool(): void
    {
        self::assertSame([], new EncounterToolProvider($this->checker(['ROLE_ENCOUNTER_CATALOG_REVIEWER']))->tools());
    }

    /**
     * @param list<string> $roles
     */
    private function checker(array $roles): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => \in_array($attribute, $roles, true));

        return $checker;
    }
}
