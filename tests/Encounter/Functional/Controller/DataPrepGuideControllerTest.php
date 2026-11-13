<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Landing page of the data preparation tool (#1130): versions, changelog, grant-dependent key link,
 * the local map help and its template download.
 */
#[ResetDatabase]
final class DataPrepGuideControllerTest extends WebTestCase
{
    use EncounterTestFixtures;
    use Factories;

    public function testPageNeedsTheBetaRole(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/encounter/data-prep');
        self::assertResponseRedirects();

        $client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $client->request(Request::METHOD_GET, '/encounter/data-prep');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        foreach (['/encounter/data-prep/key', '/encounter/data-prep/local-map', '/encounter/data-prep/local-map-template.json'] as $url) {
            $client->request(Request::METHOD_GET, $url);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $url);
        }
    }

    public function testPageShowsTheVersionsTheDownloadAndTheChangelogNewestFirst(): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());
        $crawler = $client->request(Request::METHOD_GET, '/encounter/data-prep');

        self::assertResponseIsSuccessful();
        self::assertSame('Data preparation tool', trim($crawler->filter('.breadcrumb-item.active')->text()));
        self::assertSame('1.1.0', trim($crawler->filter('[data-test="data-prep-version"]')->text()));
        self::assertSame('2026-11-13', trim($crawler->filter('[data-test="data-prep-released-at"]')->text()));
        self::assertSame('1.0.0', trim($crawler->filter('[data-test="data-prep-min-version"]')->text()));
        $download = $crawler->filter('[data-test="data-prep-download"]');
        self::assertSame('/encounter/data-prep/download', $download->attr('href'));
        self::assertSame('encounter-data-prep-1.1.0.html', $download->attr('download'));

        $entries = $crawler->filter('[data-test="data-prep-changelog-entry"]');
        self::assertSame(['1.1.0', '1.0.2', '1.0.1', '1.0.0'], $entries->each(static fn ($entry): ?string => $entry->attr('data-version')));
        self::assertStringContainsString('Local map editor', $entries->first()->text());
        self::assertStringContainsString('ORBIS MTS export', $crawler->filter('[data-test="data-prep-profile"]')->text());
        self::assertSelectorExists('[data-test="data-prep-no-hospitals"]');
        self::assertSame('/encounter/data-prep/key', $crawler->filter('[data-test="data-prep-key-link"]')->attr('href'));
        self::assertSame('/encounter/data-prep/local-map', $crawler->filter('[data-test="data-prep-local-map-link"]')->attr('href'));
        self::assertSelectorNotExists('[data-test="data-prep-key-change"]', 'the key change link is on the key page');
    }

    public function testOwnerGetsTheKeyChangeLinkAndUploaderSeesWhoCanChangeIt(): void
    {
        $client = self::createClient();
        $owner = $this->user(username: 'guide-owner');
        $uploader = $this->user(username: 'guide-uploader');
        $hospital = $this->hospital($owner);
        $this->hospitalGrant($hospital, $uploader);
        $this->encounterGrant($hospital, $uploader, [EncounterGrantAction::Upload]);
        $hospitalId = (int) $hospital->getId();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/encounter/data-prep');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter(sprintf('[data-test="data-prep-hospital"][data-hospital="%d"]', $hospitalId));
        self::assertCount(1, $row, 'an owner without a grant still sees the hospital');
        self::assertCount(0, $row->filter('[data-test="data-prep-grant-action"]'));
        self::assertCount(1, $row->filter('[data-test="data-prep-manage-grants"]'));

        $crawler = $client->request(Request::METHOD_GET, '/encounter/data-prep/key');
        self::assertResponseIsSuccessful();
        self::assertSame('Key file and key generations', trim($crawler->filter('.breadcrumb-item.active')->text()));
        self::assertSame(
            sprintf('/hospitals/%d/edit/encounter-key', $hospitalId),
            $crawler->filter('[data-test="data-prep-key-change"]')->attr('href'),
        );
        self::assertSelectorNotExists('[data-test="data-prep-key-who"]');

        $client->loginUser($uploader);
        $crawler = $client->request(Request::METHOD_GET, '/encounter/data-prep');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter(sprintf('[data-test="data-prep-hospital"][data-hospital="%d"]', $hospitalId));
        self::assertSame(['Upload'], $row->filter('[data-test="data-prep-grant-action"]')->each(static fn ($badge): string => trim($badge->text())));
        self::assertCount(0, $row->filter('[data-test="data-prep-manage-grants"]'));

        $client->request(Request::METHOD_GET, '/encounter/data-prep/key');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-test="data-prep-key-change"]');
        self::assertSelectorExists('[data-test="data-prep-key-who"]');
    }

    public function testAdministratorSeesTheAdminNote(): void
    {
        $client = self::createClient();
        $client->loginUser($this->admin());
        $client->request(Request::METHOD_GET, '/encounter/data-prep/key');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="data-prep-key-admin"]');
        self::assertSelectorNotExists('[data-test="data-prep-key-who"]');
    }

    public function testLocalMapHelpListsUniqueDepartmentNamesAndTheAllowedValues(): void
    {
        $client = self::createClient();
        $createdBy = UserFactory::createOne();
        foreach (['Unfallchirurgie', 'Innere Medizin', 'Kardiologie', 'kardiologie'] as $name) {
            DepartmentFactory::createOne(['name' => $name, 'createdBy' => $createdBy]);
        }

        $client->loginUser($this->user());
        $crawler = $client->request(Request::METHOD_GET, '/encounter/data-prep/local-map');

        self::assertResponseIsSuccessful();
        self::assertSame('Local map', trim($crawler->filter('.breadcrumb-item.active')->text()));
        self::assertSame('/encounter/data-prep/local-map-template.json', $crawler->filter('[data-test="data-prep-local-map-template"]')->attr('href'));
        self::assertSame(
            ['Innere Medizin', 'Unfallchirurgie'],
            $crawler->filter('[data-test="data-prep-department-name"]')->each(static fn ($item): string => trim($item->text())),
            'a name two catalog entries share is not resolvable and not listed',
        );
        self::assertStringContainsString('icu', $crawler->filter('[data-test="data-prep-care-levels"]')->text());
        self::assertStringContainsString('transferred_external', $crawler->filter('[data-test="data-prep-dispositions"]')->text());
        self::assertStringContainsString('"encounter-local-map"', $crawler->filter('[data-test="data-prep-local-map-example"]')->text());
    }

    public function testTemplateDownloadIsAnEmptyLocalMap(): void
    {
        $client = self::createClient();
        $client->loginUser($this->user());
        $client->request(Request::METHOD_GET, '/encounter/data-prep/local-map-template.json');

        self::assertResponseIsSuccessful();
        self::assertSame('attachment; filename=encounter-local-map-template.json', $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame([
            'format' => 'encounter-local-map',
            'version' => 1,
            'profile' => 'orbis-mts',
            'hospital' => '',
            'departments' => [],
            'stations' => [],
            'dispositions' => [],
        ], json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));

        $client->request(Request::METHOD_GET, '/encounter/data-prep/local-map-other.json');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
