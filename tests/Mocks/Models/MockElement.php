<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Models;

use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPage;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property int $Sort
 * @property int $ParentID
 * @method MockPage Parent()
 */
class MockElement extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockElement';

    private static array $db = [
        'Title' => 'Varchar',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Parent' => MockPage::class,
    ];

}
