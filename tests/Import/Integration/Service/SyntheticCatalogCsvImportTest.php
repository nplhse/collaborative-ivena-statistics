<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
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
 * The rows use an official PZC wording, two generated variants of seed 740 (mapped to their
 * normalized indication), and a label the catalog leaves unmapped. Base for the CSV round trip of #791.
 */
#[ResetDatabase]
final class SyntheticCatalogCsvImportTest extends KernelTestCase
{
    private const string FIXTURE = 'tests/Import/Fixtures/synthetic_catalog_allocations.csv';

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

        $hospital = $em->getRepository(Hospital::class)->findOneBy(['name' => 'Universitätskrankenhaus Wolfhagen']);
        self::assertInstanceOf(Hospital::class, $hospital);
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        self::assertInstanceOf(User::class, $user);
        $import = $em->getRepository(Import::class)->find(ImportFactory::createOne(['name' => 'Synthetic', 'hospital' => $hospital, 'createdBy' => $user, 'status' => ImportStatus::PENDING])->getId());
        self::assertInstanceOf(Import::class, $import);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);
        /** @var Filesystem $filesystem */
        $filesystem = $container->get(Filesystem::class);
        $reader = new SplCsvRowReader(new \SplFileObject($projectDir.'/'.self::FIXTURE, 'r'), new EncodingDetector(), new SplCsvStreamFactory($logger), encodingHint: 'UTF-8', delimiter: ';', enclosure: '"');
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
            'Synkope / Kollaps',
            'Atemnot (unklar) /Atembeschwerden /ARI',
            'Schlaganfall / Blutung < 24 h oder unklar',
            null,
            'Bauchschmerzen',
        ], $indications, 'variants map to their official indication; the unmapped label stays unmapped');
    }
}
