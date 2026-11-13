<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\MciCaseFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #1121: the admin CRUD pages show German titles and labels for a German admin.
 */
#[ResetDatabase]
final class AdminCrudTranslationTest extends WebTestCase
{
    use Factories;

    /** English labels of these pages that differ in German; none may be left on a German page. */
    private const array ENGLISH_LABELS = [
        'Created', 'Updated', 'Created by', 'Updated by', 'Description', 'Category', 'Sort order', 'Indications',
        'Note', 'Children', 'Groups', 'Normalized', 'Review status', 'Target', 'Review comment', 'Reviewed at',
        'Reviewed by', 'First matched by', 'First matched at', 'Aliases', 'State', 'Dispatch Areas',
        'Hospital', 'Hospitals', 'Dispatch Area', 'Arrival', 'Gender', 'Age', 'Requires Resus', 'Requires Cathlab',
        'CPR', 'Ventilated', 'Shock', 'Pregnant', 'Work Accident', 'With Physician', 'Transport Type', 'Urgency',
        'Speciality', 'Department', 'Department Was Closed', 'Occasion', 'Assignment', 'Infection',
        'Secondary transport', 'Secondary indication raw', 'Secondary indication normalized', 'Case ID hash', 'Notes',
        'MANV-ID (Title)', 'Owner', 'Street', 'Postal code', 'City', 'Country', 'Location', 'Tier', 'Size', 'Beds',
        'Latitude', 'Longitude', 'Participating', 'Participating since', 'Access grants', 'User', 'Permissions',
        'Indication Raw', 'Indication Normalized',
        'Type', 'Rows total', 'Rows passed', 'Rows rejected', 'Rows deduplicated',
        'Rows deduplicated (Discarded)', 'Rows deduplicated (Replaced)', 'Run count', 'Runtime (ms)', 'Export starts at',
        'Export ends at (exclusive)', 'Export period source', 'Source file', 'Reject file', 'File extension', 'MIME type',
        'File size (bytes)', 'Checksum', 'Line', 'Messages', 'Row JSON', 'Started', 'Finished', 'Options', 'Items',
        'Batch run', 'Import ID', 'Import name', 'Attempts', 'Error',
    ];

    /**
     * The raw indication pages are left out: their list and detail fail with a 500 on develop already
     * (review status enum rendered as text), independent of the translations.
     *
     * @return iterable<string, array{string, \Closure(): object, string, string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'assignment' => ['assignment', static fn (): object => AssignmentFactory::createOne(), 'Zuweisungstypen', 'Zuweisungstyp'];
        yield 'department' => ['department', static fn (): object => DepartmentFactory::createOne(), 'Abteilungen', 'Abteilung'];
        yield 'dispatch area' => ['dispatch-area', static fn (): object => DispatchAreaFactory::createOne(), 'Leitstellenbereiche', 'Leitstellenbereich'];
        yield 'infection' => ['infection', static fn (): object => InfectionFactory::createOne(), 'Infektionen', 'Infektion'];
        yield 'occasion' => ['occasion', static fn (): object => OccasionFactory::createOne(), 'Anlässe', 'Anlass'];
        yield 'secondary transport' => ['secondary-transport', static fn (): object => SecondaryTransportFactory::createOne(), 'Sekundärtransporte', 'Sekundärtransport'];
        yield 'speciality' => ['speciality', static fn (): object => SpecialityFactory::createOne(), 'Fachrichtungen', 'Fachrichtung'];
        yield 'state' => ['state', static fn (): object => StateFactory::createOne(), 'Bundesländer', 'Bundesland'];
        yield 'indication group' => ['indication-group', static fn (): object => IndicationGroupFactory::createOne(), 'Indikationsgruppen', 'Indikationsgruppe'];
        yield 'allocation' => ['allocation', static fn (): object => self::withReferenceData(static fn (): object => AllocationFactory::createOne()), 'Zuweisungen', 'Zuweisung'];
        yield 'mci case' => ['mci-case', static fn (): object => self::withReferenceData(static fn (): object => MciCaseFactory::createOne()), 'MANV-Fälle', 'MANV-Fall'];
        yield 'hospital' => ['hospital', static fn (): object => HospitalFactory::createOne(), 'Kliniken', 'Klinik'];
        yield 'hospital access grant' => ['hospital-access-grant', static fn (): object => HospitalAccessGrantFactory::createOne(), 'Zugriffsberechtigungen', 'Zugriffsberechtigung'];
        yield 'import' => ['import', static fn (): object => self::withReferenceData(static fn (): object => ImportFactory::createOne()), 'Importe', 'Import'];
        yield 'import reject' => ['import-reject', self::importReject(...), 'Abgelehnte Importzeilen', 'Abgelehnte Importzeile'];
        yield 'import batch run' => ['import-batch-run', static fn (): object => self::batchRunItem()->getRun() ?? throw new \LogicException('no run'), 'Stapelläufe', 'Stapellauf'];
        yield 'import batch run item' => ['import-batch-run-item', self::batchRunItem(...), 'Stapelpositionen', 'Stapelposition'];
        yield 'indication normalized' => ['indication-normalized', static fn (): object => IndicationNormalizedFactory::createOne(), 'Indikationen (normalisiert)', 'Indikation (normalisiert)'];
    }

    /**
     * @param \Closure(): object $create
     */
    #[DataProvider('pageProvider')]
    public function testIndexIsGermanForAGermanAdmin(string $slug, \Closure $create, string $plural, string $singular): void
    {
        $client = $this->germanAdmin();
        $create();

        $crawler = $client->request(Request::METHOD_GET, '/admin/'.$slug);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($plural, $crawler->filter('title')->text());
        $this->assertNoEnglishLabel($crawler->filter('table thead th')->each(static fn ($th): string => trim($th->text())), $slug.' index');
    }

    /**
     * @param \Closure(): object $create
     */
    #[DataProvider('pageProvider')]
    public function testDetailIsGermanForAGermanAdmin(string $slug, \Closure $create, string $plural, string $singular): void
    {
        $client = $this->germanAdmin();
        $entity = $create();
        self::assertTrue(method_exists($entity, 'getId'));

        $crawler = $client->request(Request::METHOD_GET, '/admin/'.$slug.'/'.$entity->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($singular, $crawler->filter('title')->text());
        $this->assertNoEnglishLabel($crawler->filter('.field-label')->each(static fn ($label): string => trim($label->text())), $slug.' detail');
    }

    /**
     * Allocation and MCI factories pick random existing reference data; one of each is enough.
     *
     * @param \Closure(): object $create
     */
    private static function withReferenceData(\Closure $create): object
    {
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);
        ImportFactory::createOne(['hospital' => $hospital]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();
        SecondaryTransportFactory::createOne();
        IndicationRawFactory::createOne();
        IndicationNormalizedFactory::createOne();

        return $create();
    }

    private static function importReject(): ImportReject
    {
        $reject = new ImportReject();
        $reject->setImport(self::withReferenceData(static fn (): object => ImportFactory::createOne()));
        $reject->setLineNumber(12);
        $reject->setMessages(['createdAt: This value should not be blank.']);
        $reject->setRow(['line' => 12]);

        return self::persist($reject);
    }

    private static function batchRunItem(): ImportBatchRunItem
    {
        $run = new ImportBatchRun(['dry_run' => false]);
        $item = new ImportBatchRunItem(1, 'Import 1');
        $run->addItem($item);
        self::persist($run);

        return self::persist($item);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private static function persist(object $entity): object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($entity);
        $entityManager->flush();

        return $entity;
    }

    /**
     * @param list<string> $labels
     */
    private function assertNoEnglishLabel(array $labels, string $page): void
    {
        self::assertNotSame([], $labels, $page.' shows labels');
        foreach ($labels as $label) {
            self::assertNotContains($label, self::ENGLISH_LABELS, $page.': '.implode(' | ', $labels));
        }
    }

    private function germanAdmin(): KernelBrowser
    {
        // One request per test: loginUser() fills the token storage only for the next request, and the locale listener
        // reads the user from it before the firewall runs.
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        return $client;
    }
}
