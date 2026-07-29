<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Application\Explore\Catalog\CatalogOrientationMapFactory;
use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use App\User\Domain\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowHospitalController extends AbstractController
{
    public function __construct(
        private readonly CatalogOrientationMapFactory $orientationMapFactory,
        private readonly CatalogCoverageQuery $coverageQuery,
        private readonly HospitalPermissionAccess $hospitalPermissionAccess,
    ) {
    }

    #[Route(
        '/explore/hospital/{publicId}',
        name: 'app_explore_hospital_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function index(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Hospital $hospital,
    ): Response {
        $id = $hospital->getId();
        if (null === $id) {
            throw $this->createNotFoundException();
        }

        $dispatchArea = $hospital->getDispatchArea();
        $user = $this->getUser();
        $revealSensitiveMetrics = $user instanceof User
            && $this->hospitalPermissionAccess->hasPermission($user, $id, HospitalPermission::View);

        return $this->render('@Allocation/hospitals/show.html.twig', [
            'hospital' => $hospital,
            'coverage' => $this->coverageQuery->forHospital($id, $revealSensitiveMetrics),
            'orientationMap' => $this->orientationMapFactory->forHospital(
                $dispatchArea?->getName(),
                $hospital->getLatitude(),
                $hospital->getLongitude(),
                $hospital->getName(),
            ),
        ]);
    }
}
