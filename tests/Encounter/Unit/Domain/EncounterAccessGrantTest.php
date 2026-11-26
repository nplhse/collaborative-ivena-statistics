<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Domain;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class EncounterAccessGrantTest extends TestCase
{
    public function testReviewImpliesViewCases(): void
    {
        $grant = new EncounterAccessGrant(new Hospital(), new User(), [EncounterGrantAction::Review]);

        self::assertTrue($grant->allows(EncounterGrantAction::Review));
        self::assertTrue($grant->allows(EncounterGrantAction::ViewCases));
        self::assertFalse($grant->allows(EncounterGrantAction::Upload));
        self::assertSame([EncounterGrantAction::Review, EncounterGrantAction::ViewCases], $grant->getActions());
    }

    public function testUploadDoesNotImplyCaseAccess(): void
    {
        $grant = new EncounterAccessGrant(new Hospital(), new User(), [EncounterGrantAction::Upload]);

        self::assertSame([EncounterGrantAction::Upload], $grant->getActions());
    }

    public function testChangeActionsReplacesAllActions(): void
    {
        $grant = new EncounterAccessGrant(new Hospital(), new User(), [EncounterGrantAction::Upload, EncounterGrantAction::Review]);
        $grant->changeActions([EncounterGrantAction::ViewCases]);

        self::assertSame([EncounterGrantAction::ViewCases], $grant->getActions());
    }

    public function testGrantWithoutActionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncounterAccessGrant(new Hospital(), new User(), []);
    }
}
