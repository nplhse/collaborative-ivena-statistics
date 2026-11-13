<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalActionRegistry;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsEntry;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsOverviewBuilder;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsScopeResolver;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsSelection;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PARTICIPANT')]
final class ListOwnedHospitalsController extends AbstractController
{
    public function __construct(
        private readonly MyHospitalsScopeResolver $myHospitalsScopeResolver,
        private readonly MyHospitalsOverviewBuilder $myHospitalsOverviewBuilder,
        private readonly MyHospitalActionRegistry $actionRegistry,
    ) {
    }

    #[Route('/hospitals', name: 'app_hospitals_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        $scope = $this->myHospitalsScopeResolver->resolve($user);
        $hospital = $request->query->all()['hospital'] ?? null;
        $selection = MyHospitalsSelection::fromPublicId($scope, \is_string($hospital) ? $hospital : null);
        $hospitalIds = array_values(array_filter(array_map(
            static fn (MyHospitalsEntry $entry): ?int => $entry->hospital->getId(),
            $selection->selected()->entries,
        )));

        return $this->render('@Allocation/hospitals/owned_list.html.twig', [
            'scope' => $scope,
            'selection' => $selection,
            'overview' => $scope->isEmpty() ? null : $this->myHospitalsOverviewBuilder->build($selection->selected(), (int) $user->getId()),
            'hospital_actions' => $this->actionRegistry->actionsFor($hospitalIds),
        ]);
    }
}
