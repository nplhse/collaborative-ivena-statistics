<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Domain\Entity\Media;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class MediaUploadValidationTest extends KernelTestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir().'/media_fixtures_'.bin2hex(random_bytes(4));
        mkdir($this->fixturesDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixturesDir)) {
            array_map(unlink(...), glob($this->fixturesDir.'/*') ?: []);
            rmdir($this->fixturesDir);
        }

        parent::tearDown();
    }

    public function testValidPngPassesValidation(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $path = $this->fixturesDir.'/valid.png';
        file_put_contents(
            $path,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '',
        );

        $media = new Media();
        $media->setFile(new UploadedFile($path, 'valid.png', 'image/png', null, true));

        $violations = $validator->validate($media, groups: ['Default', 'media_create']);

        self::assertCount(0, $violations);
    }

    public function testInvalidMimeIsRejected(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $path = $this->fixturesDir.'/evil.php';
        file_put_contents($path, '<?php echo "x";');

        $media = new Media();
        $media->setFile(new UploadedFile($path, 'evil.php', 'application/x-php', null, true));

        $violations = $validator->validate($media, groups: ['Default', 'media_create']);

        self::assertGreaterThan(0, $violations->count());
    }
}
