<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore;

use App\Allocation\Application\Explore\ExploreShowUrlResolver;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class ExploreShowUrlResolverTest extends TestCase
{
    private const string PUBLIC_ID = '11111111-1111-4111-8111-111111111111';

    #[DataProvider('supportedEntityProvider')]
    public function testResolveUrlForSupportedEntities(object $entity, string $expectedRoute, string $expectedPath): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with($expectedRoute, ['publicId' => self::PUBLIC_ID])
            ->willReturn($expectedPath);

        $resolver = new ExploreShowUrlResolver($urlGenerator);

        self::assertSame($expectedPath, $resolver->resolveUrl($entity));
    }

    public function testResolveUrlReturnsNullForNullEntity(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $resolver = new ExploreShowUrlResolver($urlGenerator);

        self::assertNull($resolver->resolveUrl(null));
    }

    public function testResolveUrlReturnsNullForUnsupportedEntity(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $raw = new IndicationRaw();
        $raw->setPublicId(Uuid::fromString(self::PUBLIC_ID));

        $resolver = new ExploreShowUrlResolver($urlGenerator);

        self::assertNull($resolver->resolveUrl($raw));
    }

    public function testResolveUrlReturnsNullWhenPublicIdIsMissing(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $resolver = new ExploreShowUrlResolver($urlGenerator);

        self::assertNull($resolver->resolveUrl(new Department()));
    }

    public function testResolveUrlFollowsParentClassForDoctrineProxies(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_explore_department_show', ['publicId' => self::PUBLIC_ID])
            ->willReturn('/explore/department/'.self::PUBLIC_ID);

        $proxy = new class extends Department {};
        $proxy->setPublicId(Uuid::fromString(self::PUBLIC_ID));

        $resolver = new ExploreShowUrlResolver($urlGenerator);

        self::assertSame('/explore/department/'.self::PUBLIC_ID, $resolver->resolveUrl($proxy));
    }

    public function testResolveUrlReturnsNullForUnknownClass(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $resolver = new ExploreShowUrlResolver($urlGenerator);

        self::assertNull($resolver->resolveUrl(new \stdClass()));
    }

    /**
     * @return iterable<string, array{object, string, string}>
     */
    public static function supportedEntityProvider(): iterable
    {
        yield 'allocation' => [self::withPublicId(new Allocation()), 'app_explore_allocation_show', '/explore/allocation/'.self::PUBLIC_ID];
        yield 'assignment' => [self::withPublicId(new Assignment()), 'app_explore_assignment_show', '/explore/assignment/'.self::PUBLIC_ID];
        yield 'department' => [self::withPublicId(new Department()), 'app_explore_department_show', '/explore/department/'.self::PUBLIC_ID];
        yield 'dispatch area' => [self::withPublicId(new DispatchArea()), 'app_explore_dispatch_area_show', '/explore/dispatch_area/'.self::PUBLIC_ID];
        yield 'hospital' => [self::withPublicId(new Hospital()), 'app_explore_hospital_show', '/explore/hospital/'.self::PUBLIC_ID];
        yield 'indication group' => [self::withPublicId(new IndicationGroup()), 'app_explore_indication_group_show', '/explore/indication_group/'.self::PUBLIC_ID];
        yield 'indication' => [self::withPublicId(new IndicationNormalized()), 'app_explore_indication_show', '/explore/indication/'.self::PUBLIC_ID];
        yield 'infection' => [self::withPublicId(new Infection()), 'app_explore_infection_show', '/explore/infection/'.self::PUBLIC_ID];
        yield 'mci case' => [self::withPublicId(new MciCase()), 'app_explore_mci_case_show', '/explore/mci_case/'.self::PUBLIC_ID];
        yield 'occasion' => [self::withPublicId(new Occasion()), 'app_explore_occasion_show', '/explore/occasion/'.self::PUBLIC_ID];
        yield 'secondary transport' => [self::withPublicId(new SecondaryTransport()), 'app_explore_secondary_transport_show', '/explore/secondary_transport/'.self::PUBLIC_ID];
        yield 'speciality' => [self::withPublicId(new Speciality()), 'app_explore_speciality_show', '/explore/speciality/'.self::PUBLIC_ID];
        yield 'state' => [self::withPublicId(new State()), 'app_explore_state_show', '/explore/state/'.self::PUBLIC_ID];
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private static function withPublicId(object $entity): object
    {
        if (!method_exists($entity, 'setPublicId')) {
            throw new \LogicException(sprintf('%s is missing setPublicId.', $entity::class));
        }

        $entity->setPublicId(Uuid::fromString(self::PUBLIC_ID));

        return $entity;
    }
}
