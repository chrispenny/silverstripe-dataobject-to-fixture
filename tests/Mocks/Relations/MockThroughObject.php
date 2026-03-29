<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Relations;

use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockThroughTarget;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPage;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property int $ParentID
 * @property int $TargetID
 * @method MockPage Parent()
 * @method MockThroughTarget Target()
 */
class MockThroughObject extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockThroughObject';

    private static array $has_one = [
        'Parent' => MockPage::class,
        'Target' => MockThroughTarget::class,
    ];

}
