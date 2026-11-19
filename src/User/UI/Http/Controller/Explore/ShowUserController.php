<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller\Explore;

use App\User\Application\Contract\PublishedPostPreviewMapInterface;
use App\User\Application\Explore\PostPublishedActivitySlugs;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Explore\UserProfileFactory;
use App\User\Infrastructure\Security\Voter\UserProfileVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowUserController extends AbstractController
{
    public function __construct(
        private readonly ProfileUserLookup $profileUsers,
        private readonly UserProfileFactory $userProfileFactory,
        private readonly PublishedPostPreviewMapInterface $postPreviews,
    ) {
    }

    #[Route(
        '/explore/user/{username}',
        name: 'app_explore_user_show',
        requirements: ['username' => ProfileUserLookup::ROUTE_REQUIREMENT],
        options: ['utf8' => true],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $username): Response
    {
        $profileUser = $this->profileUsers->find($username);
        if (!$profileUser instanceof User || !$profileUser->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(UserProfileVoter::VIEW, $profileUser);

        $canonical = $profileUser->getUserIdentifier();
        if ($canonical !== $username) {
            return $this->redirectToRoute(
                'app_explore_user_show',
                [...$request->query->all(), 'username' => $canonical],
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $viewer = $this->getUser();
        $profile = $this->userProfileFactory->create(
            $profileUser,
            $viewer instanceof User ? $viewer : null,
        );

        return $this->render('@User/explore/users/show.html.twig', [
            'profile' => $profile,
            'previews' => $this->postPreviews->forSlugs(PostPublishedActivitySlugs::from($profile->activities)),
        ]);
    }
}
