<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Matching;

use Cois\Plugin\EdVisit\Application\Matching\MatchScope;
use Cois\Plugin\EdVisit\Application\Message\MatchEncountersMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @psalm-suppress UnusedClass Registered as a message handler.
 */
#[AsMessageHandler]
final readonly class MatchEncountersMessageHandler
{
    public function __construct(
        private EncounterMatcher $matcher,
    ) {
    }

    public function __invoke(MatchEncountersMessage $message): void
    {
        if ([] === $message->days) {
            return;
        }

        $this->matcher->run(new MatchScope($message->hospitalId, $message->days, $message->ignoreImportId));
    }
}
