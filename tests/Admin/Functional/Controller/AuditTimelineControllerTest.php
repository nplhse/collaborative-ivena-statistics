<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Admin\Application\Audit\AuditTimelineService;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuditTimelineControllerTest extends WebTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{string}>
     */
    public static function routes(): iterable
    {
        yield 'timeline' => ['/admin/audit'];
        yield 'request' => ['/admin/audit/requests/r1'];
        yield 'entry' => ['/admin/audit/entries/1'];
        yield 'entry details' => ['/admin/audit/entries/1/details'];
    }

    #[DataProvider('routes')]
    public function testAnonymousVisitorIsSentToLogin(string $url): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[DataProvider('routes')]
    public function testUserWithoutAdminRoleIsForbidden(string $url): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asParticipant()->create());
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowsSingleEntriesAndRequestStacks(): void
    {
        $client = $this->adminClient();
        $this->insert('2026-11-13 09:00:00', 'r1', 'http', 'user.admin.updated');
        $this->insert('2026-11-13 09:00:00', 'r1', 'http');
        $this->insert('2026-11-13 09:00:05', 'r1', 'messenger');
        $this->insert('2026-11-13 10:00:00', 'r2', 'cli');

        $crawler = $client->request(Request::METHOD_GET, '/admin/audit');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-audit-stack="r1"]'));
        self::assertStringContainsString('user.admin.updated', $crawler->filter('[data-audit-stack="r1"] summary')->text());
        self::assertStringContainsString('3 entries', $crawler->filter('[data-audit-stack="r1"] summary')->text());
        self::assertStringContainsString('Background jobs started by this request', $crawler->filter('[data-audit-stack="r1"]')->text());
        self::assertCount(4, $crawler->filter('[data-audit-entry]'), 'every entry stays visible');
        self::assertCount(0, $crawler->filter('[data-audit-partial]'));
        self::assertCount(0, $crawler->filter('[data-audit-older]'));
    }

    public function testStackCutByThePageSizeSaysItIsIncompleteAndPagesContinue(): void
    {
        $client = $this->adminClient();
        $size = AuditTimelineService::PAGE_SIZE;
        // One request with PAGE_SIZE + 2 entries: the first page shows PAGE_SIZE of them.
        for ($i = 0; $i < $size + 2; ++$i) {
            $this->insert(\sprintf('2026-11-13 09:%02d:%02d', intdiv($i, 60), $i % 60), 'big', 'cli');
        }

        $crawler = $client->request(Request::METHOD_GET, '/admin/audit');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(\sprintf('%d of %d entries on this page', $size, $size + 2), $crawler->filter('[data-audit-partial]')->text());

        $client->click($crawler->filter('[data-audit-older]')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(2, $client->getCrawler()->filter('[data-audit-entry]'));
        self::assertStringContainsString(\sprintf('2 of %d entries on this page', $size + 2), $client->getCrawler()->filter('[data-audit-partial]')->text());
    }

    public function testFilterIsShownAndKeptInRequestLink(): void
    {
        $client = $this->adminClient();
        $this->insert('2026-11-13 09:00:00', 'r1', 'http', 'user.admin.updated');
        $this->insert('2026-11-13 09:00:01', 'r1', 'messenger');

        $crawler = $client->request(Request::METHOD_GET, '/admin/audit?origin=http&bogus=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Origin: http', $crawler->filter('[data-audit-active-filters]')->text());
        self::assertStringContainsString('1 of 2 entries on this page', $crawler->filter('[data-audit-stack="r1"]')->text());
        self::assertStringContainsString('1 of 2 entries match the filter', $crawler->filter('[data-audit-stack="r1"]')->text());
        self::assertStringContainsString('origin=http', (string) $crawler->filter('[data-audit-stack="r1"] a')->last()->attr('href'));
    }

    public function testRequestViewListsAllEntriesAndUnknownRequestIs404(): void
    {
        $client = $this->adminClient();
        $this->insert('2026-11-13 09:00:00', 'r1', 'http');
        $this->insert('2026-11-13 09:00:01', 'r1', 'messenger');

        $crawler = $client->request(Request::METHOD_GET, '/admin/audit/requests/r1');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-audit-entry]'));
        self::assertCount(0, $crawler->filter('[data-audit-limit]'));

        $client->request(Request::METHOD_GET, '/admin/audit/requests/unknown');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEmptyTimeline(): void
    {
        $client = $this->adminClient();

        $crawler = $client->request(Request::METHOD_GET, '/admin/audit?after=garbage');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-audit-empty]'));
    }

    public function testEntryDetailsAreLoadedLazilyAndRenderStoredContentAsText(): void
    {
        $client = $this->adminClient();
        $this->insert('2026-11-13 09:00:00', 'r1', 'http', 'user.admin.updated');
        $id = (int) $this->connection()->fetchOne('SELECT MAX(id) FROM audit_log');
        $this->connection()->executeStatement(
            'UPDATE audit_log SET changes = :changes, metadata = :metadata WHERE id = :id',
            [
                'id' => $id,
                'changes' => json_encode(['name' => ['old' => 'Kiel', 'new' => '<script>alert(1)</script>'], 'password' => ['old' => '********', 'new' => '********']], \JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['intent' => 'user.admin.updated', 'userAgent' => '<img src=x onerror=alert(2)>'], \JSON_THROW_ON_ERROR),
            ],
        );

        $crawler = $client->request(Request::METHOD_GET, '/admin/audit');
        $item = $crawler->filter(\sprintf('[data-audit-entry="%d"]', $id));
        self::assertSame(\sprintf('/admin/audit/entries/%d/details', $id), $item->filter('details')->attr('data-timeline-details-url-value'));
        self::assertStringNotContainsString('Kiel', $crawler->html(), 'values are not part of the overview');

        $client->request(Request::METHOD_GET, \sprintf('/admin/audit/entries/%d/details', $id));
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $html, 'a fragment, not a page');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('********', $html);
        self::assertCount(1, $client->getCrawler()->filter('a[href="/admin/audit/requests/r1"]'));
        self::assertCount(1, $client->getCrawler()->filter('a[href="/admin/audit?intent=user.admin.updated"]'));

        $page = $client->request(Request::METHOD_GET, \sprintf('/admin/audit/entries/%d', $id));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(\sprintf('Audit entry #%d', $id), $page->filter('title')->text());
        self::assertCount(1, $page->filter(\sprintf('[data-audit-details="%d"]', $id)));
    }

    public function testUnknownEntryIs404(): void
    {
        $client = $this->adminClient();

        $client->request(Request::METHOD_GET, '/admin/audit/entries/999999999');
        self::assertResponseStatusCodeSame(404);
        $client->request(Request::METHOD_GET, '/admin/audit/entries/999999999/details');
        self::assertResponseStatusCodeSame(404);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());
        // Creating the admin writes audit entries of its own; each test brings its own.
        $this->connection()->executeStatement('DELETE FROM audit_log');

        return $client;
    }

    private function insert(string $at, string $requestId, string $origin, ?string $intent = null): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO audit_log (occurred_at, request_id, actor_id, origin, action, entity_class, entity_id, changes, metadata, intent, request_id_kind)
             VALUES (:at, :rid, NULL, :origin, 'update', 'App\\Example\\Thing', '1', '{}', NULL, :intent, 'generated')",
            ['at' => $at, 'rid' => $requestId, 'origin' => $origin, 'intent' => $intent],
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
