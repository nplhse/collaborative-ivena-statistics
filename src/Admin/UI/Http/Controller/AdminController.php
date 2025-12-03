<?php

namespace App\Admin\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted("ROLE_ADMIN")]
final class AdminController extends AbstractController
{
    public function __construct(
    ) {
    }

    #[Route('/admin/', name: 'app_admin_dashboard')]
    public function __invoke(): Response
    {
        return $this->render('@Admin/dashboard/index.html.twig', []);
    }
}
