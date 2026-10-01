<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Entity\DepartmentAlias;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DepartmentCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminDetailShowsAliasClassificationAndSource(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()
            ->asAdmin()
            ->create(['username' => 'department-admin-'.bin2hex(random_bytes(4))]);
        $department = DepartmentFactory::createOne(['name' => 'Allgemeine Innere Medizin', 'createdBy' => $admin]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new DepartmentAlias($department, 'Allgemein Innere Medizin', 'faulty_catalog', 'local-catalog'));
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/department/'.$department->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Allgemein Innere Medizin (faulty_catalog, local-catalog)');
    }
}
