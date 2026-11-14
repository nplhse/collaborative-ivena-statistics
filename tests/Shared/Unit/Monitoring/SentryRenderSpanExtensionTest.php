<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryRenderSpanExtension;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Twig\Profiler\Profile;

final class SentryRenderSpanExtensionTest extends TestCase
{
    private Hub $hub;

    private Transaction $transaction;

    private SentryRenderSpanExtension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->hub = new Hub(ClientBuilder::create()->getClient());
        $this->transaction = new Transaction(TransactionContext::make(), $this->hub);
        $this->transaction->initSpanRecorder();
        $this->hub->setSpan($this->transaction);
        $this->extension = new SentryRenderSpanExtension($this->hub);
    }

    public function testOneSpanForTheOutermostTemplateOnly(): void
    {
        $page = new Profile('@Statistics/dashboard/index.html.twig', Profile::TEMPLATE, '@Statistics/dashboard/index.html.twig');
        $block = new Profile('@Statistics/dashboard/index.html.twig', Profile::BLOCK, 'body');
        $partial = new Profile('@Statistics/dashboard/_kpi.html.twig', Profile::TEMPLATE, '@Statistics/dashboard/_kpi.html.twig');

        $this->extension->enter($page);
        $render = $this->hub->getSpan();
        $this->extension->enter($block);
        $this->extension->enter($partial);
        self::assertSame($render, $this->hub->getSpan(), 'Nested templates and blocks open no span.');
        $this->extension->leave($partial);
        $this->extension->leave($block);
        $this->extension->leave($page);

        self::assertSame($this->transaction, $this->hub->getSpan());
        $renders = $this->renderSpans();
        self::assertCount(1, $renders);
        self::assertSame('@Statistics/dashboard/index.html.twig', $renders[0]->getDescription());
        self::assertNotNull($renders[0]->getEndTimestamp());
    }

    public function testEachTopLevelRenderGetsItsOwnSpan(): void
    {
        foreach (['frame_a.html.twig', 'frame_b.html.twig'] as $template) {
            $profile = new Profile($template, Profile::TEMPLATE, $template);
            $this->extension->enter($profile);
            $this->extension->leave($profile);
        }

        self::assertCount(2, $this->renderSpans());
    }

    public function testResetEndsARenderLeftByAnException(): void
    {
        $page = new Profile('page.html.twig', Profile::TEMPLATE, 'page.html.twig');
        $this->extension->enter($page);
        $this->extension->enter(new Profile('page.html.twig', Profile::BLOCK, 'body'));

        $this->extension->reset();

        self::assertSame($this->transaction, $this->hub->getSpan());
        self::assertEquals(SpanStatus::internalError(), $this->renderSpans()[0]->getStatus());

        $error = new Profile('error.html.twig', Profile::TEMPLATE, 'error.html.twig');
        $this->extension->enter($error);
        $this->extension->leave($error);
        self::assertCount(2, $this->renderSpans(), 'The error page is traced again.');
    }

    public function testNoSpanWithoutATransaction(): void
    {
        $hub = new Hub(ClientBuilder::create()->getClient());
        $extension = new SentryRenderSpanExtension($hub);
        $page = new Profile('page.html.twig', Profile::TEMPLATE, 'page.html.twig');

        $extension->enter($page);
        $extension->leave($page);

        self::assertNull($hub->getSpan());
    }

    /**
     * @return list<Span>
     */
    private function renderSpans(): array
    {
        return array_values(array_filter(
            $this->transaction->getSpanRecorder()?->getSpans() ?? [],
            static fn (Span $span): bool => 'view.render' === $span->getOp(),
        ));
    }
}
