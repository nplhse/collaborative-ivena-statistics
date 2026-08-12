<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller\Explore;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Explore\UserProfileFactory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class ShowUserController extends AbstractController
{
    public function __construct(
        private readonly UserProfileFactory $userProfileFactory,
    ) {
    }

    #[Route(
        '/explore/user/{publicId}',
        name: 'app_explore_user_show',
        requirements: ['publicId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] User $profileUser,
    ): Response {
        if (!$profileUser->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $viewer = $this->getUser();
        $profile = $this->userProfileFactory->create(
            $profileUser,
            $viewer instanceof User ? $viewer : null,
        );

        return $this->render('@User/explore/users/show.html.twig', [
            'profile' => $profile,
        ]);
    }
}
