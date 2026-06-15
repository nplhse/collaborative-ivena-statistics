<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\UI\Http\Controller\User\UserCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AdminUserUrlGenerator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AdminUrlGeneratorInterface $adminUrlGenerator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function detailUrl(int $userId): string
    {
        $relativeUrl = $this->adminUrlGenerator
            ->setController(UserCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($userId)
            ->generateUrl();

        $query = parse_url($relativeUrl, PHP_URL_QUERY);
        $params = [];
        if (\is_string($query) && '' !== $query) {
            parse_str($query, $params);
        }

        return $this->urlGenerator->generate(
            'app_admin_dashboard',
            $params,
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
