<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Domain\Entity\Media;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The upload rules are plain attribute constraints on {@see Media::$file}, so a validator without the container
 * checks them.
 */
final class MediaUploadValidationTest extends TestCase
{
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $fixturesDir;

    #[\Override]
    protected function setUp(): void
    {
        // One directory per process: ParaTest workers run in parallel, the tests of one process run one after another.
        $this->fixturesDir = sys_get_temp_dir().'/media-upload-validation-test-'.getmypid();
        mkdir($this->fixturesDir, 0775, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixturesDir);
    }

    public function testValidPngPassesValidation(): void
    {
        $media = $this->mediaWithUpload('valid.png', base64_decode(self::ONE_PIXEL_PNG, true) ?: '', 'image/png');

        $violations = $this->validator()->validate($media, groups: ['Default', 'media_create']);

        self::assertCount(0, $violations);
    }

    public function testInvalidMimeIsRejected(): void
    {
        $media = $this->mediaWithUpload('evil.php', '<?php echo "x";', 'application/x-php');

        $violations = $this->validator()->validate($media, groups: ['Default', 'media_create']);

        self::assertSame(
            [['file', File::INVALID_MIME_TYPE_ERROR]],
            array_map(
                static fn (ConstraintViolationInterface $violation): array => [$violation->getPropertyPath(), $violation->getCode()],
                iterator_to_array($violations),
            ),
        );
    }

    public function testAMissingFileIsRejectedOnCreate(): void
    {
        $violations = $this->validator()->validate(new Media(), groups: ['media_create']);

        self::assertCount(1, $violations);
        self::assertSame('file', $violations->get(0)->getPropertyPath());
    }

    private function mediaWithUpload(string $name, string $content, string $clientMimeType): Media
    {
        $path = $this->fixturesDir.'/'.$name;
        file_put_contents($path, $content);

        $media = new Media();
        $media->setFile(new UploadedFile($path, $name, $clientMimeType, null, true));

        return $media;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
