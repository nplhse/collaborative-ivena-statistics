<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Background upload of the import form (#977): `POST /import/upload` and its discard route.
 */
#[ResetDatabase]
final class PendingImportUploadControllerTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private string $pendingDir;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $baseDir = self::getContainer()->getParameter('app.imports_base_dir');
        self::assertIsString($baseDir);
        $this->pendingDir = $baseDir.'/pending';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->pendingDir);
        parent::tearDown();
    }

    public function testGuestIsSentToLogin(): void
    {
        $this->client->request(Request::METHOD_POST, '/import/upload');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testParticipantWithoutUploadRightIsDenied(): void
    {
        $this->owner();
        $participant = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->client->loginUser($participant);

        $this->client->request(Request::METHOD_POST, '/import/upload', ['_token' => 'x'], ['file' => $this->file('export.csv')]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnInvalidCsrfTokenIsRejected(): void
    {
        $this->client->loginUser($this->owner());

        $this->client->request(Request::METHOD_POST, '/import/upload', ['_token' => 'wrong'], ['file' => $this->file('export.csv')]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testACsvFileIsStoredForTheUserAndAnsweredWithAToken(): void
    {
        $owner = $this->owner();
        $this->client->loginUser($owner);

        $answer = $this->upload($this->file('ivena-export.csv', "a;b\n1;2\n"));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $answer['token']);
        self::assertSame('ivena-export.csv', $answer['name']);
        self::assertSame(8, $answer['size']);
        self::assertFileExists($this->pendingDir.'/'.$owner->getId().'/'.$answer['token'].'.upload');
    }

    public function testAnExcelFileIsRejectedWithAMessage(): void
    {
        $owner = $this->owner();
        $this->client->loginUser($owner);

        $answer = $this->upload($this->file('export.xlsx', 'x', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Excel', (string) $answer['error']);
        self::assertDirectoryDoesNotExist($this->pendingDir.'/'.$owner->getId());
    }

    public function testANewUploadReplacesTheOneItNames(): void
    {
        $owner = $this->owner();
        $this->client->loginUser($owner);

        $first = $this->upload($this->file('first.csv'));
        $second = $this->upload($this->file('second.csv'), (string) $first['token']);

        self::assertFileDoesNotExist($this->pendingDir.'/'.$owner->getId().'/'.$first['token'].'.upload');
        self::assertFileExists($this->pendingDir.'/'.$owner->getId().'/'.$second['token'].'.upload');
    }

    public function testDiscardRemovesOnlyTheUploadsOfTheCurrentUser(): void
    {
        $owner = $this->owner();
        $this->client->loginUser($owner);
        $token = (string) $this->upload($this->file('export.csv'))['token'];
        $file = $this->pendingDir.'/'.$owner->getId().'/'.$token.'.upload';

        $otherOwner = $this->owner('other-owner', 'Other Hospital');
        $this->client->loginUser($otherOwner);
        $this->client->request(Request::METHOD_POST, '/import/upload/'.$token.'/discard', ['_token' => $this->csrfToken()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertFileExists($file, 'Another user cannot discard the upload.');

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_POST, '/import/upload/'.$token.'/discard', ['_token' => $this->csrfToken()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertFileDoesNotExist($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(UploadedFile $file, string $replaces = ''): array
    {
        $this->client->request(Request::METHOD_POST, '/import/upload', ['_token' => $this->csrfToken(), 'replaces' => $replaces], ['file' => $file]);

        /** @var array<string, mixed> $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 4, \JSON_THROW_ON_ERROR);

        return $answer;
    }

    /**
     * The token the import form hands to the `import-upload` controller.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[data-import-upload-csrf-value]')->attr('data-import-upload-csrf-value');
    }

    private function file(string $name, string $content = "a;b\n", string $mime = 'text/csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pending-upload');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function owner(string $username = 'upload-owner', string $hospitalName = 'Upload Hospital'): User
    {
        $owner = UserFactory::createOne(['username' => $username, 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::findOrCreate(['name' => 'Hessen']);
        HospitalFactory::createOne([
            'name' => $hospitalName,
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['name' => $hospitalName.' Area', 'state' => $state]),
        ]);

        return $owner;
    }
}
