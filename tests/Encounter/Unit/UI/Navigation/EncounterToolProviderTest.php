<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\UI\Navigation;

use App\Encounter\UI\Navigation\EncounterToolProvider;
use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class EncounterToolProviderTest extends TestCase
{
    public function testBetaUserGetsReviewValidationAndDataPreparation(): void
    {
        $tools = new EncounterToolProvider($this->checker(true))->tools();

        self::assertSame(
            [
                EncounterToolProvider::REVIEW => ['app_tools_encounter_review', [], ToolCategory::Matching],
                EncounterToolProvider::VALIDATION => ['app_tools_encounter_review', ['mode' => 'validation'], ToolCategory::Matching],
                EncounterToolProvider::DATA_PREP => ['app_tools_data_prep', [], ToolCategory::DataPreparation],
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
        self::assertSame([], new EncounterToolProvider($this->checker(false))->tools());
    }

    private function checker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())->method('isGranted')->with('ROLE_ENCOUNTER_BETA')->willReturn($granted);

        return $checker;
    }
}
