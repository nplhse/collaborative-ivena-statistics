<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Adapter;

use App\Content\Application\Page\PageTranslationResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Repository\PageRepository;
use App\User\Application\Contract\TermsDocumentProviderInterface;
use App\User\Application\Terms\TermsDocument;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The published terms of use page (`PageKey::Terms`) for the acceptance records of #1564. The hash covers the
 * published block content only, with object keys sorted, so it changes with the text and not with page metadata.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for TermsDocumentProviderInterface.
 */
#[AsAlias(TermsDocumentProviderInterface::class)]
final readonly class DoctrineTermsDocumentProvider implements TermsDocumentProviderInterface
{
    public function __construct(
        private PageRepository $pageRepository,
        private PageTranslationResolver $translationResolver,
    ) {
    }

    #[\Override]
    public function current(string $locale): ?TermsDocument
    {
        $page = $this->pageRepository->findOneBy(['key' => PageKey::Terms]);
        if (!$page instanceof Page) {
            return null;
        }

        $translation = $this->translationResolver->resolveForDisplay($page, $locale);
        if (!$translation instanceof PageTranslation) {
            return null;
        }

        return new TermsDocument(
            (string) $translation->getLocale(),
            self::contentHash($translation->getContent()),
        );
    }

    /** @param array<array-key, mixed> $content */
    public static function contentHash(array $content): string
    {
        return hash('sha256', json_encode(self::sortKeys($content), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
