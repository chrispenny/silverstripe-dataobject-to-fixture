<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Models;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Name
 */
class MockImage extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockImage';

    private static array $db = [
        'Name' => 'Varchar',
    ];

}
