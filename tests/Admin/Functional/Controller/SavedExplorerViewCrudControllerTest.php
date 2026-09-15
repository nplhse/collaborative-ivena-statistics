<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class SavedExplorerViewCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testExplorerViewIndexOmitsTimestampColumns(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'explorer-index-'.bin2hex(random_bytes(4)),
            ])
        ;
        $view = new SavedExplorerView(
            'explorer-index-'.bin2hex(random_bytes(4)),
            'Index Explorer View',
            'allocations',
            ['metric' => 'count'],
            'Index description',
            true,
        );

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($view);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/saved-explorer-view');
        self::assertResponseIsSuccessful();

        $headerText = implode(' | ', $crawler->filter('table thead th')->each(static fn ($node): string => trim($node->text())));
        self::assertStringContainsString('Slug', $headerText);
        self::assertStringContainsString('Title', $headerText);
        self::assertStringContainsString('Category', $headerText);
        self::assertStringNotContainsString('Created', $headerText);
        self::assertStringNotContainsString('Updated', $headerText);
    }

    public function testExplorerViewDetailShowsConfigAndTimestamps(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'explorer-detail-'.bin2hex(random_bytes(4)),
            ])
        ;
        $view = new SavedExplorerView(
            'explorer-detail-'.bin2hex(random_bytes(4)),
            'Detail Explorer View',
            'allocations',
            ['metric' => 'count'],
            'Detail description',
            false,
        );

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($view);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/saved-explorer-view/'.$view->getId());
        self::assertResponseIsSuccessful();

        $body = $crawler->text();
        self::assertStringContainsString('Detail Explorer View', $body);
        self::assertStringContainsString('Config JSON', $body);
        self::assertStringContainsString('metric', $body);
        self::assertStringContainsString('Created', $body);
        self::assertStringContainsString('Updated', $body);
    }
}
