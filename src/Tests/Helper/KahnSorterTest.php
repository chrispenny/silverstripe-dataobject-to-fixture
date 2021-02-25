<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Helper;

use ChrisPenny\DataObjectToFixture\Helper\KahnSorter;
use SilverStripe\Dev\SapphireTest;

/**
 * Shout out to Adrian Humphreys (@adrhumphreys) for providing this one.
 */
class KhanSorterTest extends SapphireTest
{

    public function testSorter(): void
    {
        $items = [
            'bigOlStew' => [
                'thingy',
                'pig',
                'cheeseDanish',
                'chicken',
            ],
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
            'thingy' => [
                'iron',
                'apple',
                'vanilla',
            ],
            'creamCheese' => [
                'milk',
                'salt',
            ],
            'chicken' => [
                'worm',
            ],
            'worm' => [
                'apple',
            ],
            'egg' => [
                'chicken',
            ],
            'milk' => [
                'cow',
            ],
            'cow' => [
                'grass',
            ],
            'pig' => [
                'apple',
                'worm',
            ],
        ];

        $sorter = new KahnSorter($items);
        $results = $sorter->sort();

        $this->assertEquals(
            [
                'iron',
                'apple',
                'vanilla',
                'thingy',
                'worm',
                'pig',
                'flour',
                'grass',
                'cow',
                'milk',
                'salt',
                'butter',
                'chicken',
                'egg',
                'creamCheese',
                'sugar',
                'cheeseDanish',
                'bigOlStew',
            ],
            $results
        );
    }

    public function testEmptySort(): void
    {
        $sorter = new KahnSorter([]);
        $results = $sorter->sort();

        $this->assertEquals([], $results);
    }

}
