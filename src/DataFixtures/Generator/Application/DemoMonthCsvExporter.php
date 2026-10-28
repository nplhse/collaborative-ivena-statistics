<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Infrastructure\Csv\IvenaAllocationCsv;
use App\DataFixtures\Generator\Infrastructure\Csv\IvenaClosureCsv;
use App\DataFixtures\Generator\Infrastructure\Csv\IvenaCsvFile;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use App\DataFixtures\Pattern\Application\PatternLookupResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Writes one synthetic month of a hospital as two IVENA files, allocations and closures, for the demo
 * upload of #791. The rows are the ones `HospitalMonthGenerator` would write for that month; the files go
 * through the normal upload, parser and projections instead of being written into the database.
 */
final readonly class DemoMonthCsvExporter
{
    public const string FILE_PREFIX = 'synthetic-demo';

    public function __construct(
        private HospitalMonthGenerator $generator,
        private HospitalMonthSampler $sampler,
        private PatternLookupResolver $lookups,
        private Connection $connection,
    ) {
    }

    /**
     * The month after the hospital's newest allocation or closure; without any data, the last complete month.
     *
     * @return string month as Y-m
     */
    public function defaultMonth(Hospital $hospital, \DateTimeImmutable $lastDay): string
    {
        $newest = $this->connection->fetchOne(
            'SELECT GREATEST(
                (SELECT MAX(created_at) FROM allocation WHERE hospital_id = :hospital),
                (SELECT MAX(starts_at) FROM closure_interval WHERE hospital_id = :hospital))',
            ['hospital' => (int) $hospital->getId()],
        );

        if (\is_string($newest)) {
            return new \DateTimeImmutable($newest)->modify('first day of next month')->format('Y-m');
        }

        return $lastDay->modify('+1 day')->modify('first day of previous month')->format('Y-m');
    }

    /**
     * @param string $month month as Y-m
     *
     * @throws \DomainException               when the month is not complete by the last day, or already has data for the hospital
     * @throws InvalidPatternPackageException when the pattern package of the volume is invalid
     */
    public function export(FixtureVolume $volume, Hospital $hospital, string $month, \DateTimeImmutable $lastDay, string $directory): DemoMonthCsvFiles
    {
        $monthEnd = ImportBatchPlanner::firstDay($month)->modify('last day of this month');
        if ($monthEnd > $lastDay) {
            throw new \DomainException(sprintf('%s ends after %s; only complete months can be exported.', $month, $lastDay->format('Y-m-d')));
        }

        $batch = $this->generator->plan($volume, $hospital, $month, $lastDay);
        $dispatchArea = $hospital->getDispatchArea();
        if (!$dispatchArea instanceof DispatchArea) {
            throw new \DomainException(sprintf('%s needs a dispatch area for an IVENA file.', (string) $hospital->getName()));
        }

        $name = (string) $hospital->getName();
        $address = $hospital->getAddress();
        $hospitalKey = $hospital->getPublicIdString();
        $closures = $this->sampler->closures($volume, $hospital, $batch, $lastDay);

        $base = sprintf('%s/%s_%s_%s', rtrim($directory, '/'), self::FILE_PREFIX, strtolower(new AsciiSlugger()->slug($name)->toString()), $month);
        $allocationsPath = $base.'_allocations.csv';
        $closuresPath = $base.'_closures.csv';

        $allocationCsv = new IvenaAllocationCsv(
            (string) $dispatchArea->getName(),
            sprintf('%s, %s, %s %s', $name, $address->getStreet(), $address->getPostalCode(), $address->getCity()),
            $name,
            sprintf('SYNTH-%d-%s-%s', $volume->seed, substr($hospitalKey, 0, 8), $month),
        );
        $allocations = IvenaCsvFile::write($allocationsPath, IvenaAllocationCsv::HEADER, (function () use ($volume, $hospital, $batch, $closures, $allocationCsv): \Generator {
            $number = 0;
            foreach ($this->sampler->allocations($volume, $hospital, $batch, $closures) as $allocation) {
                yield $allocationCsv->row($allocation, ++$number);
            }
        })());

        $closureCsv = new IvenaClosureCsv($name);
        $closureCount = IvenaCsvFile::write($closuresPath, IvenaClosureCsv::HEADER, (function () use ($closures, $closureCsv): \Generator {
            foreach ($closures as $closure) {
                $department = $this->lookups->reference(Department::class, $closure->department);
                yield $closureCsv->row($closure, (string) $this->lookups->specialityFor($closure->department)->getName(), (string) $department->getName());
            }
        })());

        return new DemoMonthCsvFiles($month, $allocationsPath, $allocations, $closuresPath, $closureCount);
    }
}
