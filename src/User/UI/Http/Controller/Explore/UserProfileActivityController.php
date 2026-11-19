<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller\Explore;

use App\User\Application\Contract\PublishedPostPreviewMapInterface;
use App\User\Application\Explore\PostPublishedActivitySlugs;
use App\User\Application\Explore\ProfileActivityCursor;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\UserProfileActivityQuery;
use App\User\Infrastructure\Security\Voter\UserProfileVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass */
final class UserProfileActivityController extends AbstractController
{
    public function __construct(
        private readonly ProfileUserLookup $profileUsers,
        private readonly UserProfileActivityQuery $activityQuery,
        private readonly PublishedPostPreviewMapInterface $postPreviews,
    ) {
    }

    #[Route(
        '/explore/user/{username}/activity',
        name: 'app_explore_user_activity',
        requirements: ['username' => ProfileUserLookup::ROUTE_REQUIREMENT],
        options: ['utf8' => true],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        string $username,
    ): Response {
        $profileUser = $this->profileUsers->find($username);
        if (!$profileUser instanceof User || !$profileUser->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(UserProfileVoter::VIEW, $profileUser);

        $canonical = $profileUser->getUserIdentifier();
        if ($canonical !== $username) {
            return $this->redirectToRoute(
                'app_explore_user_activity',
                [...$request->query->all(), 'username' => $canonical],
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }

        $rawCursor = $request->query->get('cursor');
        $cursor = \is_string($rawCursor) && '' !== $rawCursor ? $rawCursor : null;
        $page = $this->activityQuery->getPage($profileUser, $cursor);

        $frameId = null !== $cursor
            ? ProfileActivityCursor::frameId($cursor)
            : 'profile-activity-after-start';

        return $this->render('@User/explore/users/_activity_page.html.twig', [
            'profileUsername' => $canonical,
            'page' => $page,
            'previews' => $this->postPreviews->forSlugs(PostPublishedActivitySlugs::from($page->items)),
            'frameId' => $frameId,
        ]);
    }
}
