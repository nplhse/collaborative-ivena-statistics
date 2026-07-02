<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller;

use App\Admin\Application\Service\OperationalDashboardService;
use App\Admin\Infrastructure\Repository\MessengerStatsRepository;
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
use App\Admin\UI\Http\Controller\Engagement\MonthlyReminderDispatchCrudController;
use App\Admin\UI\Http\Controller\Feedback\FeedbackCrudController;
use App\Admin\UI\Http\Controller\Hospital\HospitalAccessGrantCrudController;
use App\Admin\UI\Http\Controller\Hospital\HospitalCrudController;
use App\Admin\UI\Http\Controller\Import\ImportBatchRunCrudController;
use App\Admin\UI\Http\Controller\Import\ImportBatchRunItemCrudController;
use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Admin\UI\Http\Controller\IndicationGroup\IndicationGroupCrudController;
use App\Admin\UI\Http\Controller\IndicationNormalized\IndicationNormalizedCrudController;
use App\Admin\UI\Http\Controller\IndicationRaw\IndicationRawCrudController;
use App\Admin\UI\Http\Controller\Infection\InfectionCrudController;
use App\Admin\UI\Http\Controller\MciCase\MciCaseCrudController;
use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use App\Admin\UI\Http\Controller\Occasion\OccasionCrudController;
use App\Admin\UI\Http\Controller\Onboarding\UserOnboardingStepCrudController;
use App\Admin\UI\Http\Controller\Page\PageCrudController;
use App\Admin\UI\Http\Controller\SecondaryTransport\SecondaryTransportCrudController;
use App\Admin\UI\Http\Controller\Speciality\SpecialityCrudController;
use App\Admin\UI\Http\Controller\State\StateCrudController;
use App\Admin\UI\Http\Controller\Statistics\SavedExplorerViewCrudController;
use App\Admin\UI\Http\Controller\User\UserCrudController;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\Kpi\Application\Service\KpiDashboardService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[AdminDashboard(routePath: '/admin', routeName: 'app_admin_dashboard')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly FeedbackRepository $feedbackRepository,
        private readonly KpiDashboardService $kpiDashboardService,
        private readonly OperationalDashboardService $operationalDashboardService,
        private readonly MessengerStatsRepository $messengerStatsRepository,
    ) {
    }

    #[\Override]
    public function index(): Response
    {
        return $this->render('@Admin/dashboard/index.html.twig', [
            'kpi_cards' => $this->kpiDashboardService->getCards(),
            'kpi_chart' => $this->kpiDashboardService->getChart(),
            'failed_imports' => $this->kpiDashboardService->getFailedImportsDashboard(),
            'ops_cards' => $this->operationalDashboardService->getOpsCards(),
            'ops_health' => $this->operationalDashboardService->getHealthStatus(),
            'recent_notifications' => $this->operationalDashboardService->getRecentNotificationEvents(),
            'dashboard_sections' => $this->buildDashboardSections(),
        ]);
    }

    #[\Override]
    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addAssetMapperEntry(Asset::new('admin-kpi'));
    }

    #[AdminRoute(path: '/operations/failed-messages', name: 'operations_failed_messages')]
    public function failedMessagesIndex(): Response
    {
        return $this->render('@Admin/operations/failed_messages_index.html.twig', [
            'messages' => $this->messengerStatsRepository->findFailedMessages(),
            'total_count' => $this->messengerStatsRepository->countFailedMessages(),
        ]);
    }

    #[AdminRoute(path: '/operations/failed-messages/delete-selected', name: 'operations_failed_messages_delete_selected', options: ['methods' => ['POST']])]
    public function deleteSelectedFailedMessages(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('failed_messages_delete_batch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var list<int|string> $rawIds */
        $rawIds = $request->request->all('ids');
        $ids = array_values(array_filter(array_map(
            static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
            $rawIds,
        )));

        if ([] === $ids) {
            $this->addFlash('warning', new TranslatableMessage('ops.failed_messages.flash.none_selected', domain: 'admin'));

            return $this->redirectToFailedMessagesIndex();
        }

        $deleted = $this->messengerStatsRepository->deleteFailedMessagesByIds($ids);
        $this->addFlash('success', new TranslatableMessage('ops.failed_messages.flash.deleted_many', ['%count%' => $deleted], 'admin'));

        return $this->redirectToFailedMessagesIndex();
    }

    #[AdminRoute(path: '/operations/failed-messages/delete-all', name: 'operations_failed_messages_delete_all', options: ['methods' => ['POST']])]
    public function deleteAllFailedMessages(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('failed_messages_delete_all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $deleted = $this->messengerStatsRepository->deleteAllFailedMessages();
        $this->addFlash('success', new TranslatableMessage('ops.failed_messages.flash.deleted_all', ['%count%' => $deleted], 'admin'));

        return $this->redirectToFailedMessagesIndex();
    }

    #[AdminRoute(path: '/operations/failed-messages/{id}', name: 'operations_failed_messages_detail', options: ['requirements' => ['id' => '\d+']])]
    public function failedMessagesDetail(int $id): Response
    {
        $message = $this->messengerStatsRepository->findFailedMessageById($id);
        if (null === $message) {
            throw new NotFoundHttpException('Failed message not found.');
        }

        return $this->render('@Admin/operations/failed_messages_detail.html.twig', [
            'message' => $message,
            'back_url' => $this->adminUrlGenerator
                ->setRoute('app_admin_dashboard_operations_failed_messages')
                ->generateUrl(),
            'delete_url' => $this->adminUrlGenerator
                ->setRoute('app_admin_dashboard_operations_failed_messages_delete', ['id' => $id])
                ->generateUrl(),
        ]);
    }

    #[AdminRoute(path: '/operations/failed-messages/{id}/delete', name: 'operations_failed_messages_delete', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function deleteFailedMessage(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('failed_message_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$this->messengerStatsRepository->deleteFailedMessageById($id)) {
            throw new NotFoundHttpException('Failed message not found.');
        }

        $this->addFlash('success', new TranslatableMessage('ops.failed_messages.flash.deleted_one', domain: 'admin'));

        return $this->redirectToFailedMessagesIndex();
    }

    private function redirectToFailedMessagesIndex(): RedirectResponse
    {
        return $this->redirect(
            $this->adminUrlGenerator
                ->setRoute('app_admin_dashboard_operations_failed_messages')
                ->generateUrl(),
        );
    }

    /**
     * @return list<array{title: string, tiles: list<array{label: string, description: string|null, icon: string, url: string, badge: array{value: int, style: string}|null}>}>
     */
    private function buildDashboardSections(): array
    {
        $openFeedbackCount = $this->feedbackRepository->countOpen();

        $sections = $this->dashboardSections();
        $built = [];

        foreach ($sections as $section) {
            $tiles = [];

            foreach ($section['tiles'] as $tile) {
                $badge = null;
                if (isset($tile['badge_key']) && 'feedback' === $tile['badge_key']) {
                    $badge = [
                        'value' => $openFeedbackCount,
                        'style' => $openFeedbackCount > 0 ? 'info' : 'primary',
                    ];
                }

                if (isset($tile['route'])) {
                    $url = $this->adminUrlGenerator->setRoute($tile['route'])->generateUrl();
                } elseif (isset($tile['controller'])) {
                    $url = $this->adminUrlGenerator->setController($tile['controller'])->generateUrl();
                } else {
                    throw new \LogicException('Dashboard tile must define either route or controller.');
                }

                $tiles[] = [
                    'label' => $tile['label'],
                    'description' => $tile['description'] ?? null,
                    'icon' => $tile['icon'],
                    'url' => $url,
                    'badge' => $badge,
                ];
            }

            $built[] = [
                'title' => $section['title'],
                'tiles' => $tiles,
            ];
        }

        return $built;
    }

    /**
     * @return list<array{title: string, tiles: list<array{label: string, description?: string, icon: string, controller?: class-string, route?: string, badge_key?: string}>}>
     */
    private function dashboardSections(): array
    {
        return [
            [
                'title' => 'Management',
                'tiles' => [
                    [
                        'label' => 'Feedback',
                        'description' => 'Review and respond to user feedback',
                        'icon' => 'fas fa-comment-dots',
                        'controller' => FeedbackCrudController::class,
                        'badge_key' => 'feedback',
                    ],
                    [
                        'label' => 'Onboarding steps',
                        'description' => 'Review user onboarding progress',
                        'icon' => 'fas fa-shoe-prints',
                        'controller' => UserOnboardingStepCrudController::class,
                    ],
                    [
                        'label' => 'Users',
                        'description' => 'Manage user accounts and permissions',
                        'icon' => 'fas fa-users',
                        'controller' => UserCrudController::class,
                    ],
                ],
            ],
            [
                'title' => 'Data',
                'tiles' => [
                    [
                        'label' => 'Data',
                        'description' => 'Reference data and allocations',
                        'icon' => 'fas fa-layer-group',
                        'controller' => AllocationCrudController::class,
                    ],
                    [
                        'label' => 'Import Rejects',
                        'description' => 'Review failed import records',
                        'icon' => 'fas fa-triangle-exclamation',
                        'controller' => ImportRejectCrudController::class,
                    ],
                    [
                        'label' => 'Imports',
                        'description' => 'View import history and status',
                        'icon' => 'fa fa-database',
                        'controller' => ImportCrudController::class,
                    ],
                ],
            ],
            [
                'title' => 'Content',
                'tiles' => [
                    [
                        'label' => 'Blog',
                        'description' => 'Manage blog posts and related content',
                        'icon' => 'fas fa-book',
                        'controller' => PostCrudController::class,
                    ],
                    [
                        'label' => 'Pages',
                        'description' => 'Edit static pages and site content',
                        'icon' => 'fas fa-file',
                        'controller' => PageCrudController::class,
                    ],
                    [
                        'label' => 'Media',
                        'description' => 'Upload images and PDFs for pages and blog',
                        'icon' => 'fas fa-photo-film',
                        'controller' => MediaCrudController::class,
                    ],
                ],
            ],
            [
                'title' => 'System',
                'tiles' => [
                    [
                        'label' => 'Audit log',
                        'description' => 'Browse system audit entries',
                        'icon' => 'fas fa-clipboard-list',
                        'controller' => AuditLogCrudController::class,
                    ],
                    [
                        'label' => 'Cookie consents',
                        'description' => 'View recorded cookie consent choices',
                        'icon' => 'fas fa-cookie-bite',
                        'controller' => CookieConsentCrudController::class,
                    ],
                    [
                        'label' => 'Failed messages',
                        'description' => 'Inspect messenger failed queue',
                        'icon' => 'fas fa-triangle-exclamation',
                        'route' => 'app_admin_dashboard_operations_failed_messages',
                    ],
                    [
                        'label' => 'Monthly reminders',
                        'description' => 'Scheduler reminder dispatch history',
                        'icon' => 'fas fa-envelope',
                        'controller' => MonthlyReminderDispatchCrudController::class,
                    ],
                ],
            ],
        ];
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
        yield MenuItem::subMenu('Users', 'fas fa-users')->setSubItems([
            MenuItem::linkTo(UserCrudController::class, new TranslatableMessage('menu.users.accounts', domain: 'admin'), 'fas fa-user'),
            MenuItem::linkTo(HospitalAccessGrantCrudController::class, 'Hospital Access', 'fas fa-key'),
            MenuItem::linkTo(UserOnboardingStepCrudController::class, 'Onboarding steps', 'fas fa-shoe-prints'),
        ]);

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
            MenuItem::linkTo(IndicationGroupCrudController::class, 'Indication Groups', 'fas fa-object-group'),
            MenuItem::linkTo(IndicationRawCrudController::class, 'Indication Raw', 'fas fa-list'),
            MenuItem::linkTo(InfectionCrudController::class, 'Infections', 'fas fa-list'),
            MenuItem::linkTo(MciCaseCrudController::class, 'MCI Cases', 'fas fa-list'),
            MenuItem::linkTo(OccasionCrudController::class, 'Occasions', 'fas fa-list'),
            MenuItem::linkTo(SecondaryTransportCrudController::class, 'Secondary Transports', 'fas fa-list'),
            MenuItem::linkTo(SpecialityCrudController::class, 'Specialities', 'fas fa-list'),
            MenuItem::linkTo(StateCrudController::class, 'States', 'fas fa-list'),
        ]);
        yield MenuItem::subMenu('Imports', 'fa fa-database')->setSubItems([
            MenuItem::linkTo(ImportCrudController::class, 'Imports', 'fa fa-database'),
            MenuItem::linkTo(ImportRejectCrudController::class, 'Import Rejects', 'fas fa-triangle-exclamation'),
            MenuItem::subMenu('Batch Activities', 'fas fa-layer-group')->setSubItems([
                MenuItem::linkTo(ImportBatchRunCrudController::class, 'Import batch runs', 'fas fa-layer-group'),
                MenuItem::linkTo(ImportBatchRunItemCrudController::class, 'Import batch items', 'fas fa-list-check'),
            ]),
        ]);
        yield MenuItem::linkTo(SavedExplorerViewCrudController::class, 'Explorer views', 'fas fa-chart-bar');

        yield MenuItem::section('Content');
        yield MenuItem::subMenu('Blog', 'fas fa-book')->setSubItems([
            MenuItem::linkTo(PostCrudController::class, new TranslatableMessage('menu.blog.posts', domain: 'shared'), 'fas fa-align-left'),
            MenuItem::linkTo(PostCategoryCrudController::class, new TranslatableMessage('menu.blog.categories', domain: 'shared'), 'fas fa-folder'),
            MenuItem::linkTo(PostCommentCrudController::class, new TranslatableMessage('menu.blog.comments', domain: 'shared'), 'fas fa-comments'),
            MenuItem::linkTo(PostTagCrudController::class, new TranslatableMessage('menu.blog.tags', domain: 'shared'), 'fas fa-tags'),
        ]);
        yield MenuItem::linkTo(PageCrudController::class, new TranslatableMessage('label.pages', domain: 'content'), 'fas fa-file');
        yield MenuItem::linkTo(MediaCrudController::class, new TranslatableMessage('label.media_library', domain: 'content'), 'fas fa-photo-film');

        yield MenuItem::section('System');
        yield MenuItem::linkTo(AuditLogCrudController::class, 'Audit log', 'fas fa-clipboard-list');
        yield MenuItem::linkTo(CookieConsentCrudController::class, 'Cookie consents', 'fas fa-cookie-bite');
        yield MenuItem::linkToRoute('Failed messages', 'fas fa-triangle-exclamation', 'app_admin_dashboard_operations_failed_messages');
        yield MenuItem::linkTo(MonthlyReminderDispatchCrudController::class, 'Monthly reminders', 'fas fa-envelope');
    }
}
