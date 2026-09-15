<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Shared\Domain\Entity\CookieConsent;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CookieConsentCrudControllerTest extends WebTestCase
{
    use Factories;
    use HasBrowser;

    public function testAdminCanOpenCookieConsentIndex(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'cookie-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $this->browser()
            ->actingAs($admin)
            ->visit('/admin/cookie-consent')
            ->assertSuccessful()
            ->assertSee('Cookie consents')
        ;
    }

    public function testNonAdminUserGetsForbiddenOnCookieConsentIndex(): void
    {
        $user = UserFactory::createOne([
            'username' => 'cookie-regular-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($user)
            ->visit('/admin/cookie-consent')
            ->assertStatus(403)
        ;
    }

    public function testCookieConsentIndexOmitsSecondaryColumns(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'cookie-index-'.bin2hex(random_bytes(4)),
            ])
        ;
        $consent = new CookieConsent('subject-'.bin2hex(random_bytes(4)));
        $consent->setUser($admin);
        $consent->setConsents(true);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($consent);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/cookie-consent');
        self::assertResponseIsSuccessful();

        $headerText = implode(' | ', $crawler->filter('table thead th')->each(static fn ($node): string => trim($node->text())));
        self::assertStringContainsString('Subject ID', $headerText);
        self::assertStringContainsString('User', $headerText);
        self::assertStringContainsString('Mode', $headerText);
        self::assertStringContainsString('Decided at', $headerText);
        self::assertStringNotContainsString('Version', $headerText);
        self::assertStringNotContainsString('Updated at', $headerText);
    }

    public function testCookieConsentDetailShowsVersionAndUpdatedAt(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'cookie-detail-'.bin2hex(random_bytes(4)),
            ])
        ;
        $consent = new CookieConsent('subject-'.bin2hex(random_bytes(4)));
        $consent->setUser($admin);
        $consent->setConsents(false);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($consent);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/cookie-consent/'.$consent->getId());
        self::assertResponseIsSuccessful();

        $body = $crawler->text();
        self::assertStringContainsString($consent->getSubjectId(), $body);
        self::assertStringContainsString('Version', $body);
        self::assertStringContainsString('v1', $body);
        self::assertStringContainsString('Updated at', $body);
    }
}
