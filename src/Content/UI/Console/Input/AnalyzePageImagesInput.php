<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Input;

use Symfony\Component\Console\Attribute\Option;

final class AnalyzePageImagesInput
{
    #[Option(description: 'Only report findings without writing changes (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Apply selected write operations')]
    public bool $apply = false;

    #[Option(description: 'Backfill missing media width/height from local files', name: 'backfill-dimensions')]
    public bool $backfillDimensions = false;

    #[Option(description: 'Migrate image blocks from size lg to auto when recommended', name: 'migrate-size')]
    public bool $migrateSize = false;

    #[Option(description: 'Replace --size-lg with --size-auto in HTML snippets', name: 'fix-richtext-snippets')]
    public bool $fixRichtextSnippets = false;

    #[Option(description: 'Analyze or migrate a single page', name: 'page-id')]
    public ?string $pageId = null;
}
