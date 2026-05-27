<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller;

use App\Admin\UI\Http\Controller\Allocation\AllocationCrudController;
use App\Admin\UI\Http\Controller\Assignment\AssignmentCrudController;
use App\Admin\UI\Http\Controller\Audit\AuditLogCrudController;
use App\Admin\UI\Http\Controller\Blog\PostCategoryCrudController;
use App\Admin\UI\Http\Controller\Blog\PostCommentCrudController;
use App\Admin\UI\Http\Controller\Blog\PostCrudController;
use App\Admin\UI\Http\Controller\Blog\PostTagCrudController;
use App\Admin\UI\Http\Controller\Consent\CookieConsentCrudController;
use App\Admin\UI\Http\Controller\Department\DepartmentCrudController;
use App\Admin\UI\Http\Controller\DispatchArea\DispatchAreaCrudController;
use App\Admin\UI\Http\Controller\Feedback\FeedbackCrudController;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Admin\UI\Http\Controller\IndicationNormalized\IndicationNormalizedCrudController;
use App\Admin\UI\Http\Controller\IndicationRaw\IndicationRawCrudController;
use App\Admin\UI\Http\Controller\Infection\InfectionCrudController;
use App\Admin\UI\Http\Controller\MciCase\MciCaseCrudController;
use App\Admin\UI\Http\Controller\Occasion\OccasionCrudController;
use App\Admin\UI\Http\Controller\Page\PageCrudController;
use App\Admin\UI\Http\Controller\SecondaryTransport\SecondaryTransportCrudController;
use App\Admin\UI\Http\Controller\Speciality\SpecialityCrudController;
use App\Admin\UI\Http\Controller\State\StateCrudController;
use App\Admin\UI\Http\Controller\User\UserCrudController;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'app_admin_dashboard')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly FeedbackRepository $feedbackRepository,
    ) {
    }

    #[\Override]
    public function index(): RedirectResponse
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
        yield MenuItem::linkToRoute('Back to frontend', 'fas fa-backward-fast', 'app_default');
        yield MenuItem::section('Management');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fas fa-users');

        $openFeedbackCount = $this->feedbackRepository->countOpen();
        yield MenuItem::linkTo(FeedbackCrudController::class, 'Feedback', 'fas fa-comment-dots')
            ->setBadge($openFeedbackCount, $openFeedbackCount > 0 ? 'info' : 'primary');

        yield MenuItem::section('Data');
        yield MenuItem::subMenu('Data', 'fas fa-layer-group')->setSubItems([
            MenuItem::linkTo(AllocationCrudController::class, 'Allocations', 'fas fa-list'),
            MenuItem::linkTo(AssignmentCrudController::class, 'Assignments', 'fas fa-list'),
            MenuItem::linkTo(DepartmentCrudController::class, 'Departments', 'fas fa-list'),
            MenuItem::linkTo(DispatchAreaCrudController::class, 'Dispatch Areas', 'fas fa-list'),
            MenuItem::linkTo(HospitalCrudController::class, 'Hospitals', 'fas fa-list'),
            MenuItem::linkTo(IndicationNormalizedCrudController::class, 'Indication Normalized', 'fas fa-list'),
            MenuItem::linkTo(IndicationRawCrudController::class, 'Indication Raw', 'fas fa-list'),
            MenuItem::linkTo(InfectionCrudController::class, 'Infections', 'fas fa-list'),
            MenuItem::linkTo(MciCaseCrudController::class, 'MCI Cases', 'fas fa-list'),
            MenuItem::linkTo(OccasionCrudController::class, 'Occasions', 'fas fa-list'),
            MenuItem::linkTo(SecondaryTransportCrudController::class, 'Secondary Transports', 'fas fa-list'),
            MenuItem::linkTo(SpecialityCrudController::class, 'Specialities', 'fas fa-list'),
            MenuItem::linkTo(StateCrudController::class, 'States', 'fas fa-list'),
        ]);
        yield MenuItem::linkTo(ImportRejectCrudController::class, 'Import Rejects', 'fas fa-triangle-exclamation');
        yield MenuItem::linkTo(ImportCrudController::class, 'Imports', 'fa fa-database');

        yield MenuItem::section('Content');
        yield MenuItem::subMenu('Blog', 'fas fa-book')->setSubItems([
            MenuItem::linkTo(PostCrudController::class, 'menu.blog.posts', 'fas fa-align-left'),
            MenuItem::linkTo(PostCategoryCrudController::class, 'menu.blog.categories', 'fas fa-folder'),
            MenuItem::linkTo(PostCommentCrudController::class, 'menu.blog.comments', 'fas fa-comments'),
            MenuItem::linkTo(PostTagCrudController::class, 'menu.blog.tags', 'fas fa-tags'),
        ]);
        yield MenuItem::linkTo(PageCrudController::class, 'Pages', 'fas fa-file');

        yield MenuItem::section('System');
        yield MenuItem::linkTo(AuditLogCrudController::class, 'Audit log', 'fas fa-clipboard-list');
        yield MenuItem::linkTo(CookieConsentCrudController::class, 'Cookie consents', 'fas fa-cookie-bite');
    }
}
