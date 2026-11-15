<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\UI\Access;

use App\Encounter\Contract\Access\EncounterAccessInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The contract for plugin pages without a logged-in user and for an unknown hospital (the rules with users
 * are covered end to end by InpatientStayImportTest).
 */
#[ResetDatabase]
final class EncounterAccessTest extends KernelTestCase
{
    public function testWithoutUserNothingIsAccessible(): void
    {
        self::bootKernel();
        $access = self::getContainer()->get(EncounterAccessInterface::class);
        self::assertInstanceOf(EncounterAccessInterface::class, $access);

        self::assertSame([], $access->hospitalsWithViewCases());
        self::assertFalse($access->canViewCases(2_000_000_000));
    }
}
