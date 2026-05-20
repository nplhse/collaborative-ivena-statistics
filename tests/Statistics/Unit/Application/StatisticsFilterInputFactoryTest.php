<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterInputFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class StatisticsFilterInputFactoryTest extends KernelTestCase
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
        $input = $this->factory->fromQuery(new InputBag([]), $user);

        self::assertSame('public', $input->scope);
        self::assertFalse($input->hasScopeQueryParameter);
    }

    public function testDefaultScopeIsMyHospitalsForParticipantWithOwnedHospital(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);

        $input = $this->factory->fromQuery(new InputBag([]), $user);

        self::assertSame('my_hospitals', $input->scope);
    }
}
