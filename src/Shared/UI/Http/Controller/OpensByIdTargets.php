<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\UI\Http\Routing\OpensById;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds the Explore detail route marked with {@see OpensById} for an object type (the `{type}`
 * path segment), so new detail pages only need the attribute (#1354).
 */
final readonly class OpensByIdTargets
{
    private const string PATH = '#^/explore/([a-z_]+)/\{\w+\}$#';

    public function __construct(
        private RouterInterface $router,
    ) {
    }

    public function find(string $type): ?OpensByIdTarget
    {
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if (1 !== preg_match(self::PATH, $route->getPath(), $match) || $match[1] !== $type) {
                continue;
            }

            $attribute = $this->attributeOf($route->getDefault('_controller'));
            $variables = $route->compile()->getPathVariables();
            if (!$attribute instanceof OpensById || 1 !== \count($variables)) {
                continue;
            }

            return new OpensByIdTarget($name, $attribute->entity, $variables[0]);
        }

        return null;
    }

    private function attributeOf(mixed $controller): ?OpensById
    {
        if (!\is_string($controller)) {
            return null;
        }

        $parts = explode('::', $controller, 2);
        $class = $parts[0];
        $method = $parts[1] ?? '__invoke';
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        $attributes = new \ReflectionMethod($class, $method)->getAttributes(OpensById::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
