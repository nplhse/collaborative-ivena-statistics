<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Command;

use App\Content\Application\Contract\MediaUrlRewriterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replaces old media URLs in posts and pages with the URLs of the moved files (ADR 039). Previews by default; --apply
 * writes. Run once after the release with the media storage is accepted (upgrade guide); it changes nothing when every
 * URL is current. Old file names that belong to no moved media item are listed and keep their URL, which the redirect
 * serves until v0.4.
 */
#[AsCommand(
    name: 'app:media:rewrite-content-urls',
    description: 'Replace old /uploads/media URLs in posts and pages with the URLs of the moved media files.',
)]
final readonly class RewriteMediaContentUrlsCommand
{
    private const int LIST_LIMIT = 20;

    public function __construct(
        private MediaUrlRewriterInterface $rewriter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write the replacements (without it, only show what would be replaced)', name: 'apply')]
        bool $apply = false,
    ): int {
        $dryRun = !$apply;
        $io->title('Rewrite old media URLs in content');

        $result = $this->rewriter->rewrite($dryRun);

        $rows = [[$dryRun ? 'URLs that would be replaced' : 'URLs replaced', (string) $result->rewrittenUrls]];
        foreach ($result->changedRecords as $record => $count) {
            $rows[] = [ucfirst($record).' records', (string) $count];
        }
        $io->table(['', 'Count'], $rows);

        if ([] !== $result->unresolved) {
            $lines = array_map(static fn (string $name): string => '- '.$name, \array_slice($result->unresolved, 0, self::LIST_LIMIT));
            if (\count($result->unresolved) > self::LIST_LIMIT) {
                $lines[] = sprintf('… and %d more', \count($result->unresolved) - self::LIST_LIMIT);
            }
            $io->note(['These old media URLs belong to no moved media item and were kept:', ...$lines]);
        }

        $io->success(match (true) {
            $dryRun => 'Preview finished; nothing was changed. Run the command with --apply to replace the URLs.',
            0 === $result->rewrittenUrls => 'No old media URL left to replace.',
            default => sprintf('%d URL(s) replaced.', $result->rewrittenUrls),
        });

        return Command::SUCCESS;
    }
}
