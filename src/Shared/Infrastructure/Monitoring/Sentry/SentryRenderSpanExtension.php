<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\Profiler\NodeVisitor\ProfilerNodeVisitor;
use Twig\Profiler\Profile;

/**
 * One `view.render` span per top-level Twig render (a controller's page or frame, a Live Component re-render),
 * instead of the SDK's span per template and block (turned off in sentry.yaml).
 *
 * The span becomes the current span while the template renders, so queries that Twig triggers (lazy data,
 * nested components) show up as its children. Nested templates, blocks and macros get no span of their own.
 *
 * Twig calls leave() only when a template renders without an exception, so the state is reset when the kernel
 * handles an exception (before the error page renders) and between requests or messages (ResetInterface).
 *
 * @psalm-suppress UnusedClass Registered as a Twig extension by autoconfiguration.
 */
final class SentryRenderSpanExtension extends AbstractExtension implements ResetInterface
{
    private int $depth = 0;

    private ?Profile $rootProfile = null;

    private ?Span $span = null;

    private ?Span $parent = null;

    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    /**
     * Called by the compiled templates before a template, block or macro runs.
     */
    public function enter(Profile $profile): void
    {
        ++$this->depth;
        if (1 !== $this->depth || !$profile->isTemplate()) {
            return;
        }

        $parent = $this->hub->getSpan();
        if (!$parent instanceof Span) {
            return;
        }

        $this->rootProfile = $profile;
        $this->parent = $parent;
        $this->span = $parent->startChild(
            SpanContext::make()->setOp('view.render')->setOrigin('manual.view')->setDescription($profile->getTemplate())
        );
        $this->hub->setSpan($this->span);
    }

    /**
     * Called by the compiled templates after a template, block or macro has run.
     */
    public function leave(Profile $profile): void
    {
        $this->depth = max(0, $this->depth - 1);
        if ($profile !== $this->rootProfile || !$this->span instanceof Span) {
            return;
        }

        $this->span->finish();
        $this->hub->setSpan($this->parent);
        $this->clear();
    }

    /**
     * Ends a render that was left by an exception, so the error page and later renders are traced again.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 512)]
    #[\Override]
    public function reset(): void
    {
        if ($this->span instanceof Span) {
            $this->span->setStatus(SpanStatus::internalError());
            $this->span->finish();
            $this->hub->setSpan($this->parent);
        }
        $this->depth = 0;
        $this->clear();
    }

    private function clear(): void
    {
        $this->span = null;
        $this->parent = null;
        $this->rootProfile = null;
    }

    #[\Override]
    public function getNodeVisitors(): array
    {
        return [new ProfilerNodeVisitor(self::class)];
    }
}
