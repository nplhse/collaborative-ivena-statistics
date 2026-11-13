<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #1121, step 1: the reference data CRUD pages show German titles and labels for a German admin.
 */
#[ResetDatabase]
final class ReferenceDataCrudTranslationTest extends WebTestCase
{
    use Factories;

    /** English labels of these pages that differ in German; none may be left on a German page. */
    private const array ENGLISH_LABELS = [
        'Created', 'Updated', 'Created by', 'Updated by', 'Description', 'Category', 'Sort order', 'Indications',
        'Note', 'Children', 'Groups', 'Normalized', 'Review status', 'Target', 'Review comment', 'Reviewed at',
        'Reviewed by', 'First matched by', 'First matched at', 'Aliases', 'State', 'Dispatch Areas',
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
