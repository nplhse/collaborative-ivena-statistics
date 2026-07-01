<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\Analysis\ImportRejectAnalysisService;
use App\Import\Domain\Enum\RejectAnalysisExportFormat;
use App\Import\Infrastructure\Analysis\Export\RejectAnalysisExporterRegistry;
use App\Import\UI\Console\Input\AnalyzeImportRejectsInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'app:import:analyze-rejects',
    description: 'Analyze import rejects grouped by field, value, and reason for transformer planning.',
)]
final readonly class AnalyzeImportRejectsCommand
{
    private const string DEFAULT_OUTPUT_CSV = 'var/export/import-reject-analysis.csv';

    public function __construct(
        private ImportRejectAnalysisService $analysisService,
        private RejectAnalysisExporterRegistry $exporterRegistry,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] AnalyzeImportRejectsInput $input,
    ): int {
        $format = $input->format->value;
        $outputPath = $this->resolveOutputPath($input->output, $format);

        $result = $this->analysisService->analyze(
            minCount: $input->minCount,
            limit: $input->limit,
            includeExamples: $input->includeExamples,
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
                RejectAnalysisExportFormat::Markdown->value => Path::join($this->projectDir, 'var/export/import-reject-analysis.md'),
                RejectAnalysisExportFormat::Json->value => Path::join($this->projectDir, 'var/export/import-reject-analysis.json'),
                default => $output,
            };
        }

        return $output;
    }
}
