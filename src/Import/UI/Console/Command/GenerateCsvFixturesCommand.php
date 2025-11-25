<?php

namespace App\Import\UI\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:generate-csv-fixtures',
    description: 'Generate CSV encoding fixtures (dev/test only).',
)]
final class GenerateCsvFixturesCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!\in_array($this->kernel->getEnvironment(), ['dev', 'test'], true)) {
            $io->error(sprintf('Forbidden in "%s" environment.', $this->kernel->getEnvironment()));

            return Command::FAILURE;
        }

        $dir = $this->kernel->getProjectDir().'/tests/Fixtures/csv';
        $this->fs->mkdir($dir);

        $csv = <<<CSV
Straße;Straße;KHS-Versorgungsgebiet/Bezirk?;Ärztlich-Begleitet
Neue Straße;Neue Straße;Leitstelle Nord/1;ja
Neue Straße;Neue Straße;Leitstelle Nord/1;ja

CSV;

        $w = static fn (string $name, string $bytes): int|false => file_put_contents($dir.'/'.$name, $bytes);

        $w('utf8.csv', $csv);
        $w('utf8_bom.csv', "\xEF\xBB\xBF".$csv);

        $converted = iconv('UTF-8', 'ISO-8859-1', $csv);
        if (false === $converted) {
            throw new \RuntimeException('iconv failed to convert CSV to ISO-8859-1');
        }
        $w('iso8859_1.csv', $converted);

        $io->success("Fixtures written to $dir");

        return Command::SUCCESS;
    }
}
