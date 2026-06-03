<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Content\Application\Page\PageSidebarDataProvider;
use App\Content\Infrastructure\Repository\PostRepository;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DefaultController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly HospitalRepository $hospitalRepository,
        private readonly ImportRepository $importRepository,
        private readonly AllocationRepository $allocationRepository,
        private readonly PostRepository $postRepository,
        private readonly PageSidebarDataProvider $pageSidebarDataProvider,
    ) {
    }

    #[Route('/', name: 'app_default')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_USER')) {
            return $this->render('@Content/dashboard/dashboard.html.twig', [
                'recentPosts' => $this->postRepository->findPublishedForIndex(5),
                ...$this->pageSidebarDataProvider->getData(),
            ]);
        }

        return $this->render('@Content/public/home.html.twig', [
            'userCount' => $this->userRepository->count(),
            'hospitalCount' => $this->hospitalRepository->countParticipating(),
            'importCount' => $this->importRepository->count(),
            'allocationCount' => $this->allocationRepository->count(),
        ]);
    }
}
