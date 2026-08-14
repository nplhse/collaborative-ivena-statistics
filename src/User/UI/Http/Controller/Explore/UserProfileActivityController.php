<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller\Explore;

use App\User\Application\Explore\ProfileActivityCursor;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Query\UserProfileActivityQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/** @psalm-suppress UnusedClass */
final class UserProfileActivityController extends AbstractController
{
    public function __construct(
        private readonly UserProfileActivityQuery $activityQuery,
    ) {
    }

    #[Route(
        '/explore/user/{publicId}/activity',
        name: 'app_explore_user_activity',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] User $profileUser,
    ): Response {
        if (!$profileUser->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $rawCursor = $request->query->get('cursor');
        $cursor = \is_string($rawCursor) && '' !== $rawCursor ? $rawCursor : null;
        $page = $this->activityQuery->getPage($profileUser, $cursor);

        $frameId = null !== $cursor
            ? ProfileActivityCursor::frameId($cursor)
            : 'profile-activity-after-start';

        return $this->render('@User/explore/users/_activity_page.html.twig', [
            'profilePublicId' => $profileUser->getPublicIdString(),
            'page' => $page,
            'frameId' => $frameId,
        ]);
    }
}
