<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Infrastructure\Security;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportVoterTest extends KernelTestCase
{
    use Factories;

    private ImportVoter $voter;

    /** @var list<string> */
    private array $storedFiles = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = self::getContainer()->get(ImportVoter::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->storedFiles as $file) {
            @unlink($file);
            @rmdir(\dirname($file));
        }
        $this->storedFiles = [];

        parent::tearDown();
    }

    public function testViewGrantedForHospitalOwner(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedForForeignParticipant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder), $import, [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedForAnonymousUser(): void
    {
        $import = $this->createMock(Import::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($token, $import, [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedWhenImportHasNoHospital(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createMock(Import::class);
        $import->method('getHospital')->willReturn(null);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::VIEW]),
        );
    }

    public function testDeleteGrantedForHospitalOwner(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::DELETE]),
        );
    }

    public function testDeleteGrantedForHospitalOwnerEvenWhenNotCreator(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, $creator);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $creator,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::DELETE]),
        );
    }

    public function testDeleteGrantedForAdmin(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin), $import, [ImportVoter::DELETE]),
        );
    }

    public function testDownloadSourceGrantedForAdmin(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin), $import, [ImportVoter::DOWNLOAD_SOURCE]),
        );
    }

    public function testDownloadSourceDeniedForHospitalOwner(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::DOWNLOAD_SOURCE]),
        );
    }

    public function testDownloadSourceDeniedForForeignParticipant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder), $import, [ImportVoter::DOWNLOAD_SOURCE]),
        );
    }

    public function testDeleteDeniedForCreatorWithoutHospitalAccess(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, $creator);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($creator), $import, [ImportVoter::DELETE]),
        );
    }

    public function testDeleteGrantedForCreatorWithImportGrant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, $creator);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $creator,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($creator), $import, [ImportVoter::DELETE]),
        );
    }

    public function testDeleteDeniedForForeignParticipant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder), $import, [ImportVoter::DELETE]),
        );
    }

    public function testDeleteDeniedForGranteeWithImportPermissionButNotCreator(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, $creator);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($grantee), $import, [ImportVoter::DELETE]),
        );
    }

    public function testViewGrantedForUserWithImportGrant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($grantee), $import, [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedForUserWithOnlyViewGrant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($grantee), $import, [ImportVoter::VIEW]),
        );
    }

    public function testReprocessGrantedForHospitalOwnerWhoCreatedTheImport(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, filePath: $this->storedFile());

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::REPROCESS]),
        );
    }

    public function testReprocessGrantedForAdmin(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, filePath: $this->storedFile());

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin), $import, [ImportVoter::REPROCESS]),
        );
    }

    public function testReprocessDeniedForForeignParticipant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, filePath: $this->storedFile());

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder), $import, [ImportVoter::REPROCESS]),
        );
    }

    public function testReprocessDeniedWhenTheSourceFileIsGone(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($admin), $import, [ImportVoter::REPROCESS]),
        );
        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin), $import, [ImportVoter::DELETE]),
        );
    }

    public function testReprocessDeniedWhenTheCreatorLostTheImportPermission(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, $creator, filePath: $this->storedFile());

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($admin), $import, [ImportVoter::REPROCESS]),
        );
        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::REPROCESS]),
        );
    }

    public function testReprocessGrantedWhileTheCreatorKeepsAnImportGrant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, $creator, filePath: $this->storedFile());

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $creator,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($creator), $import, [ImportVoter::REPROCESS]),
        );
    }

    public function testClosureReprocessDeniedWhenTheCreatorLostTheBetaRole(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_CLOSURE_BETA']]);
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, type: ImportType::CLOSURE, filePath: $this->storedFile());

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($admin), $import, [ImportVoter::REPROCESS]),
        );
    }

    public function testClosureReprocessGrantedWhenTheCreatorHasTheBetaRole(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA']]);
        $import = $this->createImportForOwner($owner, type: ImportType::CLOSURE, filePath: $this->storedFile());

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::REPROCESS]),
        );
    }

    public function testSupportsTypeForImportOnly(): void
    {
        self::assertTrue($this->voter->supportsType(Import::class));
        self::assertFalse($this->voter->supportsType(Hospital::class));
    }

    public function testClosureImportIsHiddenWithoutBetaRole(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, type: ImportType::CLOSURE);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::VIEW]),
        );
        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::DELETE]),
        );
    }

    public function testClosureImportIsVisibleWithBetaRole(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA']]);
        $import = $this->createImportForOwner($owner, type: ImportType::CLOSURE);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $import, [ImportVoter::VIEW]),
        );
    }

    private function createImportForOwner(object $owner, ?object $createdBy = null, ImportType $type = ImportType::ALLOCATION, string $filePath = '/tmp/test.csv'): object
    {
        $createdBy ??= $owner;
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
            'type' => $type,
            'status' => ImportStatus::PENDING,
            'filePath' => $filePath,
        ]);
    }

    /**
     * Writes a source file under the imports base directory and returns its stored path.
     */
    private function storedFile(): string
    {
        $importsBaseDir = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $testToken = getenv('TEST_TOKEN') ?: '0';
        $targetDir = $importsBaseDir.'/_tests/'.$testToken.'/'.bin2hex(random_bytes(4));
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            self::fail('Unable to create imports test directory: '.$targetDir);
        }

        $absolutePath = $targetDir.'/source.csv';
        file_put_contents($absolutePath, "a;b\n");
        $this->storedFiles[] = $absolutePath;

        return ltrim(substr($absolutePath, \strlen($projectDir)), '/');
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
