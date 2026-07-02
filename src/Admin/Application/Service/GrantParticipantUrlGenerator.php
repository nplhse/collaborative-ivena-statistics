<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class GrantParticipantUrlGenerator
{
    private const int EXPIRATION_SECONDS = 604800;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generate(int $userId): string
    {
        $url = $this->urlGenerator->generate(
            'app_admin_dashboard_user_grant_participant',
            ['id' => $userId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $expires = time() + self::EXPIRATION_SECONDS;
        $separator = str_contains($url, '?') ? '&' : '?';

        return $this->uriSigner->sign($url.$separator.'expires='.$expires);
    }
}
