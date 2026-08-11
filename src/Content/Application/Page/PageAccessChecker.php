<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class PageAccessChecker
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Security $security,
    ) {
    }

    public function canView(Page $page, ?PageTranslation $translation = null): bool
    {
        if ($translation instanceof PageTranslation) {
            if (!$translation->isPublished()) {
                return false;
            }
        } elseif (!$page->hasPublishedTranslation()) {
            return false;
        }

        if (Page::VISIBILITY_PUBLIC === $page->getVisibility()) {
            return true;
        }

        if (Page::VISIBILITY_AUTHENTICATED === $page->getVisibility()) {
            return $this->security->isGranted('ROLE_USER');
        }

        return false;
    }
}
