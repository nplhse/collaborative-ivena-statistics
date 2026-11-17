<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\WithoutEdVisit;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Kernel\KernelWithoutEdVisit;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Allocation and closure imports with the ED visit plugin disabled (#1300 step 5), through the upload form.
 * Reference data as in NewImportControllerTest; no random reference data, so the import's lookups by name are
 * unambiguous.
 */
#[ResetDatabase]
final class AllocationAndClosureImportWithoutEdVisitTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    /** @var list<string> */
    private array $files = [];

    #[\Override]
    protected static function getKernelClass(): string
    {
        return KernelWithoutEdVisit::class;
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->files);

        parent::tearDown();
    }

    public function testAllocationAndClosureImportsRun(): void
    {
        $this->client = self::createClient();
        [$owner, $hospitalId] = $this->ownerWithHospital();
        $this->client->loginUser($owner);

        $allocations = $this->upload($hospitalId, 'allocation upload', 'Allocations', (string) file_get_contents(\dirname(__DIR__, 3).'/Import/Fixtures/allocation_import_sample.csv'));
        $closures = $this->upload($hospitalId, 'closure upload', 'Closure', implode("\n", [
            'Krankenhaus-Kurzname;Fachgebiet;Fachbereich;Behandlungsdringlichkeit;Datum (Schließungs-Beginn);Uhrzeit (Schließungs-Beginn);Schließungs-Dauer (Minuten);Datum (Schließungs-Ende);Uhrzeit (Schließungs-Ende);Grund;Eingetragen am;Geändert am;Typ',
            'Klinikum Beispiel;Innere Medizin;Kardiologie;Notfallversorgung;01.01.2026;00:10:00;60;01.01.2026;01:10:00;Überlastung der Notaufnahme;01.01.2026 00:20:22;01.01.2026 00:20:22;Klinik',
        ])."\n");

        self::assertSame(ImportType::ALLOCATION, $allocations->getType());
        self::assertTrue($allocations->isFinalStatus());
        self::assertNotSame(ImportStatus::FAILED, $allocations->getStatus());
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation WHERE import_id = ?', [$allocations->getId()]));

        self::assertSame(ImportType::CLOSURE, $closures->getType());
        self::assertSame(ImportStatus::COMPLETED, $closures->getStatus());
    }

    /**
     * @return array{User, int}
     */
    private function ownerWithHospital(): array
    {
        UserFactory::new()->withoutAutorefresh()->create(['username' => 'import-owner', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA']]);
        $owner = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'import-owner']);
        self::assertInstanceOf(User::class, $owner);
        $state = StateFactory::new()->withoutAutorefresh()->create(['name' => 'Hessen']);
        $hospital = HospitalFactory::new()->withoutAutorefresh()->create([
            'name' => 'Klinikum Beispiel',
            'owner' => $owner,
            'createdBy' => UserFactory::new()->withoutAutorefresh()->create(['username' => 'import-area-user']),
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::new()->withoutAutorefresh()->create(['name' => 'Test Area', 'state' => $state]),
        ]);
        SpecialityFactory::new()->withoutAutorefresh()->create(['name' => 'Innere Medizin']);
        DepartmentFactory::new()->withoutAutorefresh()->create(['name' => 'Kardiologie']);
        foreach (['Patient', 'RD', 'ZLST'] as $name) {
            AssignmentFactory::new()->withoutAutorefresh()->create(['name' => $name]);
        }
        foreach (['aus Arztpraxis', 'Häuslicher Einsatz', 'Öffentlicher Raum', 'Sonstiger Einsatz'] as $name) {
            OccasionFactory::new()->withoutAutorefresh()->create(['name' => $name]);
        }
        SecondaryTransportFactory::new()->withoutAutorefresh()->create(['name' => 'Kapazitätsengpass']);
        foreach (['Noro', 'V.a. COVID'] as $name) {
            InfectionFactory::new()->withoutAutorefresh()->create(['name' => $name]);
        }
        IndicationRawFactory::new()->withoutAutorefresh()->create(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        $this->entityManager()->clear();
        $owner = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'import-owner']);
        self::assertInstanceOf(User::class, $owner);

        return [$owner, (int) $hospital->getId()];
    }

    private function upload(int $hospitalId, string $name, string $type, string $content): Import
    {
        $path = sys_get_temp_dir().'/without-ed-visit-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $content);
        $this->files[] = $path;

        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="import_create"]')->form();
        $form['import_create[name]'] = $name;
        $form['import_create[hospital]'] = (string) $hospitalId;
        $form['import_create[type]'] = (string) $crawler->filter('select[name="import_create[type]"] option')
            ->reduce(static fn (Crawler $option): bool => str_contains($option->text(), $type))
            ->attr('value');
        $file = $form['import_create[file]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        $this->client->submit($form);

        $this->entityManager()->clear();
        $import = $this->entityManager()->getRepository(Import::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Import::class, $import);
        self::assertResponseRedirects('/import/'.$import->getId().'/processing');
        if (null !== $import->getFilePath()) {
            $this->files[] = $import->getFilePath();
        }

        return $import;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
