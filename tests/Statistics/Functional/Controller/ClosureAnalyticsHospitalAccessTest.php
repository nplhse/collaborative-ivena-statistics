<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
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
final class ClosureAnalyticsHospitalAccessTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testParticipantSeesOnlyAssignedHospitalsAcrossQueriesExportsAndDirectIds(): void
    {
        $client = self::createClient();
        $owner = $this->login($client, [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]);
        $other = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]]);
        $own = $this->seedClosure($owner, 'Own Closure Hospital', 'own-group');
        $foreign = $this->seedClosure($other, 'Foreign Closure Hospital', 'foreign-group');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('scope=hospital', (string) $client->getRequest()->getUri());
        self::assertStringContainsString('hospital='.$own['hospitalId'], (string) $client->getRequest()->getUri());
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdowns"]', 'Own Closure Hospital');
        $this->assertSelectorTextNotContains('body', 'Foreign Closure Hospital');
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/duration?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-tab-duration"].active');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-median"]', '2 h');
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-pauses"]', 'Not computable');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-load"]', 'Foreign Closure Hospital');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-scope-group"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="my_hospitals"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-hospitals"] option[value="my_hospitals"]', 'My hospitals');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="hospital:'.$own['hospitalId'].'"][selected]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-hospitals"][multiple]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"]');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=state:1&period=year&year=2026');
        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('scope=public', (string) $client->getRequest()->getUri());
        self::assertStringNotContainsString('scope=state', (string) $client->getRequest()->getUri());
        self::assertStringContainsString('period=year', (string) $client->getRequest()->getUri());
        $this->assertSelectorTextNotContains('body', 'Foreign Closure Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=hospital&hospital='.$foreign['hospitalId'].'&period=month&year=2026&month=5');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('hospital='.$own['hospitalId'], (string) $client->getRequest()->getUri());
        self::assertStringContainsString('period=month', (string) $client->getRequest()->getUri());
        $this->assertSelectorTextNotContains('body', 'Foreign Closure Hospital');

        $client->request(Request::METHOD_GET, sprintf(
            '/statistics/closure-analytics?scope=my_hospitals&closureHospitals[]=%d&closureHospitals[]=%d&closureHospitalsSubmitted=1&period=all_time',
            $own['hospitalId'],
            $foreign['hospitalId'],
        ));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-breakdowns"]', 'Own Closure Hospital');
        $this->assertSelectorTextNotContains('body', 'Foreign Closure Hospital');
        $client->request(Request::METHOD_GET, sprintf(
            '/statistics/closure-analytics/duration?scope=my_hospitals&closureHospitals[]=%d&closureHospitals[]=%d&closureHospitalsSubmitted=1&period=all_time',
            $own['hospitalId'],
            $foreign['hospitalId'],
        ));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-closure-duration-median"]', '2 h');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-duration-load"]', 'Foreign Closure Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=my_hospitals&closureHospitalsSubmitted=1&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-empty"]');
        $this->assertSelectorTextNotContains('body', 'Foreign Closure Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/intervals/'.$foreign['intervalId'].'?scope=hospital&hospital='.$own['hospitalId']);
        $this->assertResponseStatusCodeSame(404);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/events/'.rawurlencode('group:'.$foreign['hospitalId'].':foreign-group'));
        $this->assertResponseStatusCodeSame(404);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/details?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Foreign Closure Hospital');
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/timeline/frame?scope=public&period=all_time&timeline_grain=year');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-closure-duration-pauses"]');
        $this->assertSelectorTextNotContains('body', 'Foreign Closure Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/export.csv?scope=public&period=all_time&columns=hospital');
        $this->assertResponseIsSuccessful();
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringContainsString('Own Closure Hospital', $csv);
        self::assertStringNotContainsString('Foreign Closure Hospital', $csv);

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/export.csv?scope=hospital&hospital='.$foreign['hospitalId'].'&period=all_time&columns=hospital');
        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('Foreign Closure Hospital', (string) $client->getInternalResponse()->getContent());
    }

    public function testParticipantWithTwoHospitalsCanSelectEitherOrBoth(): void
    {
        $client = self::createClient();
        $owner = $this->login($client, [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]);
        $first = $this->seedClosure($owner, 'Closure Hospital A', 'group-a');
        $second = $this->seedClosure($owner, 'Closure Hospital B', 'group-b');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=my_hospitals&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Closure Hospital A');
        $this->assertSelectorTextContains('body', 'Closure Hospital B');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-scope-group"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="my_hospitals"][selected]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-hospitals"] option[value="my_hospitals"]', 'My hospitals');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="hospital:'.$first['hospitalId'].'"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="hospital:'.$second['hospitalId'].'"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-hospitals"] option[value="hospital:'.$first['hospitalId'].'"][selected]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-hospitals"][multiple]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"]');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=hospital&hospital='.$first['hospitalId'].'&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Closure Hospital A');
        $this->assertSelectorTextNotContains('[data-testid="stats-closure-breakdowns"]', 'Closure Hospital B');

        $client->request(Request::METHOD_GET, sprintf(
            '/statistics/closure-analytics?scope=my_hospitals&closureHospitals[]=%d&closureHospitals[]=%d&closureHospitalsSubmitted=1&period=all_time',
            $first['hospitalId'],
            $second['hospitalId'],
        ));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Closure Hospital A');
        $this->assertSelectorTextContains('body', 'Closure Hospital B');
    }

    public function testMissingHospitalAssignmentStaysEmpty(): void
    {
        $client = self::createClient();
        $this->login($client, [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]);
        $other = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]]);
        $this->seedClosure($other, 'Hidden Closure Hospital', 'hidden-group');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-no-hospital-access"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-no-hospitals"]');
        $this->assertSelectorTextNotContains('body', 'Hidden Closure Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/timeline?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-no-hospital-access"]');
        $this->assertSelectorTextNotContains('body', 'Hidden Closure Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/events?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-no-hospital-access"]');
        $this->assertSelectorTextNotContains('body', 'Hidden Closure Hospital');
    }

    public function testClosureBetaWithoutParticipantIsRejectedAndAdminCanCrossHospitals(): void
    {
        $client = self::createClient();
        $this->login($client, [UserRole::USER, UserRole::CLOSURE_BETA]);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');
        $this->assertResponseStatusCodeSame(403);
        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/export.csv?scope=public&period=all_time');
        $this->assertResponseStatusCodeSame(403);

        $owner = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        $first = $this->seedClosure($owner, 'Admin Visible Hospital A', 'admin-a');
        $second = $this->seedClosure($owner, 'Admin Visible Hospital B', 'admin-b');
        $this->login($client, [UserRole::USER, UserRole::ADMIN, UserRole::CLOSURE_BETA]);

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('scope=my_hospitals', (string) $client->getRequest()->getUri());
        $this->assertSelectorTextContains('body', 'Admin Visible Hospital A');
        $this->assertSelectorTextContains('body', 'Admin Visible Hospital B');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="my_hospitals"][selected]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-context-hospitals"] option[value="my_hospitals"]', 'All hospitals');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="hospital:'.$first['hospitalId'].'"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-context-hospitals"] option[value="hospital:'.$second['hospitalId'].'"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-scope-group"] option[value="public"]');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=hospital&hospital='.$second['hospitalId'].'&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Admin Visible Hospital B');

        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-scope-group"] option[value="public"]');
        self::assertNotSame($first['hospitalId'], $second['hospitalId']);
    }

    /**
     * @param list<string> $roles
     */
    private function login(KernelBrowser $client, array $roles): User
    {
        $user = UserFactory::createOne(['roles' => $roles]);
        $client->followRedirects(true);
        $client->loginUser($user);

        return $user;
    }

    /**
     * @return array{hospitalId: int, intervalId: int}
     */
    private function seedClosure(User $owner, string $hospitalName, string $groupId): array
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
        $speciality = SpecialityFactory::createOne();
        $department = DepartmentFactory::createOne();
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $owner]);
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('closure_interval', [
            'hospital_id' => $hospital->getId(),
            'import_id' => $import->getId(),
            'speciality_id' => $speciality->getId(),
            'department_id' => $department->getId(),
            'starts_at' => '2026-05-01 10:00:00',
            'ends_at' => '2026-05-01 12:00:00',
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => $hospitalName.' unit',
            'source_group_id' => $groupId,
            'source_recorded_at' => '2026-05-01 09:00:00',
            'source_changed_at' => '2026-05-01 09:00:00',
        ]);

        return [
            'hospitalId' => (int) $hospital->getId(),
            'intervalId' => (int) $connection->fetchOne(
                'SELECT id FROM closure_interval WHERE hospital_id = :hospital_id AND source_group_id = :group_id',
                ['hospital_id' => $hospital->getId(), 'group_id' => $groupId],
            ),
        ];
    }
}
