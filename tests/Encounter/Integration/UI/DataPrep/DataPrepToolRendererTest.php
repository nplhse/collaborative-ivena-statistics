<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\UI\DataPrep;

use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\UI\DataPrep\DataPrepRelease;
use App\Encounter\UI\DataPrep\DataPrepToolRenderer;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * The renderer adds the key rules of the dimension to a schema file and refuses a file with its own copy (#1301).
 */
final class DataPrepToolRendererTest extends KernelTestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/data-prep-renderer-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testASchemaFileWithItsOwnKeyRulesIsRefused(): void
    {
        $this->expectExceptionMessage('must not contain key rules');
        $this->renderer()->schema($this->file('keys.json', ['schema' => 'ed_visit', 'version' => 1, 'fields' => [], 'keys' => []]));
    }

    public function testASchemaFileWithoutNameOrVersionIsRefused(): void
    {
        $this->expectExceptionMessage('has no schema name and version');
        $this->renderer()->schema($this->file('nameless.json', ['fields' => []]));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function file(string $name, array $content): string
    {
        file_put_contents($this->directory.'/'.$name, json_encode($content, \JSON_THROW_ON_ERROR));

        return $name;
    }

    private function renderer(): DataPrepToolRenderer
    {
        self::bootKernel();
        $container = self::getContainer();

        return new DataPrepToolRenderer(
            $this->service($container->get(Environment::class), Environment::class),
            $this->service($container->get(UrlGeneratorInterface::class), UrlGeneratorInterface::class),
            $this->service($container->get(DataPrepRelease::class), DataPrepRelease::class),
            $this->service($container->get(MtsCatalog::class), MtsCatalog::class),
            $this->service($container->get(EncounterDimensionRegistry::class), EncounterDimensionRegistry::class),
            $this->directory,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(?object $service, string $class): object
    {
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
