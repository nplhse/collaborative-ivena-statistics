<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Query;

use App\Content\Application\Contract\HelpSearchQueryInterface;
use App\Content\Application\Help\HelpSearchHit;
use App\Content\Domain\Entity\PageTranslation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * PostgreSQL full-text search over the plain text of help articles (#596). The help holds tens to a few hundred
 * articles, so the vectors are built per query; an index can follow when the help grows.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for HelpSearchQueryInterface.
 */
#[AsAlias(HelpSearchQueryInterface::class)]
final readonly class HelpSearchQuery implements HelpSearchQueryInterface
{
    private const int MAX_TERMS = 8;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function search(string $terms, array $pageIds, string $locale, string $defaultLocale): array
    {
        $tsQuery = $this->prefixQuery($terms);
        if (null === $tsQuery || [] === $pageIds) {
            return [];
        }

        $document = "setweight(to_tsvector(d.cfg, d.title), 'A') || setweight(to_tsvector(d.cfg, coalesce(d.summary, '')), 'A') || setweight(to_tsvector(d.cfg, d.search_text), 'B')";
        $headlineOptions = \sprintf(
            'StartSel=%s, StopSel=%s, MaxWords=30, MinWords=15, MaxFragments=2, FragmentDelimiter="%s"',
            HelpSearchHit::MATCH_START,
            HelpSearchHit::MATCH_END,
            HelpSearchHit::PASSAGE_BREAK,
        );

        $rows = $this->connection->fetchAllAssociative(
            'WITH shown AS (
                SELECT DISTINCT ON (t.page_id) t.page_id, t.locale, t.title, t.path, t.summary, t.search_text
                FROM page_translation t
                WHERE t.page_id IN (:pageIds) AND t.status = :published AND t.locale IN (:locale, :defaultLocale)
                ORDER BY t.page_id, (t.locale = :locale) DESC
            ), docs AS (
                SELECT s.*, CAST(CASE WHEN s.locale = \'de\' THEN \'german\' ELSE \'english\' END AS regconfig) AS cfg
                FROM shown s
            )
            SELECT d.page_id, d.title, d.path, d.summary, d.search_text,
                   ts_headline(d.cfg, d.search_text, to_tsquery(d.cfg, :query), :headline) AS excerpt
            FROM docs d
            WHERE ('.$document.') @@ to_tsquery(d.cfg, :query)
            ORDER BY ts_rank('.$document.', to_tsquery(d.cfg, :query)) DESC, d.title ASC
            LIMIT '.self::MAX_RESULTS,
            [
                'pageIds' => array_values(array_unique($pageIds)),
                'published' => PageTranslation::STATUS_PUBLISHED,
                'locale' => $locale,
                'defaultLocale' => $defaultLocale,
                'query' => $tsQuery,
                'headline' => $headlineOptions,
            ],
            ['pageIds' => ArrayParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): HelpSearchHit => new HelpSearchHit(
                (int) $row['page_id'],
                (string) $row['title'],
                (string) $row['path'],
                (string) $row['excerpt'],
                null !== $row['summary'] ? (string) $row['summary'] : null,
                (string) $row['search_text'],
            ),
            $rows,
        );
    }

    /**
     * Words of letters and digits, each as a prefix ("imp" finds "Import"), all required. Everything else is
     * dropped, so the terms cannot carry tsquery syntax.
     */
    private function prefixQuery(string $terms): ?string
    {
        $terms = mb_substr($terms, 0, self::MAX_QUERY_LENGTH);
        if (false === preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($terms), $matches) || [] === $matches[0]) {
            return null;
        }

        $words = \array_slice(array_values(array_unique($matches[0])), 0, self::MAX_TERMS);

        return implode(' & ', array_map(static fn (string $word): string => $word.':*', $words));
    }
}
