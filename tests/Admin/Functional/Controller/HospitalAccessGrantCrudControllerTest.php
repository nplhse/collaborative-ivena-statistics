<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalAccessGrantCrudControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanCreateHospitalAccessGrant(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'grant-admin-'.bin2hex(random_bytes(4)),
        ]);
        $user = UserFactory::createOne([
            'username' => 'grant-user-'.bin2hex(random_bytes(4)),
        ]);
        $hospital = HospitalFactory::createOne();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/hospital-access-grant/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $this->selectChoice($form, 'HospitalAccessGrant[hospital]', (string) $hospital->getId());
        $this->selectChoice($form, 'HospitalAccessGrant[user]', (string) $user->getId());
        $this->selectChoice($form, 'HospitalAccessGrant[permissions]', [
            (string) HospitalPermission::View->value,
            (string) HospitalPermission::Statistics->value,
        ]);
        $client->submit($form);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $grant = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(\App\Allocation\Domain\Entity\HospitalAccessGrant::class)
            ->findOneBy(['hospital' => $hospital, 'user' => $user]);

        self::assertNotNull($grant);
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::Statistics));
    }

    /**
     * @param list<string>|string $value
     */
    private function selectChoice(Form $form, string $name, array|string $value): void
    {
        $field = $form->get($name);
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->select($value);
    }
}
