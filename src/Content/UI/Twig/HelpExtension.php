<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Help\HelpNavigation;
use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageType;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions of the help section (#596).
 *
 * @psalm-suppress UnusedClass Registered through #[AsTwigFunction].
 */
final readonly class HelpExtension
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private HelpNavigation $helpNavigation,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Main topics of the help with their articles, as the viewer sees them.
     *
     * @return list<PageNavigationNode>
     *
     * @psalm-suppress PossiblyUnusedMethod Called from Twig.
     */
    #[AsTwigFunction('help_topics')]
    public function topics(): array
    {
        return $this->helpNavigation->topics();
    }

    /**
     * URL of the help article with this help key, for "Need help?" links in the application. Null when there is no
     * such article or the viewer cannot open it, so a template can leave the link out.
     *
     * @psalm-suppress PossiblyUnusedMethod Called from Twig.
     */
    #[AsTwigFunction('help_link')]
    public function link(string $helpKey): ?string
    {
        $page = $this->entityManager->getRepository(Page::class)->findOneBy(['helpKey' => $helpKey, 'type' => PageType::Help]);
        if (!$page instanceof Page) {
            return null;
        }

        foreach ($this->helpNavigation->articles() as $article) {
            if ($article->pageId === $page->getId()) {
                return $article->url;
            }
        }

        return null;
    }
}
