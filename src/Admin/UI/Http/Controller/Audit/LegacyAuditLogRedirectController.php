<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Audit;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The EasyAdmin audit table at /admin/audit-log was replaced by the audit timeline (#955). Old links and
 * bookmarks lead to the timeline or to the entry's permalink. Its filters are not translated.
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/audit-log', name: 'audit_log_legacy')]
final class LegacyAuditLogRedirectController extends AbstractController
{
    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('app_admin_dashboard_audit_timeline', status: Response::HTTP_MOVED_PERMANENTLY);
    }

    #[AdminRoute(path: '/{id}', name: 'detail', options: ['methods' => ['GET'], 'requirements' => ['id' => '\d+']])]
    public function detail(int $id): RedirectResponse
    {
        return $this->redirectToRoute('app_admin_dashboard_audit_entry', ['id' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }
}
