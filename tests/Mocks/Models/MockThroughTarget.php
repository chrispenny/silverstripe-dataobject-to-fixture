<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Models;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 */
class MockThroughTarget extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockThroughTarget';

    private static array $db = [
        'Title' => 'Varchar',
    ];

}
