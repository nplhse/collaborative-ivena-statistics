<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\User\Application\Contract\AdminUserDetailUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(AdminUserDetailUrlGeneratorInterface::class)]
final readonly class AdminUserUrlGenerator implements AdminUserDetailUrlGeneratorInterface
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function detailUrl(int $userId): string
    {
        return $this->urlGenerator->generate(
            'app_admin_dashboard_user_detail',
            ['entityId' => $userId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
