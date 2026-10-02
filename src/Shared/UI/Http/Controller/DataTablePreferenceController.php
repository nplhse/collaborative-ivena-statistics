<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Application\DataTable\DataTablePreferenceDefinitionRegistry;
use App\Shared\Application\DataTable\DataTablePreferenceSchema;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\UI\Http\SafeRedirectTargetResolver;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DataTablePreferenceController extends AbstractController
{
    #[Route('/account/data-table-preferences', name: 'app_data_table_preferences_save', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        DataTablePreferenceDefinitionRegistry $registry,
        DataTablePreferenceService $preferences,
        SafeRedirectTargetResolver $redirectTargetResolver,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        $body = $request->request->all();
        $tableKey = \is_string($body['tableKey'] ?? null) ? $body['tableKey'] : '';
        $schema = $registry->get($tableKey);
        if (!$schema instanceof DataTablePreferenceSchema) {
            throw $this->createNotFoundException();
        }

        $token = \is_string($body['_token'] ?? null) ? $body['_token'] : '';
        if (!$this->isCsrfTokenValid('data_table_preference_'.$tableKey, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = \is_string($body['action'] ?? null) ? $body['action'] : 'save';
        if ('reset' === $action) {
            $preferences->reset($user, $tableKey);
        } elseif ('reset-columns' === $action) {
            $preferences->resetColumns($user, $tableKey);
        } elseif ('reset-sort' === $action) {
            $preferences->resetSort($user, $tableKey);
        } else {
            $configuration = [
                'visibleColumns' => \is_array($body['visibleColumns'] ?? null) ? $body['visibleColumns'] : [],
                'columnOrder' => \is_array($body['columnOrder'] ?? null) ? $body['columnOrder'] : [],
                'pageSize' => $this->pageSize($body['pageSize'] ?? null, $schema->defaultPageSize),
            ];
            if (\is_string($body['sortBy'] ?? null) && '' !== $body['sortBy']) {
                $configuration['sortBy'] = $body['sortBy'];
            }
            if (\is_string($body['orderBy'] ?? null) && '' !== $body['orderBy']) {
                $configuration['orderBy'] = $body['orderBy'];
            }
            $preferences->save($user, $tableKey, $configuration);
        }

        $candidate = \is_string($body['returnUrl'] ?? null) ? $body['returnUrl'] : null;
        $target = $redirectTargetResolver->resolve($candidate, $request, '/');

        return $this->redirect($this->withoutQueryKeys($target, match ($action) {
            'reset' => ['columns', 'columnOrder', 'limit', 'page', 'cursor', 'after', 'before', 'sortBy', 'orderBy', 'unitsColumns', 'unitsColumnOrder', 'unitsLimit', 'unitsPage', 'unitsSort', 'unitsOrder'],
            'reset-columns' => ['columns', 'columnOrder', 'unitsColumns', 'unitsColumnOrder'],
            'reset-sort' => ['sortBy', 'orderBy', 'limit', 'page', 'cursor', 'after', 'before', 'unitsSort', 'unitsOrder', 'unitsLimit', 'unitsPage'],
            default => ['columns', 'columnOrder', 'limit', 'page', 'cursor', 'after', 'before', 'unitsColumns', 'unitsColumnOrder', 'unitsLimit', 'unitsPage', 'unitsSort', 'unitsOrder'],
        }));
    }

    private function pageSize(mixed $value, int $default): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            return $default;
        }
        $filtered = filter_var($value, \FILTER_VALIDATE_INT);

        return false === $filtered ? $default : $filtered;
    }

    /**
     * @param list<string> $keys
     */
    private function withoutQueryKeys(string $url, array $keys): string
    {
        $parts = parse_url($url);
        if (false === $parts) {
            return '/';
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ($keys as $key) {
            unset($query[$key]);
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $base = \is_string($scheme) && '' !== $scheme && \is_string($host) && '' !== $host
            ? $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '')
            : '';
        $path = $parts['path'] ?? '/';
        $queryString = http_build_query($query);

        return $base.$path.('' === $queryString ? '' : '?'.$queryString);
    }
}
