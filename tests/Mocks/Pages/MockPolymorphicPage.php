<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 * @property int $PolymorphicHasOneID
 * @property string $PolymorphicHasOneClass
 * @method DataObject PolymorphicHasOne()
 */
class MockPolymorphicPage extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockPolymorphicPage';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $has_one = [
        'PolymorphicHasOne' => DataObject::class,
    ];

    private static array $field_classname_map = [
        'PolymorphicHasOneID' => 'PolymorphicHasOneClass',
    ];

}
