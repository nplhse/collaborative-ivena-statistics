<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Plugin;

use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The test bundle `inpatient_stay` contributes a statistics page through the Statistics page API (#1300): its
 * entry in the clinical group of the statistics sidebar, the header controls of the statistics pages and its own
 * figure for the chosen period. Two stays in October, one in November.
 */
#[ResetDatabase]
final class InpatientStayStatisticsPageTest extends WebTestCase
{
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;

    private const array OCTOBER = ['period' => 'month', 'year' => '2026', 'month' => '10'];

    public function testAViewerSeesTheStaysOfThePeriodWithTheStatisticsControls(): void
    {
        $client = self::createClient();
        $hospital = $this->hospital($this->user());
        $viewer = $this->user();
        $this->encounterGrant($hospital, $viewer, [EncounterGrantAction::ViewCases]);
        $import = (int) $this->encounterImport($hospital, 'stays')->getId();
        foreach (['2026-10-03 10:00', '2026-10-20 08:00', '2026-11-02 09:00'] as $i => $admittedAt) {
            $this->entityManager()->getConnection()->insert('test_inpatient_stay', [
                'hospital_id' => $hospital->getId(),
                'key_generation' => 1,
                'import_id' => $import,
                'stay_fingerprint' => hash('sha256', 'stay '.$i),
                'case_key' => hash('sha256', 'case '.$i),
                'admitted_at' => $admittedAt,
            ]);
        }

        $client->loginUser($viewer);
        $client->request(Request::METHOD_GET, '/statistics/inpatient-stays', self::OCTOBER);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="inpatient-stay-statistics-count"]', '2 stays admitted in the period');
        self::assertSelectorExists('[data-testid="sidebar-entry-inpatient-stays"]');
        self::assertSelectorExists('[data-testid="sidebar-entry-encounter-analytics"]', 'Both plugins add to the clinical group.');
        self::assertSelectorTextContains('.page-header', $hospital->getName());
    }

    public function testWithoutAnyHospitalThePageIsForbidden(): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());
        $client->request(Request::METHOD_GET, '/statistics/inpatient-stays');

        self::assertResponseStatusCodeSame(403);
    }
}
