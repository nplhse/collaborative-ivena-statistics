<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
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
final class ClosureAnalyticsVolumeAccessTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RebuildsClosureAnalysis;

    public function testVolumeStaysInsideTheAuthorizedHospital(): void
    {
        $client = self::createClient();
        $owner = $this->login($client, [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]);
        $other = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::CLOSURE_BETA]]);
        $own = $this->seedClosure($owner, 'Own Volume Hospital', 'own-volume');
        $foreign = $this->seedClosure($other, 'Foreign Volume Hospital', 'foreign-volume');
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();
        $own['intervalId'] = $this->analysisIntervalId($own['hospitalId'], 'own-volume');
        $foreign['intervalId'] = $this->analysisIntervalId($foreign['hospitalId'], 'foreign-volume');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics?scope=hospital&hospital='.$own['hospitalId'].'&period=all_time');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-kpi-deviation"]');
        $this->assertSelectorExists('[data-testid="stats-closure-kpi-share"]');
        $this->assertSelectorNotExists('[data-testid="stats-closure-volume"]');
        $this->assertSelectorTextNotContains('body', 'Foreign Volume Hospital');

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/intervals/'.$foreign['intervalId']);
        $this->assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/statistics/closure-analytics/intervals/'.$own['intervalId']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-closure-volume-detail"]');
        $this->assertSelectorExists('[data-testid="stats-closure-volume-windows"]');
        $this->assertSelectorExists('[data-testid="stats-closure-volume-bar-toggles"]');
        $this->assertSelectorTextNotContains('body', 'Foreign Volume Hospital');
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

        $this->rebuildClosureAnalysis();

        return [
            'hospitalId' => (int) $hospital->getId(),
            'intervalId' => $this->analysisIntervalId((int) $hospital->getId(), $groupId),
        ];
    }

    private function analysisIntervalId(int $hospitalId, string $groupId): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT ai.id FROM closure_analysis_interval ai WHERE ai.hospital_id = :hospital_id AND ai.source_group_id = :group_id',
            ['hospital_id' => $hospitalId, 'group_id' => $groupId],
        );
    }
}
