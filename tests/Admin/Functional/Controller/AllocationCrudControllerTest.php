<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The IVENA free text can contain personal data, so the admin area never shows it (#1549).
 */
#[ResetDatabase]
final class AllocationCrudControllerTest extends WebTestCase
{
    use Factories;

    private const string FREE_TEXT = 'Freitext-1549-Pat. Mustermann';

    private KernelBrowser $client;

    private int $allocationId;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create(['username' => 'allocation-admin']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $admin, 'createdBy' => $admin]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $admin]);
        $allocation = AllocationFactory::createOne(['hospital' => $hospital, 'import' => $import]);
        $allocation->setNotes(self::FREE_TEXT);
        $this->em()->flush();
        $this->allocationId = (int) $allocation->getId();

        $this->client->loginUser($this->em()->find(User::class, $admin->getId()) ?? $admin);
    }

    public function testListDetailAndFormsDoNotShowTheFreeText(): void
    {
        foreach (['/admin/allocation', '/admin/allocation/'.$this->allocationId, '/admin/allocation/'.$this->allocationId.'/edit', '/admin/allocation/new'] as $url) {
            $this->client->request(Request::METHOD_GET, $url);

            self::assertResponseIsSuccessful($url);
            self::assertStringNotContainsString(self::FREE_TEXT, (string) $this->client->getResponse()->getContent(), $url);
            self::assertSelectorNotExists('[name="Allocation[notes]"]', $url);
        }
    }

    public function testSavingTheEditFormKeepsTheStoredFreeText(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/allocation/'.$this->allocationId.'/edit');
        $form = $crawler->filter('form[name="Allocation"]')->form(['Allocation[age]' => '42']);

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em()->clear();
        $allocation = $this->em()->find(Allocation::class, $this->allocationId);
        self::assertNotNull($allocation);
        self::assertSame(42, $allocation->getAge(), 'The form was saved.');
        self::assertSame(self::FREE_TEXT, $allocation->getNotes());
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
