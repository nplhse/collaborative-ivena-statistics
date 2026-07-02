<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AdminUserUrlGenerator
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
