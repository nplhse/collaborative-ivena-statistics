<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller\Explore;

use App\User\Application\Explore\UserQueryParameters;
use App\User\Infrastructure\Explore\UserDirectoryFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/user', name: 'app_explore_user_list', methods: ['GET'])]
final class ListUsersController extends AbstractController
{
    public function __construct(
        private readonly UserDirectoryFactory $userDirectoryFactory,
    ) {
    }

    public function __invoke(
        #[MapQueryString] UserQueryParameters $query,
    ): Response {
        $page = $this->userDirectoryFactory->create($query);

        $activeFilterCount = 0;
        if ($query->wantsParticipant()) {
            ++$activeFilterCount;
        }
        if ($query->wantsBoardMember()) {
            ++$activeFilterCount;
        }
        if (null !== $query->hospitalId && $query->hospitalId > 0) {
            ++$activeFilterCount;
        }

        return $this->render('@User/explore/users/list.html.twig', [
            'paginator' => $page->paginator,
            'items' => $page->items,
            'hospitalChoices' => $page->hospitalChoices,
            'pagination_route' => 'app_explore_user_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'filters' => $query,
            'activeFilterCount' => $activeFilterCount,
        ]);
    }
}
