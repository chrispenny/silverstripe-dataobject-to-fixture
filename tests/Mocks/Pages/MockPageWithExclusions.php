<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages;

use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockImage;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property int $ImageID
 * @method MockImage Image()
 */
class MockPageWithExclusions extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockPageWithExclusions';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Image' => MockImage::class,
    ];

    private static array $excluded_fixture_relationships = [
        'Image',
    ];

}
