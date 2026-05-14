<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Uses the Silverstripe array-format polymorphic has_one definition.
 *
 * @property string $Title
 * @property int $OwnerID
 * @property string $OwnerClass
 * @method DataObject Owner()
 */
class MockNativePoly extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockNativePoly';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'Owner' => [
            'class' => DataObject::class,
            'type' => 'polymorphic',
        ],
    ];

    private static array $field_classname_map = [
        'OwnerID' => 'OwnerClass',
    ];

}
