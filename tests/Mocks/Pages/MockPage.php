<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages;

use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockElement;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockImage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockTag;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockThroughTarget;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Relations\MockThroughObject;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\ManyManyThroughList;

/**
 * @property string $Title
 * @property int $ImageID
 * @method MockImage Image()
 * @method HasManyList|MockElement[] Elements()
 * @method ManyManyList|MockTag[] Tags()
 * @method ManyManyThroughList|MockThroughTarget[] ThroughTargets()
 */
class MockPage extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockPage';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Image' => MockImage::class,
    ];

    private static array $has_many = [
        'Elements' => MockElement::class,
    ];

    private static array $many_many = [
        'Tags' => MockTag::class,
        'ThroughTargets' => [
            'through' => MockThroughObject::class,
            'from' => 'Parent',
            'to' => 'Target',
        ],
    ];

}
