<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\Analysis\ImportRejectAnalysisService;
use App\Import\Infrastructure\Analysis\Export\RejectAnalysisExporterRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'app:analyze-import-rejects',
    description: 'Analyze import rejects grouped by field, value, and reason for transformer planning.',
)]
final class AnalyzeImportRejectsCommand extends Command
{
    private const string DEFAULT_OUTPUT_CSV = 'var/export/import-reject-analysis.csv';

    public function __construct(
        private readonly ImportRejectAnalysisService $analysisService,
        private readonly RejectAnalysisExporterRegistry $exporterRegistry,
        private readonly Filesystem $filesystem,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format: csv, md, json', 'csv')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file path', self::DEFAULT_OUTPUT_CSV)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit to top N groups after sorting')
            ->addOption('include-examples', null, InputOption::VALUE_NONE, 'Include example raw row JSON in output')
            ->addOption('min-count', null, InputOption::VALUE_REQUIRED, 'Minimum count per group', '1');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = strtolower((string) $input->getOption('format'));
        if (!\in_array($format, ['csv', 'md', 'json'], true)) {
            $io->error('Option --format must be one of: csv, md, json');

            return Command::INVALID;
        }

        $outputPath = $this->resolveOutputPath(
            (string) $input->getOption('output'),
            $format,
        );

        $limitOption = $input->getOption('limit');
        $limit = null !== $limitOption && '' !== $limitOption ? max(1, (int) $limitOption) : null;
        $minCount = max(1, (int) $input->getOption('min-count'));
        $includeExamples = (bool) $input->getOption('include-examples');

        $result = $this->analysisService->analyze(
            minCount: $minCount,
            limit: $limit,
            includeExamples: $includeExamples,
        );

        $this->filesystem->mkdir(Path::getDirectory($outputPath));

        $this->exporterRegistry->get($format)->export($result, $outputPath);

        $io->success(sprintf('Wrote reject analysis to %s', $outputPath));
        $io->listing([
            sprintf('Total rejects: %d', $result->totalRejects),
            sprintf('Distinct groups: %d', $result->distinctGroupCount()),
            sprintf('Format: %s', $format),
        ]);

        return Command::SUCCESS;
    }

    private function resolveOutputPath(string $output, string $format): string
    {
        if (!str_starts_with($output, '/')) {
            $output = Path::join($this->projectDir, $output);
        }

        $defaultCsv = Path::join($this->projectDir, self::DEFAULT_OUTPUT_CSV);
        if ($output === $defaultCsv) {
            return match ($format) {
                'md' => Path::join($this->projectDir, 'var/export/import-reject-analysis.md'),
                'json' => Path::join($this->projectDir, 'var/export/import-reject-analysis.json'),
                default => $output,
            };
        }

        return $output;
    }
}
