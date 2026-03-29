<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Models;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property string $Title
 */
class MockExcludedObject extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockExcludedObject';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static bool $exclude_from_fixture_relationships = true;

}
