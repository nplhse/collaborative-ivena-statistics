<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

use App\Encounter\Application\Catalog\MtsCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Renders the offline data preparation tool as one self-contained HTML file (ADR 026, #1051):
 * inlined scripts, the `ed_visit` field contract and the bundled public profiles. Hospital-specific
 * local maps are never bundled. The MTS catalog is bundled from `mts-de.json` as offline fallback; at start the tool
 * fetches the maintained catalog from the public endpoint (MtsCatalog, ADR 032, #1157).
 */
final readonly class DataPrepToolRenderer
{
    private const array SCRIPTS = ['js/data-prep-core.js', 'js/data-prep-app.js'];
    private const array PROFILES = ['profiles/orbis-mts.json'];
    private const string SCHEMA = 'schema/ed_visit.v1.json';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private DataPrepRelease $release,
        private MtsCatalog $mtsCatalog,
        #[Autowire('%kernel.project_dir%/src/Encounter/UI/DataPrep')]
        private string $sourceDirectory,
    ) {
    }

    public function render(): string
    {
        $scripts = array_map(function (string $path): string {
            $script = $this->read($path);
            if (false !== stripos($script, '</script')) {
                throw new \LogicException(sprintf('Script "%s" must not contain a closing script tag.', $path));
            }

            return $script;
        }, self::SCRIPTS);

        return $this->twig->render('@Encounter/data_prep/tool.html.twig', [
            'release' => $this->release,
            'latest_url' => $this->urlGenerator->generate('app_encounter_data_prep_latest', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'scripts' => $scripts,
            'schema' => $this->decode(self::SCHEMA),
            'profiles' => array_map($this->decode(...), self::PROFILES),
            'catalog_url' => $this->urlGenerator->generate('app_encounter_data_prep_mts_catalog', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'catalogs' => [MtsCatalog::ID => $this->mtsCatalog->bundledToolCatalog()],
        ]);
    }

    /**
     * Label and required header columns of each bundled profile, for the export step of the tool's
     * landing page (#1130).
     *
     * @return list<array{label: string, columns: list<string>}>
     */
    public function profileSummaries(): array
    {
        return array_map(function (string $path): array {
            /** @var array{label: string, detect: array{requiredColumns: list<string>}} $profile */
            $profile = $this->decode($path);

            return ['label' => $profile['label'], 'columns' => $profile['detect']['requiredColumns']];
        }, self::PROFILES);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($this->sourceDirectory.'/'.$path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Cannot read data preparation tool source "%s".', $path));
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        /* @var array<string, mixed> */
        return json_decode($this->read($path), true, 512, \JSON_THROW_ON_ERROR);
    }
}
