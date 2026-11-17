<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\UI\Navigation;

use App\Encounter\UI\Navigation\AssignmentConflictToolProvider;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class AssignmentConflictToolProviderTest extends TestCase
{
    public function testBetaUserGetsTheAssignmentConflicts(): void
    {
        $tools = new AssignmentConflictToolProvider($this->checker(true))->tools();

        self::assertCount(1, $tools);
        self::assertSame(
            [AssignmentConflictToolProvider::ASSIGNMENTS, 'app_tools_encounter_assignments', [], ToolCategory::Matching, true, 'encounter'],
            [$tools[0]->key, $tools[0]->route, $tools[0]->routeParameters, $tools[0]->category, $tools[0]->beta, $tools[0]->translationDomain],
        );
    }

    public function testUserWithoutBetaRoleGetsNoTool(): void
    {
        self::assertSame([], new AssignmentConflictToolProvider($this->checker(false))->tools());
    }

    private function checker(bool $beta): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => $beta && 'ROLE_ENCOUNTER_BETA' === $attribute);

        return $checker;
    }
}
