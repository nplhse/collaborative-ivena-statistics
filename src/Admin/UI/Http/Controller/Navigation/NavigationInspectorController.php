<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Navigation;

use App\Shared\Application\Navigation\Menu\Inspection\NavigationInspector;
use App\User\Infrastructure\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The whole navigation tree with properties, visibility and warnings, for debugging (#1237, ADR 035).
 *
 * Read-only. Query parameters: `user` evaluates permissions for that username instead of the current admin;
 * `route` with `parameters` (`key=value&key=value`) shows the active path of that route in every menu.
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/operations/navigation', name: 'operations_navigation')]
final class NavigationInspectorController extends AbstractController
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly NavigationInspector $inspector,
        private readonly UserRepository $users,
        private readonly Security $security,
    ) {
    }

    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(Request $request): Response
    {
        $username = trim($request->query->getString('user'));
        $user = '' === $username ? null : $this->users->findOneBy(['username' => $username]);

        $route = trim($request->query->getString('route'));
        $parameterQuery = trim($request->query->getString('parameters'));
        parse_str($parameterQuery, $parsed);
        $parameters = [];
        foreach ($parsed as $name => $value) {
            if (\is_string($value)) {
                $parameters[(string) $name] = $value;
            }
        }

        return $this->render('@Admin/operations/navigation/index.html.twig', [
            'inspection' => $this->inspector->inspect(
                null === $user ? null : fn (string $attribute): bool => $this->security->isGrantedForUser($user, $attribute),
                $user?->getUserIdentifier(),
                '' === $route ? null : $route,
                $parameters,
            ),
            'username' => $username,
            'unknown_user' => '' !== $username && null === $user,
            'route' => $route,
            'parameter_query' => $parameterQuery,
        ]);
    }
}
