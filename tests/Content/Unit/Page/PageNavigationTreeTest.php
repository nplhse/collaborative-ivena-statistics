<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Application\Page\PageNavigationTree;
use App\Content\Domain\Enum\PageKey;
use PHPUnit\Framework\TestCase;

/**
 * Tree used in every test (IDs in brackets):
 *
 *     A [1]
 *     ├─ A2 [3]
 *     │  └─ A2a [4]
 *     └─ A1 [2]
 *     B [5] (key: faq)
 *     └─ B1 [6]
 *     C [7]
 */
final class PageNavigationTreeTest extends TestCase
{
    private PageNavigationTree $tree;

    #[\Override]
    protected function setUp(): void
    {
        $this->tree = new PageNavigationTree();
    }

    public function testFullTreeWithoutCurrentPageIsCollapsedAndUnmarked(): void
    {
        $nodes = $this->tree->select($this->fixture(), null, false, null);

        self::assertSame(['A', 'B', 'C'], $this->titles($nodes));
        self::assertSame([], $this->flagged($nodes, 'expanded'));
        self::assertSame([], $this->flagged($nodes, 'current'));
        self::assertSame([], $this->flagged($nodes, 'ancestor'));
    }

    public function testCurrentLeafMarksAndExpandsItsAncestorsOnly(): void
    {
        $nodes = $this->tree->select($this->fixture(), null, false, 4);

        self::assertSame(['A2a'], $this->flagged($nodes, 'current'));
        self::assertSame(['A', 'A2'], $this->flagged($nodes, 'ancestor'));
        // A2a has no children, so it is never "expanded"; B stays collapsed.
        self::assertSame(['A', 'A2'], $this->flagged($nodes, 'expanded'));
    }

    public function testCurrentPageWithChildrenIsExpanded(): void
    {
        $nodes = $this->tree->select($this->fixture(), null, false, 3);

        self::assertSame(['A2'], $this->flagged($nodes, 'current'));
        self::assertSame(['A'], $this->flagged($nodes, 'ancestor'));
        self::assertSame(['A', 'A2'], $this->flagged($nodes, 'expanded'));
    }

    public function testSubtreeWithoutRootStartsWithItsChildrenInOrder(): void
    {
        $nodes = $this->tree->select($this->fixture(), 1, false, null);

        self::assertSame(['A2', 'A1'], $this->titles($nodes));
        self::assertSame([], $this->flagged($nodes, 'expanded'));
    }

    public function testSubtreeWithRootShowsTheRootExpanded(): void
    {
        $nodes = $this->tree->select($this->fixture(), 1, true, null);

        self::assertSame(['A'], $this->titles($nodes));
        self::assertSame(['A2', 'A1'], $this->titles($nodes[0]->children));
        self::assertSame(['A'], $this->flagged($nodes, 'expanded'));
    }

    public function testCurrentPageOutsideTheSubtreeMarksNothing(): void
    {
        $nodes = $this->tree->select($this->fixture(), 1, false, 6);

        self::assertSame([], $this->flagged($nodes, 'current'));
        self::assertSame([], $this->flagged($nodes, 'ancestor'));
        self::assertSame([], $this->flagged($nodes, 'expanded'));
    }

    public function testCurrentRootIsMarkedOnlyWhenIncluded(): void
    {
        $withoutRoot = $this->tree->select($this->fixture(), 1, false, 1);
        $withRoot = $this->tree->select($this->fixture(), 1, true, 1);

        self::assertSame([], $this->flagged($withoutRoot, 'current'));
        self::assertSame(['A'], $this->flagged($withRoot, 'current'));
    }

    public function testUnknownRootGivesNoEntries(): void
    {
        self::assertSame([], $this->tree->select($this->fixture(), 99, false, null));
        self::assertSame([], $this->tree->select($this->fixture(), 99, true, null));
    }

    public function testLeafRootWithoutRootGivesNoEntries(): void
    {
        self::assertSame([], $this->tree->select($this->fixture(), 7, false, null));
    }

    public function testFindByKeySearchesNestedNodes(): void
    {
        self::assertSame(5, $this->tree->findByKey($this->fixture(), PageKey::Faq)?->pageId);
        self::assertNull($this->tree->findByKey($this->fixture(), PageKey::Imprint));
    }

    public function testFullTreeLeavesOutHelpArticlesButKeepsTheHelpStartPage(): void
    {
        $nodes = $this->tree->select($this->helpFixture(), null, false, 12);

        self::assertSame(['A', 'Help'], $this->titles($nodes));
        self::assertSame([], $nodes[1]->children, 'articles of the help start page are left out');
        self::assertSame([], $this->flagged($nodes, 'current'), 'a current help article is not marked in the page tree');
    }

    public function testHelpSubtreeShowsTheArticles(): void
    {
        $nodes = $this->tree->select($this->helpFixture(), 10, true, 12);

        self::assertSame(['Help'], $this->titles($nodes));
        self::assertSame(['Topic 1', 'Topic 2'], $this->titles($nodes[0]->children));
        self::assertSame(['Article 1b'], $this->flagged($nodes, 'current'));
        self::assertSame(['Help', 'Topic 1'], $this->flagged($nodes, 'ancestor'));
    }

    public function testHelpArticlesAreListedInReadingOrder(): void
    {
        self::assertSame(
            ['Topic 1', 'Article 1a', 'Article 1b', 'Topic 2'],
            $this->titles($this->tree->helpArticles($this->helpFixture())),
        );
        self::assertSame([], $this->tree->helpArticles($this->fixture()), 'no help start page, no articles');
    }

    /**
     * @return list<PageNavigationNode>
     */
    private function helpFixture(): array
    {
        return [
            $this->node(1, 'A', children: [$this->node(2, 'A1')]),
            $this->node(10, 'Help', PageKey::Help, [
                $this->helpNode(11, 'Topic 1', [$this->helpNode(13, 'Article 1a'), $this->helpNode(12, 'Article 1b')]),
                $this->helpNode(14, 'Topic 2'),
            ]),
        ];
    }

    /**
     * @param list<PageNavigationNode> $children
     */
    private function helpNode(int $id, string $title, array $children = []): PageNavigationNode
    {
        return new PageNavigationNode($id, null, $title, '/help/'.$id, $children, help: true);
    }

    /**
     * @return list<PageNavigationNode>
     */
    private function fixture(): array
    {
        return [
            $this->node(1, 'A', children: [
                $this->node(3, 'A2', children: [$this->node(4, 'A2a')]),
                $this->node(2, 'A1'),
            ]),
            $this->node(5, 'B', PageKey::Faq, [$this->node(6, 'B1')]),
            $this->node(7, 'C'),
        ];
    }

    /**
     * @param list<PageNavigationNode> $children
     */
    private function node(int $id, string $title, ?PageKey $key = null, array $children = []): PageNavigationNode
    {
        return new PageNavigationNode($id, $key, $title, '/'.strtolower($title), $children);
    }

    /**
     * @param list<PageNavigationNode> $nodes
     *
     * @return list<string>
     */
    private function titles(array $nodes): array
    {
        return array_map(static fn (PageNavigationNode $node): string => $node->title, $nodes);
    }

    /**
     * Titles of all nodes (depth first) whose flag is true.
     *
     * @param list<PageNavigationNode>        $nodes
     * @param 'current'|'ancestor'|'expanded' $flag
     *
     * @return list<string>
     */
    private function flagged(array $nodes, string $flag): array
    {
        $titles = [];
        foreach ($nodes as $node) {
            if ($node->{$flag}) {
                $titles[] = $node->title;
            }
            $titles = [...$titles, ...$this->flagged($node->children, $flag)];
        }

        return $titles;
    }
}
