<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\AllocationRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Content\Application\Page\PageNavigationTreeBuilder;
use App\Content\Infrastructure\Repository\PageRepository;
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
        private readonly PageRepository $pageRepository,
        private readonly PageNavigationTreeBuilder $pageNavigationTreeBuilder,
    ) {
    }

    #[Route('/', name: 'app_default')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_USER')) {
            $pages = $this->pageRepository->findAllPublishedVisibleToAuthenticatedUser();

            return $this->render('@Content/dashboard/dashboard.html.twig', [
                'recentPosts' => $this->postRepository->findPublishedForIndex(5),
                'pageTree' => $this->pageNavigationTreeBuilder->build($pages),
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
