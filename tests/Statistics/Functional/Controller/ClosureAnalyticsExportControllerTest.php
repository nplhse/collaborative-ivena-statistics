<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Statistics\Support\RebuildsClosureAnalysis;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureAnalyticsExportControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RebuildsClosureAnalysis;

    public function testExportRequiresClosureBetaRole(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/export.csv?scope=public&period=all_time');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCsvExportsTheFullMatchingSetWithVisibleColumnsAndSort(): void
    {
        $client = self::createClient();
        $user = $this->loginAsClosureBetaUser($client);
        $this->seedJanuaryClosures($user, 30);

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=year&year=2026&sortBy=startsAt&orderBy=asc&limit=25&page=2&closureReasons[]=technical_fault',
        );
        $this->assertResponseIsSuccessful();
        $htmlRows = $client->getCrawler()->filter('[data-testid="stats-closure-event-row"]')->count();
        self::assertSame(5, $htmlRows);
        $this->assertSelectorExists('[data-testid="stats-closure-table-export"]');

        $csv = $this->exportCsv(
            $client,
            '/statistics/closure-analytics/export.csv?scope=public&period=year&year=2026&sortBy=startsAt&orderBy=asc&limit=25&page=2&closureReasons[]=technical_fault&columns=hospital,event,startsAt&columnOrder=hospital,event,startsAt',
        );
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringNotContainsString('<span', $csv);
        self::assertStringNotContainsString('<a ', $csv);

        $lines = $this->csvLines($csv);
        self::assertCount(31, $lines);
        self::assertSame(['Hospital', 'Event', 'Start', 'Duration'], $lines[0]);
        self::assertSame('01.01.2026 10:00', $lines[1][2]);
        self::assertSame('30.01.2026 10:00', $lines[30][2]);
        foreach ($lines as $line) {
            self::assertCount(4, $line);
            self::assertFalse(\in_array('End', $line, true));
        }

        $unsortedFallback = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/closure-analytics/export.csv?scope=public&period=year&year=2026&sortBy=not-a-column&orderBy=sideways&columns=startsAt,event,actualMinutes,unknown',
        ));
        self::assertSame('Start', $unsortedFallback[0][0]);
        self::assertSame('30.01.2026 10:00', $unsortedFallback[1][0]);
        self::assertFalse(\in_array('Hospital', $unsortedFallback[0], true));
    }

    public function testIntervalExportRespectsTheIntervalView(): void
    {
        $client = self::createClient();
        $user = $this->loginAsClosureBetaUser($client);
        $this->seedJanuaryClosures($user, 2);

        $lines = $this->csvLines($this->exportCsv(
            $client,
            '/statistics/closure-analytics/export.csv?scope=public&period=year&year=2026&tableView=intervals&sortBy=startsAt&orderBy=asc&columns=department,startsAt,event,durationMinutes&columnOrder=department,startsAt,event,durationMinutes',
        ));

        self::assertSame('Department', $lines[0][0]);
        self::assertSame('Export Closure Department', $lines[1][0]);
        self::assertCount(3, $lines);
    }

    private function loginAsClosureBetaUser(KernelBrowser $client): User
    {
        $user = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA],
        ]);
        $client->followRedirects(true);
        $client->loginUser($user);

        return $user;
    }

    private function seedJanuaryClosures(User $user, int $days): void
    {
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Export Closure Hospital',
            'state' => $state,
            'dispatchArea' => $dispatch,
            'owner' => $user,
            'createdBy' => $user,
        ]);
        $speciality = SpecialityFactory::createOne(['name' => 'Export Closure Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Export Closure Department']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        for ($day = 1; $day <= $days; ++$day) {
            $date = sprintf('2026-01-%02d', $day);
            $connection->insert('closure_interval', [
                'hospital_id' => $hospital->getId(),
                'import_id' => $import->getId(),
                'speciality_id' => $speciality->getId(),
                'department_id' => $department->getId(),
                'starts_at' => $date.' 10:00:00',
                'ends_at' => $date.' 11:00:00',
                'care_level' => 'emergency',
                'reason' => 'technical_fault',
                'facility_kind' => 'clinic',
                'closure_unit' => 'Export unit',
                'source_group_id' => null,
                'source_recorded_at' => $date.' 09:00:00',
                'source_changed_at' => $date.' 09:00:00',
            ]);
        }
        $this->rebuildClosureAnalysis();
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
}
