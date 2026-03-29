<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Helper;

use ChrisPenny\DataObjectToFixture\Helper\KahnSorter;
use SilverStripe\Dev\SapphireTest;

/**
 * Shout out to Adrian Humphreys (@adrhumphreys) for providing this one.
 *
 * @phpcs:disable
 */
class KhanSorterTest extends SapphireTest
{

    public function testSorter(): void
    {
        $items = [
            'cheeseDanish' => [
                'flour',
                'butter',
                'egg',
                'vanilla',
                'creamCheese',
                'sugar',
            ],
            'butter' => [
                'milk',
                'salt',
            ],
            'creamCheese' => [
                'milk',
                'salt',
            ],
            'egg' => [
                'chicken',
            ],
            'milk' => [
                'cow',
            ],
            'cow' => [],
            'chicken' => [],
        ];

        $expected = [
            'cow',
            'salt',
            'milk',
            'chicken',
            'sugar',
            'creamCheese',
            'vanilla',
            'egg',
            'butter',
            'flour',
            'cheeseDanish',
        ];

        $sorter = new KahnSorter();
        $results = $sorter->process($items);

        $this->assertEquals($expected, $results);
    }

    public function testEmptySort(): void
    {
        $sorter = new KahnSorter();
        $results = $sorter->process([]);

        $this->assertEquals([], $results);
    }

    public function testCircularDependency(): void
    {
        $items = [
            'A' => ['B'],
            'B' => ['A'],
        ];

        $sorter = new KahnSorter();
        $results = $sorter->process($items);

        // Both nodes should still be in the output
        $this->assertContains('A', $results);
        $this->assertContains('B', $results);

        // Both nodes have 1 left over dependency due to the circular reference
        $this->assertEqualsCanonicalizing(
            [
                'Node `A` has `1` left over dependencies, and so could not be sorted',
                'Node `B` has `1` left over dependencies, and so could not be sorted',
            ],
            $sorter->getWarnings()
        );
    }

    public function testSingleNode(): void
    {
        $items = [
            'A' => [],
        ];

        $sorter = new KahnSorter();
        $results = $sorter->process($items);

        $this->assertSame(['A'], $results);
        $this->assertEmpty($sorter->getWarnings());
    }

    public function testConvergentDependencies(): void
    {
        // Both A and B depend on C
        $items = [
            'A' => ['C'],
            'B' => ['C'],
            'C' => [],
        ];

        $sorter = new KahnSorter();
        $results = $sorter->process($items);

        // C must appear before both A and B; the relative order of A and B doesn't matter
        $cIndex = array_search('C', $results, true);
        $aIndex = array_search('A', $results, true);
        $bIndex = array_search('B', $results, true);

        $this->assertLessThan($aIndex, $cIndex);
        $this->assertLessThan($bIndex, $cIndex);
        $this->assertSame([], $sorter->getWarnings());
    }

    public function testLinearChain(): void
    {
        $items = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['D'],
            'D' => [],
        ];

        $sorter = new KahnSorter();
        $results = $sorter->process($items);

        $this->assertSame(['D', 'C', 'B', 'A'], $results);
        $this->assertEmpty($sorter->getWarnings());
    }

    public function testHasProcessed(): void
    {
        $sorter = new KahnSorter();

        $this->assertFalse($sorter->hasProcessed());

        $sorter->process([]);

        $this->assertTrue($sorter->hasProcessed());
    }

    public function testGetSortedNodesAfterProcess(): void
    {
        $sorter = new KahnSorter();
        $sorter->process(['A' => ['B'], 'B' => []]);

        $this->assertSame(['B', 'A'], $sorter->getSortedNodes());
    }

    public function testGetMessages(): void
    {
        $sorter = new KahnSorter();
        $sorter->process(['A' => ['B'], 'B' => []]);

        $this->assertEqualsCanonicalizing(
            [
                '[Dependency resolution] A depends on [B]',
                '[Dependency resolution] B depends on []',
            ],
            $sorter->getMessages()
        );
    }

    public function testUnspecifiedDependenciesAreCreated(): void
    {
        // 'B' is referenced as a dependency but not defined as a key
        $items = [
            'A' => ['B'],
        ];

        $sorter = new KahnSorter();
        $results = $sorter->process($items);

        // Both should appear in output, with B before A
        $this->assertSame(['B', 'A'], $results);
        $this->assertEmpty($sorter->getWarnings());
    }

    public function testThreeNodeCycle(): void
    {
        $items = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ];

        $sorter = new KahnSorter();
        $results = $sorter->process($items);

        // All nodes are in a cycle, so none can be sorted normally — they're all present but in arbitrary order
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $results);

        $this->assertEqualsCanonicalizing(
            [
                'Node `A` has `1` left over dependencies, and so could not be sorted',
                'Node `B` has `1` left over dependencies, and so could not be sorted',
                'Node `C` has `1` left over dependencies, and so could not be sorted',
            ],
            $sorter->getWarnings()
        );
    }

    public function testProcessResetsState(): void
    {
        $sorter = new KahnSorter();

        // First process with warnings
        $sorter->process(['A' => ['B'], 'B' => ['A']]);
        $this->assertNotEmpty($sorter->getWarnings());

        // Second process without warnings — state should be reset
        $sorter->process(['A' => ['B'], 'B' => []]);
        $this->assertEmpty($sorter->getWarnings());
    }

}
