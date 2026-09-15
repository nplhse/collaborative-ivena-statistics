<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testHospitalIndexOmitsTimestampColumns(): void
    {
        $client = self::createClient();
        HospitalFactory::createOne([
            'name' => 'Index Hospital '.bin2hex(random_bytes(4)),
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'hospital-index-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/hospital');
        self::assertResponseIsSuccessful();

        $headers = $crawler->filter('table thead th')->each(static fn ($node): string => trim($node->text()));
        $headerText = implode(' | ', $headers);

        self::assertStringContainsString('Name', $headerText);
        self::assertStringContainsString('Owner', $headerText);
        self::assertStringContainsString('Participating', $headerText);
        self::assertStringNotContainsString('Created', $headerText);
        self::assertStringNotContainsString('Updated', $headerText);
    }

    public function testHospitalDetailShowsAccessGrants(): void
    {
        $client = self::createClient();
        $hospital = HospitalFactory::createOne([
            'name' => 'Detail Hospital '.bin2hex(random_bytes(4)),
            'isParticipating' => true,
        ]);
        $grantee = UserFactory::createOne([
            'username' => 'grant-member-'.bin2hex(random_bytes(4)),
        ]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
        ]);
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'hospital-detail-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/hospital/'.$hospital->getId());
        self::assertResponseIsSuccessful();

        $body = $crawler->text();
        self::assertStringContainsString('Access grants', $body);
        self::assertStringContainsString((string) $grantee->getUsername(), $body);
        self::assertStringContainsString($hospital->getCreatedAt()->format('d.m.Y H:i'), $body);
        self::assertSelectorExists('a.action-sendMonthlyReminder');
        self::assertSelectorExists('a.action-reminderHistory');
    }
}
