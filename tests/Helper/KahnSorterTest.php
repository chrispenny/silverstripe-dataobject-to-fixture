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

}
