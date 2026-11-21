<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Application\Contract\MediaUrlRewriterInterface;
use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Application\Media\MediaUrlRewriteResult;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Rewrites old media URLs in blog posts and in the content and draft of pages (ADR 039).
 *
 * Only URLs whose old file name belongs to a media item that has moved to the storage are replaced; everything else
 * is listed and left alone, so a second run changes nothing. The rewrite is a technical migration, not an edit: it
 * changes neither `updated_at` nor the audit log, and runs per table in one transaction. A URL keeps its host, query
 * and fragment; only the path changes.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for MediaUrlRewriterInterface.
 */
#[AsAlias(MediaUrlRewriterInterface::class)]
final readonly class MediaUrlRewriter implements MediaUrlRewriterInterface
{
    /** Old URL path; the name ends where HTML, CSS, a query or a fragment begins. */
    private const string LEGACY_URL_PATTERN = '#/uploads/media/([^"\'\s<>?\#)\\\\]+)#';

    /** JSON columns are written as Doctrine's JSON type writes them. */
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function rewrite(bool $dryRun): MediaUrlRewriteResult
    {
        $urls = $this->movedUrls();
        $rewritten = 0;
        $changed = ['post' => 0, 'page' => 0, 'page draft' => 0];
        $unresolved = [];

        $replace = function (string $text) use ($urls, &$rewritten, &$unresolved): string {
            return (string) preg_replace_callback(self::LEGACY_URL_PATTERN, static function (array $match) use ($urls, &$rewritten, &$unresolved): string {
                $name = rawurldecode($match[1]);
                if (!isset($urls[$name])) {
                    $unresolved[$name] = true;

                    return $match[0];
                }

                ++$rewritten;

                return $urls[$name];
            }, $text);
        };

        $this->inTransaction($dryRun, function () use ($replace, &$changed): void {
            foreach ($this->connection->fetchAllAssociative("SELECT id, content FROM post WHERE content LIKE '%/uploads/media/%'") as $row) {
                $content = (string) $row['content'];
                $new = $replace($content);
                if ($new !== $content) {
                    ++$changed['post'];
                    $this->connection->update('post', ['content' => $new], ['id' => $row['id']]);
                }
            }
        });

        $this->inTransaction($dryRun, function () use ($replace, &$changed): void {
            $rows = $this->connection->fetchAllAssociative("SELECT id, content::text AS content, draft_content::text AS draft_content FROM page_translation WHERE content::text LIKE '%uploads%media%' OR draft_content::text LIKE '%uploads%media%'");
            foreach ($rows as $row) {
                $update = [];
                foreach (['content' => 'page', 'draft_content' => 'page draft'] as $column => $label) {
                    if (!\is_string($row[$column])) {
                        continue;
                    }

                    $data = json_decode($row[$column], true, 512, \JSON_THROW_ON_ERROR);
                    $new = $this->walk($data, $replace);
                    if ($new !== $data) {
                        ++$changed[$label];
                        $update[$column] = json_encode($new, self::JSON_FLAGS);
                    }
                }

                if ([] !== $update) {
                    $this->connection->update('page_translation', $update, ['id' => $row['id']]);
                }
            }
        });

        $unresolvedNames = array_keys($unresolved);
        sort($unresolvedNames);

        return new MediaUrlRewriteResult($rewritten, array_filter($changed), array_map(strval(...), $unresolvedNames));
    }

    /**
     * New URL per old file name, for every moved media item.
     *
     * @return array<string, string>
     */
    private function movedUrls(): array
    {
        $urls = [];
        foreach ($this->connection->fetchAllAssociative('SELECT legacy_filename, storage_key FROM media WHERE legacy_filename IS NOT NULL AND storage_key IS NOT NULL') as $row) {
            $key = (string) $row['storage_key'];
            if (str_starts_with($key, 'originals/')) {
                $urls[(string) $row['legacy_filename']] = MediaPublicUrlResolver::URI_PREFIX.substr($key, \strlen('originals/'));
            }
        }

        return $urls;
    }

    /**
     * @param callable(string): string $replace
     */
    private function walk(mixed $value, callable $replace): mixed
    {
        if (\is_string($value)) {
            return $replace($value);
        }

        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->walk($item, $replace), $value);
        }

        return $value;
    }

    /**
     * @param callable(): void $work
     */
    private function inTransaction(bool $dryRun, callable $work): void
    {
        $this->connection->beginTransaction();
        try {
            $work();
            $dryRun ? $this->connection->rollBack() : $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }
}
