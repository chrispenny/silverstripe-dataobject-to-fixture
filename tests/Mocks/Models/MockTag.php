<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Mocks\Models;

use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPage;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;

/**
 * @property string $Title
 * @method ManyManyList|MockPage[] Pages()
 */
class MockTag extends DataObject implements TestOnly
{

    private static string $table_name = 'DOToFixture_MockTag';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $belongs_many_many = [
        'Pages' => MockPage::class,
    ];

}
