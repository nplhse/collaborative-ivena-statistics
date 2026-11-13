<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller;

use App\Admin\Application\Service\Messenger\MessengerMonitoringPeriod;
use App\Admin\Application\Service\Messenger\MessengerMonitoringService;
use App\Admin\Application\Service\OperationalDashboardService;
use App\Admin\Infrastructure\Repository\MessengerStatsRepository;
use App\Admin\UI\Http\Controller\Allocation\AllocationCrudController;
use App\Admin\UI\Http\Controller\Assignment\AssignmentCrudController;
use App\Admin\UI\Http\Controller\Blog\PostCategoryCrudController;
use App\Admin\UI\Http\Controller\Blog\PostCommentCrudController;
use App\Admin\UI\Http\Controller\Blog\PostCrudController;
use App\Admin\UI\Http\Controller\Blog\PostTagCrudController;
use App\Admin\UI\Http\Controller\Consent\CookieConsentCrudController;
use App\Admin\UI\Http\Controller\Department\DepartmentCrudController;
use App\Admin\UI\Http\Controller\DispatchArea\DispatchAreaCrudController;
use App\Admin\UI\Http\Controller\Engagement\MonthlyReminderDispatchCrudController;
use App\Admin\UI\Http\Controller\Feedback\FeedbackCrudController;
use App\Admin\UI\Http\Controller\Feedback\FeedbackQuarantineCrudController;
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
use App\Admin\UI\Http\Controller\JobRun\JobRunCrudController;
use App\Admin\UI\Http\Controller\MciCase\MciCaseCrudController;
use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use App\Admin\UI\Http\Controller\Occasion\OccasionCrudController;
use App\Admin\UI\Http\Controller\Onboarding\UserOnboardingStepCrudController;
use App\Admin\UI\Http\Controller\Page\PageCrudController;
use App\Admin\UI\Http\Controller\Page\PageTranslationCrudController;
use App\Admin\UI\Http\Controller\SecondaryTransport\SecondaryTransportCrudController;
use App\Admin\UI\Http\Controller\Speciality\SpecialityCrudController;
use App\Admin\UI\Http\Controller\State\StateCrudController;
use App\Admin\UI\Http\Controller\Statistics\SavedExplorerViewCrudController;
use App\Admin\UI\Http\Controller\User\UserCrudController;
use App\Analytics\Application\Service\AccessTrendService;
use App\Analytics\Application\Service\AnalyticsDashboardService;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\Kpi\Application\Service\KpiDashboardService;
use App\Storage\Application\View\StorageReportService;
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
        private readonly AnalyticsDashboardService $analyticsDashboardService,
        private readonly StorageReportService $storageReportService,
        private readonly MessengerMonitoringService $messengerMonitoringService,
        private readonly AccessTrendService $accessTrendService,
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
            'ops_storage' => $this->operationalDashboardService->getStorageSummary(),
            'ops_storage_url' => $this->adminUrlGenerator->setRoute('app_admin_dashboard_operations_storage')->generateUrl(),
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

    #[AdminRoute(path: '/operations/messenger', name: 'operations_messenger')]
    public function messenger(Request $request): Response
    {
        return $this->render('@Admin/operations/messenger/index.html.twig', [
            'report' => $this->messengerMonitoringService->report(MessengerMonitoringPeriod::fromQuery($request->query->getString('period'))),
            'periods' => MessengerMonitoringPeriod::cases(),
        ]);
    }

    #[AdminRoute(path: '/operations/storage', name: 'operations_storage')]
    public function storage(): Response
    {
        return $this->render('@Admin/operations/storage/index.html.twig', [
            'disk_usage' => $this->storageReportService->diskUsage(),
            'imports_url' => $this->adminUrlGenerator->setController(ImportCrudController::class)->generateUrl(),
            'media_url' => $this->adminUrlGenerator->setController(MediaCrudController::class)->generateUrl(),
        ]);
    }

    #[AdminRoute(path: '/operations/usage-analytics', name: 'operations_usage_analytics')]
    public function usageAnalytics(): RedirectResponse
    {
        return $this->redirectToRoute('app_admin_dashboard_operations_usage_analytics_overview');
    }

    #[AdminRoute(path: '/operations/usage-analytics/overview', name: 'operations_usage_analytics_overview')]
    public function usageAnalyticsOverview(): Response
    {
        return $this->render('@Admin/operations/usage_analytics/overview.html.twig', [
            'overview' => $this->analyticsDashboardService->getOverview(),
        ]);
    }

    #[AdminRoute(path: '/operations/usage-analytics/accesses', name: 'operations_usage_analytics_accesses')]
    public function usageAnalyticsAccesses(): Response
    {
        return $this->render('@Admin/operations/usage_analytics/accesses.html.twig', [
            'trend' => $this->accessTrendService->getTrend(),
        ]);
    }

    #[AdminRoute(path: '/operations/usage-analytics/performance', name: 'operations_usage_analytics_performance')]
    public function usageAnalyticsPerformance(): Response
    {
        return $this->render('@Admin/operations/usage_analytics/performance.html.twig', [
            'performance' => $this->analyticsDashboardService->getPerformance(),
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
     * @return list<array{title: string|TranslatableMessage, tiles: list<array{label: string|TranslatableMessage, description: string|TranslatableMessage|null, icon: string, url: string, badge: array{value: int, style: string}|null}>}>
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
     * @return list<array{title: string|TranslatableMessage, tiles: list<array{label: string|TranslatableMessage, description?: string|TranslatableMessage, icon: string, controller?: class-string, route?: string, badge_key?: string}>}>
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
                'title' => new TranslatableMessage('usage_analytics.menu.section', domain: 'admin'),
                'tiles' => [
                    [
                        'label' => new TranslatableMessage('usage_analytics.menu.overview', domain: 'admin'),
                        'description' => new TranslatableMessage('usage_analytics.tile.overview', domain: 'admin'),
                        'icon' => 'fas fa-gauge-high',
                        'route' => 'app_admin_dashboard_operations_usage_analytics_overview',
                    ],
                    [
                        'label' => new TranslatableMessage('usage_analytics.menu.accesses', domain: 'admin'),
                        'description' => new TranslatableMessage('usage_analytics.tile.accesses', domain: 'admin'),
                        'icon' => 'fas fa-chart-column',
                        'route' => 'app_admin_dashboard_operations_usage_analytics_accesses',
                    ],
                    [
                        'label' => new TranslatableMessage('usage_analytics.menu.performance', domain: 'admin'),
                        'description' => new TranslatableMessage('usage_analytics.tile.performance', domain: 'admin'),
                        'icon' => 'fas fa-tachometer-alt',
                        'route' => 'app_admin_dashboard_operations_usage_analytics_performance',
                    ],
                ],
            ],
            [
                'title' => 'System',
                'tiles' => [
                    [
                        'label' => new TranslatableMessage('audit.timeline.title', domain: 'admin'),
                        'description' => new TranslatableMessage('audit.timeline.tile', domain: 'admin'),
                        'icon' => 'fas fa-clipboard-list',
                        'route' => 'app_admin_dashboard_audit_timeline',
                    ],
                    [
                        'label' => 'Cookie consents',
                        'description' => 'View recorded cookie consent choices',
                        'icon' => 'fas fa-cookie-bite',
                        'controller' => CookieConsentCrudController::class,
                    ],
                    [
                        'label' => 'Disk usage',
                        'description' => 'Storage by category, location and history',
                        'icon' => 'fas fa-hard-drive',
                        'route' => 'app_admin_dashboard_operations_storage',
                    ],
                    [
                        'label' => 'Messenger',
                        'description' => 'Message handling, queues, retries and failures',
                        'icon' => 'fas fa-tower-broadcast',
                        'route' => 'app_admin_dashboard_operations_messenger',
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
        yield MenuItem::linkToDashboard(new TranslatableMessage('menu.dashboard', domain: 'admin'), 'fa fa-home');
        yield MenuItem::linkToRoute(new TranslatableMessage('menu.back_to_frontend', domain: 'admin'), 'fas fa-backward-fast', 'app_default');
        yield MenuItem::section(new TranslatableMessage('menu.section.management', domain: 'admin'));
        yield MenuItem::subMenu(new TranslatableMessage('menu.users', domain: 'admin'), 'fas fa-users')->setSubItems([
            MenuItem::linkTo(UserCrudController::class, new TranslatableMessage('menu.users.accounts', domain: 'admin'), 'fas fa-user'),
            MenuItem::linkTo(HospitalAccessGrantCrudController::class, new TranslatableMessage('menu.users.hospital_access', domain: 'admin'), 'fas fa-key'),
            MenuItem::linkTo(UserOnboardingStepCrudController::class, new TranslatableMessage('menu.users.onboarding_steps', domain: 'admin'), 'fas fa-shoe-prints'),
        ]);

        $openFeedbackCount = $this->feedbackRepository->countOpen();
        yield MenuItem::linkTo(FeedbackCrudController::class, new TranslatableMessage('menu.feedback', domain: 'admin'), 'fas fa-comment-dots')
            ->setBadge($openFeedbackCount, $openFeedbackCount > 0 ? 'info' : 'primary');
        // Count only: quarantined content is shown only after the reviewer opens the quarantine.
        yield MenuItem::linkTo(FeedbackQuarantineCrudController::class, new TranslatableMessage('admin.feedback_quarantine.menu', domain: 'admin'), 'fas fa-shield-halved')
            ->setBadge($this->feedbackRepository->countQuarantined(), 'secondary');

        yield MenuItem::section(new TranslatableMessage('menu.section.data', domain: 'admin'));
        yield MenuItem::subMenu(new TranslatableMessage('menu.data', domain: 'admin'), 'fas fa-layer-group')->setSubItems([
            MenuItem::linkTo(AllocationCrudController::class, new TranslatableMessage('menu.data.allocations', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(AssignmentCrudController::class, new TranslatableMessage('menu.data.assignments', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(DepartmentCrudController::class, new TranslatableMessage('menu.data.departments', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(DispatchAreaCrudController::class, new TranslatableMessage('menu.data.dispatch_areas', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(HospitalCrudController::class, new TranslatableMessage('menu.data.hospitals', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(IndicationNormalizedCrudController::class, new TranslatableMessage('menu.data.indications_normalized', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(IndicationGroupCrudController::class, new TranslatableMessage('menu.data.indication_groups', domain: 'admin'), 'fas fa-object-group'),
            MenuItem::linkTo(IndicationRawCrudController::class, new TranslatableMessage('menu.data.indications_raw', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(InfectionCrudController::class, new TranslatableMessage('menu.data.infections', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(MciCaseCrudController::class, new TranslatableMessage('menu.data.mci_cases', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(OccasionCrudController::class, new TranslatableMessage('menu.data.occasions', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(SecondaryTransportCrudController::class, new TranslatableMessage('menu.data.secondary_transports', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(SpecialityCrudController::class, new TranslatableMessage('menu.data.specialities', domain: 'admin'), 'fas fa-list'),
            MenuItem::linkTo(StateCrudController::class, new TranslatableMessage('menu.data.states', domain: 'admin'), 'fas fa-list'),
        ]);
        yield MenuItem::subMenu(new TranslatableMessage('menu.imports', domain: 'admin'), 'fa fa-database')->setSubItems([
            MenuItem::linkTo(ImportCrudController::class, new TranslatableMessage('menu.imports.imports', domain: 'admin'), 'fa fa-database'),
            MenuItem::linkTo(ImportRejectCrudController::class, new TranslatableMessage('menu.imports.rejects', domain: 'admin'), 'fas fa-triangle-exclamation'),
            MenuItem::subMenu(new TranslatableMessage('menu.imports.batch', domain: 'admin'), 'fas fa-layer-group')->setSubItems([
                MenuItem::linkTo(ImportBatchRunCrudController::class, new TranslatableMessage('menu.imports.batch_runs', domain: 'admin'), 'fas fa-layer-group'),
                MenuItem::linkTo(ImportBatchRunItemCrudController::class, new TranslatableMessage('menu.imports.batch_items', domain: 'admin'), 'fas fa-list-check'),
            ]),
        ]);
        yield MenuItem::linkTo(SavedExplorerViewCrudController::class, new TranslatableMessage('menu.explorer_views', domain: 'admin'), 'fas fa-chart-bar');

        yield MenuItem::section(new TranslatableMessage('menu.section.content', domain: 'admin'));
        yield MenuItem::subMenu(new TranslatableMessage('menu.blog', domain: 'admin'), 'fas fa-book')->setSubItems([
            MenuItem::linkTo(PostCrudController::class, new TranslatableMessage('menu.blog.posts', domain: 'shared'), 'fas fa-align-left'),
            MenuItem::linkTo(PostCategoryCrudController::class, new TranslatableMessage('menu.blog.categories', domain: 'shared'), 'fas fa-folder'),
            MenuItem::linkTo(PostCommentCrudController::class, new TranslatableMessage('menu.blog.comments', domain: 'shared'), 'fas fa-comments'),
            MenuItem::linkTo(PostTagCrudController::class, new TranslatableMessage('menu.blog.tags', domain: 'shared'), 'fas fa-tags'),
        ]);
        yield MenuItem::linkTo(PageCrudController::class, new TranslatableMessage('label.pages', domain: 'content'), 'fas fa-file');
        yield MenuItem::linkTo(PageTranslationCrudController::class, new TranslatableMessage('label.page_translations', domain: 'content'), 'fas fa-language');
        yield MenuItem::linkTo(MediaCrudController::class, new TranslatableMessage('label.media_library', domain: 'content'), 'fas fa-photo-film');

        yield MenuItem::section(new TranslatableMessage('usage_analytics.menu.section', domain: 'admin'));
        yield MenuItem::linkToRoute(new TranslatableMessage('usage_analytics.menu.overview', domain: 'admin'), 'fas fa-gauge-high', 'app_admin_dashboard_operations_usage_analytics_overview');
        yield MenuItem::linkToRoute(new TranslatableMessage('usage_analytics.menu.accesses', domain: 'admin'), 'fas fa-chart-column', 'app_admin_dashboard_operations_usage_analytics_accesses');
        yield MenuItem::linkToRoute(new TranslatableMessage('usage_analytics.menu.performance', domain: 'admin'), 'fas fa-tachometer-alt', 'app_admin_dashboard_operations_usage_analytics_performance');

        yield MenuItem::section(new TranslatableMessage('menu.section.system', domain: 'admin'));
        yield MenuItem::linkToRoute(new TranslatableMessage('audit.timeline.title', domain: 'admin'), 'fas fa-clipboard-list', 'app_admin_dashboard_audit_timeline');
        yield MenuItem::linkTo(CookieConsentCrudController::class, new TranslatableMessage('menu.system.cookie_consents', domain: 'admin'), 'fas fa-cookie-bite');
        yield MenuItem::linkToRoute(new TranslatableMessage('storage.menu.disk_usage', domain: 'storage'), 'fas fa-hard-drive', 'app_admin_dashboard_operations_storage');
        yield MenuItem::linkToRoute(new TranslatableMessage('ops.messenger.menu', domain: 'admin'), 'fas fa-tower-broadcast', 'app_admin_dashboard_operations_messenger');
        yield MenuItem::linkToRoute(new TranslatableMessage('menu.system.failed_messages', domain: 'admin'), 'fas fa-triangle-exclamation', 'app_admin_dashboard_operations_failed_messages');
        yield MenuItem::linkTo(JobRunCrudController::class, new TranslatableMessage('admin.job_run.menu', domain: 'admin'), 'fas fa-stopwatch');
        yield MenuItem::linkTo(MonthlyReminderDispatchCrudController::class, new TranslatableMessage('menu.system.monthly_reminders', domain: 'admin'), 'fas fa-envelope');
    }
}
