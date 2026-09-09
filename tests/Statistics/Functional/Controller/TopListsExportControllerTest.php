<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\RateLimit\DeniesRateLimiter;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TopListsExportControllerTest extends WebTestCase
{
    use DeniesRateLimiter;
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    public function testExportReturnsStreamedCsvForCurrentRanking(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedTopListExportData();

        $csv = $this->exportCsv($client, '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=all');

        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        $disposition = $client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertMatchesRegularExpression('/attachment; filename="top-diagnoses-top-25-\d{4}-\d{2}-\d{2}\.csv"/', $disposition);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $lines = $this->csvLines($csv);
        self::assertSame('Scope', $lines[0][0]);
        self::assertSame('Rank', $lines[0][2]);
        self::assertSame('Indication', $lines[0][3]);
        self::assertSame('Public', $lines[1][0]);
        self::assertSame('Last 12 months', $lines[1][1]);
        self::assertTrue($this->csvContainsLabel($lines, 'Export Current 01'));
        self::assertFalse($this->csvContainsLabel($lines, 'Export Historic Only'));
        self::assertCount(13, $lines);
    }

    public function testExportRespectsRankingLimitIncludingAll(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedTopListExportData();

        $top10 = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=all&limit=10',
        ));
        $all = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=all&limit=all&per_page=25&page=2',
        ));

        self::assertCount(11, $top10);
        self::assertCount(13, $all);
        $disposition = $client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString('top-diagnoses-all-', $disposition);
    }

    public function testExportRespectsPeriodAndUrgencyFilter(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedTopListExportData();

        $year2024 = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=year&year=2024',
        ));
        $emergency = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=all&urgency=1',
        ));

        self::assertTrue($this->csvContainsLabel($year2024, 'Export Historic Only'));
        self::assertFalse($this->csvContainsLabel($year2024, 'Export Current 01'));
        self::assertTrue($this->csvContainsLabel($emergency, 'Export Current 01'));
        self::assertTrue($this->csvContainsLabel($emergency, 'Export Current 02'));
        self::assertFalse($this->csvContainsLabel($emergency, 'Export Current 03'));
        self::assertCount(3, $emergency);
    }

    public function testComparisonExportMergesBothSides(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedTopListExportData();

        $csv = $this->exportCsv(
            $client,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=year&year=2024&compare=1&comparison_scope=public&comparison_period=all',
        );
        $disposition = $client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString('comparison', $disposition);

        $lines = $this->csvLines($csv);
        self::assertSame('Scope A', $lines[0][0]);
        self::assertSame('Scope B', $lines[0][2]);
        self::assertSame('Rank A', $lines[0][5]);
        self::assertSame('Count B', $lines[0][9]);
        self::assertSame('Relative delta', $lines[0][13]);
        self::assertTrue($this->csvContainsLabel($lines, 'Export Historic Only', 4));
        self::assertTrue($this->csvContainsLabel($lines, 'Export Current 01', 4));
    }

    public function testExportUsesGermanColumnLabels(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER'], 'locale' => 'de']);
        $client->loginUser($user);
        $this->seedTopListExportData();

        $lines = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=all',
        ));

        self::assertSame('Bereich', $lines[0][0]);
        self::assertSame('Zeitraum', $lines[0][1]);
        self::assertSame('Rang', $lines[0][2]);
        self::assertSame('Indikation', $lines[0][3]);
        self::assertSame('Anzahl', $lines[0][4]);
        self::assertSame('Anteil', $lines[0][5]);
        self::assertSame('Öffentlich', $lines[1][0]);
        self::assertSame('Letzte 12 Monate', $lines[1][1]);
    }

    public function testComparisonExportUsesGermanColumnLabels(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER'], 'locale' => 'de']);
        $client->loginUser($user);
        $this->seedTopListExportData();

        $lines = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=year&year=2024&compare=1&comparison_scope=public&comparison_period=all',
        ));

        self::assertSame('Vergleich A', $lines[0][0]);
        self::assertSame('Zeitraum A', $lines[0][1]);
        self::assertSame('Vergleich B', $lines[0][2]);
        self::assertSame('Zeitraum B', $lines[0][3]);
    }

    public function testExportRequiresLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/statistics/top-lists/top_diagnoses/export.csv?scope=public');

        $this->assertResponseRedirects('/login');
    }

    public function testUnknownTopListReturnsNotFound(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/top-lists/not_a_real_list/export.csv?scope=public');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testExportIsRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = $this->loginAsRoleUser($client);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->denyRateLimiter(
            'limiter.top_lists_export',
            $this->userAndIpRateLimitKey('top_lists_export', $userId),
        );

        $client->request(
            Request::METHOD_GET,
            '/statistics/top-lists/top_diagnoses/export.csv?scope=public&period=all',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function seedTopListExportData(): void
    {
        $user = UserFactory::createOne(['username' => 'top-lists-export-seed']);
        $state = StateFactory::createOne(['name' => 'Top Lists Export State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Top Lists Export Dispatch']);
        $hospital = HospitalFactory::createOne(['name' => 'Top Lists Export Hospital']);
        $import = ImportFactory::createOne(['name' => 'Top Lists Export Import', 'hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'Top Lists Export Speciality']);
        DepartmentFactory::createOne(['name' => 'Top Lists Export Department']);
        AssignmentFactory::createOne(['name' => 'Top Lists Export Assignment']);
        OccasionFactory::createOne(['name' => 'Top Lists Export Occasion']);
        InfectionFactory::createOne(['name' => 'Top Lists Export Infection']);

        $defaults = [
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'import' => $import,
            'hospital' => $hospital,
        ];

        for ($i = 1; $i <= 12; ++$i) {
            $raw = IndicationRawFactory::createOne(['name' => sprintf('Export Current Raw %02d', $i)]);
            $normalized = IndicationNormalizedFactory::createOne(['name' => sprintf('Export Current %02d', $i)]);
            AllocationFactory::createOne(array_merge($defaults, [
                'createdAt' => new \DateTimeImmutable('today'),
                'arrivalAt' => new \DateTimeImmutable('today'),
                'indicationRaw' => $raw,
                'indicationNormalized' => $normalized,
                'urgency' => $i <= 2 ? AllocationUrgency::EMERGENCY : AllocationUrgency::INPATIENT,
            ]));
        }

        $historicRaw = IndicationRawFactory::createOne(['name' => 'Export Historic Raw']);
        $historicNormalized = IndicationNormalizedFactory::createOne(['name' => 'Export Historic Only']);
        AllocationFactory::createOne(array_merge($defaults, [
            'createdAt' => new \DateTimeImmutable('2024-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-06-15 10:20:00'),
            'indicationRaw' => $historicRaw,
            'indicationNormalized' => $historicNormalized,
            'urgency' => AllocationUrgency::INPATIENT,
        ]));

        $this->rebuildProjectionForImports([(int) $import->getId()]);
    }

    private function exportCsv(KernelBrowser $client, string $uri): string
    {
        $client->request(Request::METHOD_GET, $uri);
        self::assertResponseIsSuccessful();
        $content = $client->getInternalResponse()->getContent();
        self::assertNotSame('', $content);

        return $content;
    }

    /**
     * @return list<list<string>>
     */
    private function csvLines(string $csv): array
    {
        $body = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $lines = preg_split('/\R/', trim($body));
        self::assertIsArray($lines);

        return array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            $lines,
        );
    }

    /**
     * @param list<list<string>> $lines
     */
    private function csvContainsLabel(array $lines, string $label, int $column = 3): bool
    {
        return array_any(\array_slice($lines, 1), fn ($row): bool => ($row[$column] ?? null) === $label);
    }
}
