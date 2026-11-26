<?php

declare(strict_types=1);

/*
 * Messages queued before #1300 name the classes they had in the core. The Doctrine transports store them
 * PHP-serialized, so a worker of the new release must still resolve the old names: this autoloader defines an
 * old name as an alias of the plugin class when a stored message asks for it. Remove an entry only once neither
 * transport (async_priority_low, failed) holds messages with the old name (docs/05-operations/upgrading.md).
 */
spl_autoload_register(static function (string $class): void {
    $aliases = [
        'App\Encounter\Application\Message\MatchEncountersMessage' => Cois\Plugin\EdVisit\Application\Message\MatchEncountersMessage::class,
    ];

    if (isset($aliases[$class])) {
        class_alias($aliases[$class], $class);
    }
});
