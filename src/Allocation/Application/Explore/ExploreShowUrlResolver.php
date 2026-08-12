<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ExploreShowUrlResolver
{
    /**
     * @var array<class-string, string>
     */
    private const array ROUTES = [
        Allocation::class => 'app_explore_allocation_show',
        Assignment::class => 'app_explore_assignment_show',
        Department::class => 'app_explore_department_show',
        DispatchArea::class => 'app_explore_dispatch_area_show',
        Hospital::class => 'app_explore_hospital_show',
        IndicationGroup::class => 'app_explore_indication_group_show',
        IndicationNormalized::class => 'app_explore_indication_show',
        Infection::class => 'app_explore_infection_show',
        MciCase::class => 'app_explore_mci_case_show',
        Occasion::class => 'app_explore_occasion_show',
        SecondaryTransport::class => 'app_explore_secondary_transport_show',
        Speciality::class => 'app_explore_speciality_show',
        State::class => 'app_explore_state_show',
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function resolveUrl(?object $entity): ?string
    {
        if (null === $entity) {
            return null;
        }

        $route = $this->routeFor($entity);
        if (null === $route) {
            return null;
        }

        $publicId = $this->publicIdFor($entity);
        if (null === $publicId) {
            return null;
        }

        return $this->urlGenerator->generate($route, ['publicId' => $publicId]);
    }

    private function routeFor(object $entity): ?string
    {
        $class = $entity::class;
        if (isset(self::ROUTES[$class])) {
            return self::ROUTES[$class];
        }

        $parents = class_parents($class);
        if (false === $parents) {
            return null;
        }

        foreach ($parents as $parent) {
            if (isset(self::ROUTES[$parent])) {
                return self::ROUTES[$parent];
            }
        }

        return null;
    }

    private function publicIdFor(object $entity): ?string
    {
        if (!method_exists($entity, 'getPublicId')) {
            return null;
        }

        $publicId = $entity->getPublicId();
        if (!$publicId instanceof Uuid) {
            return null;
        }

        return $publicId->toRfc4122();
    }
}
