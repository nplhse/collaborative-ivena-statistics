<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Faker\Provider\PzcCodes;
use App\Import\Application\Factory\AllocationImporterFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Adapter\SplCsvRejectWriter;
use App\Import\Infrastructure\Adapter\SplCsvRowReader;
use App\Import\Infrastructure\Adapter\SplCsvStreamFactory;
use App\Import\Infrastructure\Charset\EncodingDetector;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * An IVENA file written only with synthetic catalog values imports against the synthetic catalog (ADR 020).
 *
 * The rows use official PZC wordings, two generated label variants (mapped to their normalized
 * indication) and a label the catalog leaves unmapped. Hospital, variants and the unmapped label are
 * read from the loaded catalog, so the test does not depend on the generated values. Base for the
 * CSV round trip of #791.
 */
#[ResetDatabase]
final class SyntheticCatalogCsvImportTest extends KernelTestCase
{
    private const string TEMPLATE = 'tests/Import/Fixtures/synthetic_catalog_allocations.csv.tpl';

    public function testEveryRowImportsAndResolvesAgainstTheSyntheticCatalog(): void
    {
        self::bootKernel();
        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');
        new ORMExecutor($em, $purgerFactory->createForEntityManager('default', $em, []))->execute($loader->getFixtures(['reference']), true);

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $hospitalId = $connection->fetchOne("SELECT h.id FROM hospital h JOIN dispatch_area d ON d.id = h.dispatch_area_id WHERE d.name = 'Kassel' ORDER BY h.public_id LIMIT 1");
        $hospital = $em->getRepository(Hospital::class)->find($hospitalId);
        self::assertInstanceOf(Hospital::class, $hospital, 'the catalog has a hospital in Kassel');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        self::assertInstanceOf(User::class, $user);
        $import = $em->getRepository(Import::class)->find(ImportFactory::createOne(['name' => 'Synthetic', 'hospital' => $hospital, 'createdBy' => $user, 'status' => ImportStatus::PENDING])->getId());
        self::assertInstanceOf(Import::class, $import);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);
        /** @var Filesystem $filesystem */
        $filesystem = $container->get(Filesystem::class);
        $file = $this->writeCsv($projectDir, $hospital, $connection);
        $reader = new SplCsvRowReader(new \SplFileObject($file, 'r'), new EncodingDetector(), new SplCsvStreamFactory($logger), encodingHint: 'UTF-8', delimiter: ';', enclosure: '"');
        $rejects = new SplCsvRejectWriter(filesystem: $filesystem, rejectsBaseDir: 'var/tests/rejects', clock: new NativeClock(), delimiter: ';', enclosure: "\0", escape: '\\');
        $rejects->start($import);
        /** @var AllocationImporterFactory $factory */
        $factory = $container->get(AllocationImporterFactory::class);

        $summary = $factory->create($reader, $rejects)->import($import);

        self::assertSame(5, $summary->total);
        self::assertSame(5, $summary->ok);
        self::assertSame(0, $summary->rejected);

        $indications = [];
        foreach ($em->getRepository(Allocation::class)->findBy([], ['arrivalAt' => 'ASC']) as $allocation) {
            self::assertSame('Kassel', $allocation->getDispatchArea()?->getName());
            self::assertNotNull($allocation->getDepartment());
            self::assertNotNull($allocation->getSpeciality());
            $indications[] = $allocation->getIndicationNormalized()?->getName();
        }

        self::assertSame([
            PzcCodes::name('322'),
            PzcCodes::name('311'),
            PzcCodes::name('421'),
            null,
            PzcCodes::name('353'),
        ], $indications, 'variants map to their official indication; the unmapped label stays unmapped');
    }

    /**
     * Fills the template with a hospital, the generated variants for 311 and 421 and an unmapped label of the catalog.
     */
    private function writeCsv(string $projectDir, Hospital $hospital, Connection $connection): string
    {
        $variant = static function (string $code) use ($connection): string {
            $name = $connection->fetchOne('SELECT name FROM indication_raw WHERE code = :code AND target_id IS NOT NULL AND name <> :official ORDER BY public_id LIMIT 1', ['code' => $code, 'official' => PzcCodes::name($code)]);
            self::assertIsString($name, sprintf('the catalog has a generated variant for %s', $code));

            return $name;
        };
        $unmapped = $connection->fetchAssociative("SELECT code, name FROM indication_raw WHERE target_id IS NULL AND name <> '' ORDER BY public_id LIMIT 1");
        self::assertIsArray($unmapped, 'the catalog has an unmapped label');

        $csv = strtr((string) file_get_contents($projectDir.'/'.self::TEMPLATE), [
            '{hospital}' => sprintf('%s, %s, %s %s', $hospital->getName(), $hospital->getAddress()->getStreet(), $hospital->getAddress()->getPostalCode(), $hospital->getAddress()->getCity()),
            '{short}' => explode(' ', (string) $hospital->getName())[0],
            '{variant311}' => $variant('311'),
            '{variant421}' => $variant('421'),
            '{unmappedCode}' => (string) $unmapped['code'],
            '{unmappedName}' => (string) $unmapped['name'],
        ]);

        $file = $projectDir.'/var/tests/synthetic_catalog_allocations.csv';
        new Filesystem()->dumpFile($file, $csv);

        return $file;
    }
}
