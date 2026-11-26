<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\UI\CaseFile;

use App\Encounter\Contract\Display\EncounterCourse;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Cois\Plugin\EdVisit\UI\CaseFile\EdVisitCourseProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The ED course of an encounter for the hospital course card (#1300): none without an ED visit, an empty course
 * for a visit without effective link, the course template for a linked visit (MTS names stay empty without codes).
 */
#[ResetDatabase]
final class EdVisitCourseProviderTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    public function testTheCourseFollowsTheVisitAndItsLink(): void
    {
        self::bootKernel();
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $ed = $this->encounterImport($hospital, 'ED');
        $this->allocationAt($hospital, $ivena, '10:00');
        $linked = $this->visitAt($hospital, $ed, '10:05');
        $unlinked = $this->visitAt($hospital, $ed, '20:00', sex: Sex::Male, age: 20);
        $this->matchDay($hospital);
        $withoutVisit = (int) $this->encounterEntityManager()->getConnection()->fetchOne(
            "INSERT INTO encounter (hospital_id, key_generation, case_key, anchor_at, age, sex, created_at) VALUES (?, 1, ?, '2026-10-12 12:00:00', 40, 'F', '2026-10-12 12:00:00') RETURNING id",
            [$hospital->getId(), hash('sha256', 'no visit')],
        );

        $provider = self::getContainer()->get(EdVisitCourseProvider::class);
        self::assertInstanceOf(EdVisitCourseProvider::class, $provider);

        self::assertNull($provider->courseForEncounter($withoutVisit));
        self::assertEquals(new EncounterCourse(), $provider->courseForEncounter($unlinked));

        $course = $provider->courseForEncounter($linked);
        self::assertInstanceOf(EncounterCourse::class, $course);
        self::assertSame('@EdVisit/case_card/hospital_course.html.twig', $course->template);
        self::assertNull($course->context['diagram_name']);
        self::assertNull($course->context['indicator_name']);
    }
}
