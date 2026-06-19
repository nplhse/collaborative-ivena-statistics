<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterInputFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsFilterInputFactoryTest extends DatabaseKernelTestCase
{
    private StatisticsFilterInputFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(StatisticsFilterInputFactory::class);
    }

    public function testDefaultScopeIsPublicForUserWithoutHospitalAccess(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $input = $this->factory->fromQuery($this->queryBag([]), $user);

        self::assertSame('public', $input->scope);
        self::assertFalse($input->hasScopeQueryParameter);
    }

    public function testDefaultScopeIsMyHospitalsForParticipantWithOwnedHospital(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $input = $this->factory->fromQuery($this->queryBag([]), $user);

        self::assertSame('my_hospitals', $input->scope);
    }

    public function testDefaultScopeIsPublicForHospitalDataSourceAnalysis(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $request = Request::create('/statistics/analytics/view/allocations_by_month', Request::METHOD_GET, [
            'ga_data_source' => 'hospitals',
        ]);

        $input = $this->factory->fromRequest($request, $user);

        self::assertSame('public', $input->scope);
        self::assertFalse($input->hasScopeQueryParameter);
    }

    public function testExplicitMyHospitalsScopeIsPreservedForHospitalDataSource(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $request = Request::create('/statistics/analytics/builder', Request::METHOD_GET, [
            'ga_data_source' => 'hospitals',
            'scope' => 'my_hospitals',
        ]);

        $input = $this->factory->fromRequest($request, $user);

        self::assertSame('my_hospitals', $input->scope);
        self::assertTrue($input->hasScopeQueryParameter);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return InputBag<string>
     */
    private function queryBag(array $parameters): InputBag
    {
        return (new Request($parameters))->query;
    }
}
