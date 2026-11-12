<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure;

use App\Encounter\Domain\Entity\EncounterKeyState;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class EncounterKeyStateTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testNewStateStartsAtFirstGenerationWithoutCheckValue(): void
    {
        $hospital = $this->encounterHospital();
        $em = $this->encounterEntityManager();
        $em->persist(new EncounterKeyState($hospital));
        $em->flush();
        $em->clear();

        $state = $em->getRepository(EncounterKeyState::class)->findOneBy(['hospital' => $hospital->getId()]);

        self::assertInstanceOf(EncounterKeyState::class, $state);
        self::assertNotNull($state->getId());
        self::assertSame($hospital->getId(), $state->getHospital()->getId());
        self::assertSame(EncounterKeyState::FIRST_GENERATION, $state->getKeyGeneration());
        self::assertNull($state->getActiveCheckValue());
        self::assertSame([], $state->getRetiredCheckValues());
        self::assertFalse($state->isKeyChangeInProgress());
        self::assertInstanceOf(\DateTimeImmutable::class, $state->getCreatedAt());
    }

    public function testRetiredCheckValuesAreRecognised(): void
    {
        $hospital = $this->encounterHospital();
        $em = $this->encounterEntityManager();
        $em->persist(new EncounterKeyState($hospital));
        $em->flush();

        // Written by the key change (#1050); set directly here.
        $em->getConnection()->executeStatement(
            'UPDATE encounter_key_state SET key_generation = 2, active_check_value = ?, retired_check_values = ?, key_change_in_progress = true WHERE hospital_id = ?',
            [str_repeat('b', 64), json_encode([str_repeat('a', 64)]), $hospital->getId()],
        );
        $em->clear();

        $state = $em->getRepository(EncounterKeyState::class)->findOneBy(['hospital' => $hospital->getId()]);

        self::assertInstanceOf(EncounterKeyState::class, $state);
        self::assertSame(2, $state->getKeyGeneration());
        self::assertSame(str_repeat('b', 64), $state->getActiveCheckValue());
        self::assertTrue($state->isRetired(str_repeat('a', 64)));
        self::assertFalse($state->isRetired(str_repeat('b', 64)));
        self::assertTrue($state->isKeyChangeInProgress());
    }

    public function testOneStatePerHospital(): void
    {
        $hospital = $this->encounterHospital();
        $em = $this->encounterEntityManager();
        $em->persist(new EncounterKeyState($hospital));
        $em->persist(new EncounterKeyState($hospital));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }
}
