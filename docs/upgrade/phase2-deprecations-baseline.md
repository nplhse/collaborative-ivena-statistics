# Phase 2: Deprecation-Baseline (vor Upgrade)

Ermittelt am Start des Upgrade-Projekts (Symfony 7.4, Doctrine Bundle 2.18).

## Warum PHPUnit keine Deprecations zeigt

`phpunit.dist.xml` setzt `ignoreIndirectDeprecations="true"`. Vendor-Deprecations (Doctrine) werden dadurch nicht als Testfehler gewertet.

## Erwartete / dokumentierte Deprecations

| Quelle | Meldung | Bibliothek | Risiko | Lösung |
|--------|---------|------------|--------|--------|
| Doctrine Boot | `Proxy\Autoloader` deprecated | doctrine/orm + doctrine-bundle 2.18 | Hoch | `enable_native_lazy_objects: true`, Bundle 3.x |
| Doctrine ORM 3.6 | Ghost objects auf PHP 8.4+ | doctrine/orm | Hoch | Native lazy objects |
| Doctrine ORM 3.6 | `options['default']` in Column mappings | doctrine/orm | Mittel | `DefaultExpression` |
| Symfony 8 | `User::eraseCredentials()` | symfony/security | Hoch | Methode entfernen (Phase 4) |
| Sentry | `Debug\Exception\FatalErrorException` | sentry/symfony | Niedrig | Config anpassen |

## Prüfmatrix (Befehle)

```bash
SYMFONY_DEPRECATIONS_HELPER='max[total]=0&verbose=1' php bin/console cache:warmup
SYMFONY_DEPRECATIONS_HELPER='max[total]=0&verbose=1' php bin/console lint:container
SYMFONY_DEPRECATIONS_HELPER='max[total]=0&verbose=1' php bin/console doctrine:schema:validate
SYMFONY_DEPRECATIONS_HELPER='max[total]=0&verbose=1' php bin/console messenger:consume async_priority_high async_priority_low scheduler_default --time-limit=30 -vv
XDEBUG_MODE=off SYMFONY_DEPRECATIONS_HELPER='max[total]=0&verbose=1' vendor/bin/phpunit --no-coverage
```
