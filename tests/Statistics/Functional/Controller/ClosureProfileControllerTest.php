<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileRef;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureProfileQuery;
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
final class ClosureProfileControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RebuildsClosureAnalysis;

    public function testProfilePagesListsAndDrilldownUseTheSameDefinition(): void
    {
        $client = self::createClient();
        $owner = $this->login($client);
        $seed = $this->seed($owner, 'Profile Page Hospital', 'profile-page-group');
        $foreignOwner = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]]);
        $foreign = $this->seed($foreignOwner, 'Foreign Profile Hospital', 'foreign-profile-group');
        $eventId = $this->closureEventId($seed['hospitalId'], 'profile-page-group');
        $links = self::getContainer()->get(ClosureProfileQuery::class)->linksForEvent($eventId, $seed['hospitalId']);
        self::assertNotNull($links->groupKey);
        self::assertCount(2, $links->specialities);

        $query = '?scope=hospital&hospital='.$seed['hospitalId'].'&period=all_time';
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics'.$query);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="closure-profile-groups"]');
        $hospitalHref = $client->getCrawler()->filter('[data-testid="stats-closure-breakdown-hospital"] [data-testid="stats-closure-breakdown-link"]')->attr('href');
        self::assertIsString($hospitalHref);
        self::assertStringContainsString('/profiles/hospital/'.$seed['hospitalId'], $hospitalHref);
        $specialityHref = $client->getCrawler()->filter('[data-testid="stats-closure-breakdown-speciality"] [data-testid="stats-closure-breakdown-link"]')->attr('href');
        self::assertIsString($specialityHref);
        self::assertStringContainsString('/profiles/speciality/'.$seed['hospitalId'].'/'.$seed['specialityId'], $specialityHref);
        $departmentHref = $client->getCrawler()->filter('[data-testid="stats-closure-breakdown-department"] [data-testid="stats-closure-breakdown-link"]')->attr('href');
        self::assertIsString($departmentHref);
        self::assertStringContainsString('/profiles/department/'.$seed['hospitalId'].'/', $departmentHref);
        $unitHref = $client->getCrawler()->filter('[data-testid="stats-closure-unit-profile-link"]')->attr('href');
        self::assertIsString($unitHref);
        self::assertStringContainsString('/profiles/unit/'.$seed['hospitalId'], $unitHref);

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/profiles'.$query);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-tab-profiles"].active');
        $this->assertSelectorExists('[data-testid="closure-profile-groups"]');
        $this->assertSelectorExists('[data-testid="closure-profile-group-link"]');
        $this->assertSelectorNotExists('[data-testid="closure-recurring-events-link"]');
        $this->assertSelectorExists('[data-testid="data-table-toolbar"]');

        $client->request(Request::METHOD_GET, $hospitalHref);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-profile-title"]', 'Profile Page Hospital');

        $client->request(Request::METHOD_GET, $departmentHref);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-profile-title"]', 'department');

        $client->request(Request::METHOD_GET, $unitHref);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-profile"]', 'Profile Page Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/events/'.$eventId.$query);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-event-group-profile"]', 'Profile Page Hospital department');
        $this->assertSelectorCount(2, '[data-testid="closure-event-speciality-profile"]');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/profiles/speciality/'.$seed['hospitalId'].'/'.$seed['specialityId'].$query,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="closure-profile-title"]', 'Profile Speciality');
        $this->assertSelectorTextContains('[data-testid="closure-profile-event-count"]', '1');
        $this->assertSelectorNotExists('[data-testid="closure-profile-not-additive"]');
        $this->assertSelectorExists('[data-testid="closure-profile-actions"] .btn-list.flex-column');
        $this->assertSelectorExists('[data-testid="closure-profile-tab-composition"]');
        $this->assertSelectorTextContains('[data-testid="closure-profile-composition"]', 'Profile Page Hospital department');
        $this->assertSelectorExists('[data-testid="closure-profile-provenance"]');
        $this->assertSelectorExists('[data-testid="closure-profile-coverage-years"]');
        $this->assertSelectorNotExists('[data-testid="closure-profile-course-start"] .badge');
        $this->assertSelectorTextContains('[data-testid="closure-profile-course-empty"]', 'No typical course yet');
        $this->assertSelectorTextContains('[data-testid="closure-profile-course-empty"]', 'Adjust filters');
        $this->assertSelectorExists('[data-testid="closure-profile-timeline-link"]');
        $profileQuery = ClosureProfileRef::speciality($seed['hospitalId'], $seed['specialityId'])->toQuery();
        $eventsHref = $client->getCrawler()->filter('[data-testid="closure-profile-events-link"]')->attr('href');
        self::assertIsString($eventsHref);
        self::assertStringContainsString('closureProfile=', $eventsHref);
        self::assertStringContainsString((string) $seed['specialityId'], urldecode($eventsHref));

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/events'.$query.'&closureProfile='.$profileQuery);
        $this->assertResponseIsSuccessful();

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/profiles/group/'.$seed['hospitalId'].'/'.$links->groupKey.$query,
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-profile-rule"]');
        $this->assertSelectorTextContains('[data-testid="closure-profile-title"]', 'Profile Page Hospital department');
        $this->assertSelectorTextContains('[data-testid="closure-profile-title"]', 'Second Profile Speciality');
        $this->assertSelectorTextContains('[data-testid="closure-profile-event-count"]', '1');
        $this->assertSelectorCount(2, '[data-testid="closure-profile-composition-row"]');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/profiles/group/'.$seed['hospitalId'].'/'.$links->groupKey.$query.'&tab=composition',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-profile-tab-composition"].active');

        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/profiles/speciality/'.$foreign['hospitalId'].'/'.$foreign['specialityId'].$query,
        );
        $this->assertResponseStatusCodeSame(404);
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/profiles/hospital/'.$foreign['hospitalId'].$query,
        );
        $this->assertResponseStatusCodeSame(404);
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/profiles/department/'.$foreign['hospitalId'].'/1'.$query,
        );
        $this->assertResponseStatusCodeSame(404);
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/profiles/unit/'.$seed['hospitalId'].$query,
        );
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRedundantGroupConfigurationOmitsOverviewAndEventLink(): void
    {
        $client = self::createClient();
        $owner = $this->login($client);
        $seed = $this->seed($owner, 'Redundant Group Hospital', 'redundant-profile-group', 'Redundant Group Hospital unit');
        $eventId = $this->closureEventId($seed['hospitalId'], 'redundant-profile-group');
        $query = '?scope=hospital&hospital='.$seed['hospitalId'].'&period=all_time';

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/profiles'.$query);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="closure-profile-groups"]');
        $this->assertSelectorNotExists('[data-testid="closure-profile-group-link"]');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/events/'.$eventId.$query);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="closure-event-group-profile"]');
    }

    private function login(KernelBrowser $client): User
    {
        $user = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]]);
        $client->followRedirects(true);
        $client->loginUser($user);

        return $user;
    }

    /**
     * @return array{hospitalId: int, specialityId: int}
     */
    private function seed(User $owner, string $hospitalName, string $groupId, ?string $sharedClosureUnit = null): array
    {
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => $hospitalName,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'owner' => $owner,
            'createdBy' => $owner,
        ]);
        $speciality = SpecialityFactory::createOne(['name' => 'Profile Speciality']);
        $otherSpeciality = SpecialityFactory::createOne(['name' => 'Second Profile Speciality']);
        $department = DepartmentFactory::createOne(['name' => $hospitalName.' department']);
        $otherDepartment = DepartmentFactory::createOne(['name' => $hospitalName.' other department']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $owner]);
        $connection = self::getContainer()->get(Connection::class);
        foreach ([[$speciality, $department, 'a'], [$otherSpeciality, $otherDepartment, 'b']] as [$rowSpeciality, $rowDepartment, $suffix]) {
            $connection->insert('closure_interval', [
                'hospital_id' => $hospital->getId(),
                'import_id' => $import->getId(),
                'speciality_id' => $rowSpeciality->getId(),
                'department_id' => $rowDepartment->getId(),
                'starts_at' => '2026-05-01 10:00:00',
                'ends_at' => '2026-05-01 12:00:00',
                'care_level' => 'emergency',
                'reason' => 'no_bed_capacity',
                'facility_kind' => 'clinic',
                'closure_unit' => $sharedClosureUnit ?? $hospitalName.' '.$suffix,
                'source_group_id' => $groupId,
                'source_recorded_at' => '2026-05-01 09:00:00',
                'source_changed_at' => '2026-05-01 09:00:00',
            ]);
        }
        $this->rebuildClosureAnalysis();

        return [
            'hospitalId' => (int) $hospital->getId(),
            'specialityId' => (int) $speciality->getId(),
        ];
    }
}
