<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

use App\Encounter\Contract\DataPrep\DataPrepCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Renders the offline data preparation tool as one self-contained HTML file (ADR 026, #1051): the generic core, the
 * script modules of the schema contributions and the app, inlined; the schemas with their key rules, the public
 * profiles and the bundled catalogs (#1301). Hospital-specific local maps are never bundled. At start the tool
 * fetches the maintained catalogs from their public endpoints (ADR 032, #1157); without network it uses the
 * bundled copies.
 */
final readonly class DataPrepToolRenderer
{
    private const string CORE = 'js/data-prep-core.js';

    private const string APP = 'js/data-prep-app.js';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private DataPrepRelease $release,
        private DataPrepContributions $contributions,
        #[Autowire('%kernel.project_dir%/src/Encounter/UI/DataPrep')]
        private string $sourceDirectory,
    ) {
    }

    public function render(): string
    {
        $scripts = array_map(static function (string $script): string {
            if (false !== stripos($script, '</script')) {
                throw new \LogicException('A data preparation script must not contain a closing script tag.');
            }

            return $script;
        }, [
            $this->contributions->read($this->sourceDirectory.'/'.self::CORE),
            ...$this->contributions->scripts(),
            $this->contributions->read($this->sourceDirectory.'/'.self::APP),
        ]);
        $catalogs = $this->contributions->catalogs();
        $catalogUrls = [];
        foreach ($catalogs as $catalog) {
            if (null !== $catalog->route) {
                $catalogUrls[$catalog->id] = $this->urlGenerator->generate($catalog->route, [], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        return $this->twig->render('@Encounter/data_prep/tool.html.twig', [
            'release' => $this->release,
            'latest_url' => $this->urlGenerator->generate('app_encounter_data_prep_latest', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'scripts' => $scripts,
            'schemas' => $this->contributions->schemas(),
            'profiles' => $this->contributions->profiles(),
            'catalog_urls' => $catalogUrls,
            'catalogs' => array_combine(
                array_map(static fn (DataPrepCatalog $catalog): string => $catalog->id, $catalogs),
                array_map(static fn (DataPrepCatalog $catalog): array => $catalog->payload, $catalogs),
            ),
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
        return array_map(
            /* @var array{label: string, detect: array{requiredColumns: list<string>}} $profile */
            static fn (array $profile): array => ['label' => $profile['label'], 'columns' => $profile['detect']['requiredColumns']], $this->contributions->profiles());
    }
}
