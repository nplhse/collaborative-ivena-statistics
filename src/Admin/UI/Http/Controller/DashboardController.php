<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller;

use App\Admin\UI\Http\Controller\Allocation\AllocationCrudController;
use App\Admin\UI\Http\Controller\Assignment\AssignmentCrudController;
use App\Admin\UI\Http\Controller\Department\DepartmentCrudController;
use App\Admin\UI\Http\Controller\DispatchArea\DispatchAreaCrudController;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Admin\UI\Http\Controller\IndicationNormalized\IndicationNormalizedCrudController;
use App\Admin\UI\Http\Controller\IndicationRaw\IndicationRawCrudController;
use App\Admin\UI\Http\Controller\Infection\InfectionCrudController;
use App\Admin\UI\Http\Controller\MciCase\MciCaseCrudController;
use App\Admin\UI\Http\Controller\Occasion\OccasionCrudController;
use App\Admin\UI\Http\Controller\SecondaryTransport\SecondaryTransportCrudController;
use App\Admin\UI\Http\Controller\Speciality\SpecialityCrudController;
use App\Admin\UI\Http\Controller\State\StateCrudController;
use App\Admin\UI\Http\Controller\User\UserCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'app_admin_dashboard')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    #[\Override]
    public function index(): Response
    {
        $url = $this->adminUrlGenerator
            ->setController(UserCrudController::class)
            ->generateUrl();

        return $this->redirect($url);
    }

    #[\Override]
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Admin');
    }

    #[\Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Management');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fas fa-users');
        yield MenuItem::section('Imports');
        yield MenuItem::linkTo(ImportCrudController::class, 'Imports', 'fa fa-database');
        yield MenuItem::linkTo(ImportRejectCrudController::class, 'Import Rejects', 'fas fa-triangle-exclamation');
        yield MenuItem::section('Data');
        yield MenuItem::linkTo(AllocationCrudController::class, 'Allocations', 'fas fa-list');
        yield MenuItem::linkTo(MciCaseCrudController::class, 'MCI Cases', 'fas fa-list');
        yield MenuItem::linkTo(AssignmentCrudController::class, 'Assignments', 'fas fa-list');
        yield MenuItem::linkTo(DepartmentCrudController::class, 'Departments', 'fas fa-list');
        yield MenuItem::linkTo(DispatchAreaCrudController::class, 'Dispatch Areas', 'fas fa-list');
        yield MenuItem::linkTo(HospitalCrudController::class, 'Hospitals', 'fas fa-list');
        yield MenuItem::linkTo(IndicationNormalizedCrudController::class, 'Indication Normalized', 'fas fa-list');
        yield MenuItem::linkTo(IndicationRawCrudController::class, 'Indication Raw', 'fas fa-list');
        yield MenuItem::linkTo(InfectionCrudController::class, 'Infections', 'fas fa-list');
        yield MenuItem::linkTo(OccasionCrudController::class, 'Occasions', 'fas fa-list');
        yield MenuItem::linkTo(SecondaryTransportCrudController::class, 'Secondary Transports', 'fas fa-list');
        yield MenuItem::linkTo(SpecialityCrudController::class, 'Specialities', 'fas fa-list');
        yield MenuItem::linkTo(StateCrudController::class, 'States', 'fas fa-list');
    }
}
