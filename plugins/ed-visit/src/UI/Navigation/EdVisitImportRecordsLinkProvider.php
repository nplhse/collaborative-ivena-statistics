<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Navigation;

use App\Encounter\Contract\Display\ImportRecordsLink;
use App\Encounter\Contract\Display\ImportRecordsLinkProviderInterface;
use Cois\Plugin\EdVisit\Application\Dimension\EdVisitDimension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Links an ED visit import to the visit list of its hospital, filtered to the visits of that import (#1413).
 *
 * @psalm-suppress UnusedClass Tagged via ImportRecordsLinkProviderInterface.
 */
final readonly class EdVisitImportRecordsLinkProvider implements ImportRecordsLinkProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function linkForImport(string $schema, int $importId, string $hospitalPublicId): ?ImportRecordsLink
    {
        if (EdVisitDimension::SCHEMA !== $schema) {
            return null;
        }

        return new ImportRecordsLink(
            new TranslatableMessage('encounter.import.action.open_visits', domain: 'encounter'),
            $this->urlGenerator->generate('app_explore_ed_visit_list', ['publicId' => $hospitalPublicId, 'importId' => $importId]),
            'tabler:stethoscope',
        );
    }
}
