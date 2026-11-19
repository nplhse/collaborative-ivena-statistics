<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lets administrators open an Explore detail page by the internal numeric ID (#1354), e.g.
 * `/explore/hospital/40`, and redirects to the canonical URL. Everyone else gets a 404, whether
 * the ID exists or not. The target page applies its own access checks.
 */
final class OpenObjectByIdController extends AbstractController
{
    public function __construct(
        private readonly OpensByIdTargets $targets,
        private readonly EntityManagerInterface $entityManager,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    #[Route(
        '/explore/{type}/{id}',
        name: 'app_explore_object_by_id',
        requirements: ['type' => '[a-z_]+', 'id' => '\d+'],
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(string $type, int $id): RedirectResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }

        $target = $this->targets->find($type) ?? throw $this->createNotFoundException();
        $object = $this->entityManager->find($target->entity, $id) ?? throw $this->createNotFoundException();

        $value = $this->propertyAccessor->getValue($object, $target->parameter);
        $value = $value instanceof \Stringable || \is_string($value) ? (string) $value : '';

        // A digits-only value would land on this route again (legacy usernames, #1354).
        if ('' === $value || ctype_digit($value)) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute($target->route, [$target->parameter => $value]);
    }
}
