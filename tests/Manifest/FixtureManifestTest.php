<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Manifest;

use ChrisPenny\DataObjectToFixture\Manifest\FixtureManifest;
use ChrisPenny\DataObjectToFixture\ORM\Group;
use ChrisPenny\DataObjectToFixture\ORM\Record;
use SilverStripe\Dev\SapphireTest;

/**
 * @phpcs:disable
 */
class FixtureManifestTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testAddGroupAndGetByClassName(): void
    {
        $manifest = new FixtureManifest();
        $group = Group::create('App\Model\Page');

        $manifest->addGroup($group);

        $this->assertSame($group, $manifest->getGroupByClassName('App\Model\Page'));
    }

    public function testGetGroupByClassNameReturnsNullWhenNotFound(): void
    {
        $manifest = new FixtureManifest();

        $this->assertNull($manifest->getGroupByClassName('App\Model\NonExistent'));
    }

    public function testAddGroupOverwritesSameClassName(): void
    {
        $manifest = new FixtureManifest();
        $group1 = Group::create('App\Model\Page');
        $group2 = Group::create('App\Model\Page');

        $manifest->addGroup($group1);
        $manifest->addGroup($group2);

        // assertSame checks object identity (===) — the returned instance must be $group2, not $group1
        $this->assertSame($group2, $manifest->getGroupByClassName('App\Model\Page'));
        $this->assertNotSame($group1, $manifest->getGroupByClassName('App\Model\Page'));
    }

    public function testGetRecordByClassNameId(): void
    {
        $manifest = new FixtureManifest();
        $group = Group::create('App\Model\Page');
        $record = Record::create(42);
        $record->addFieldValue('Title', 'Test');
        $group->addRecord($record);
        $manifest->addGroup($group);

        $result = $manifest->getRecordByClassNameId('App\Model\Page', 42);

        $this->assertSame($record, $result);
    }

    public function testGetRecordByClassNameIdReturnsNullForInvalidClass(): void
    {
        $manifest = new FixtureManifest();

        $this->assertNull($manifest->getRecordByClassNameId('NonExistent', 1));
    }

    public function testGetRecordByClassNameIdReturnsNullForInvalidId(): void
    {
        $manifest = new FixtureManifest();
        $group = Group::create('App\Model\Page');
        $manifest->addGroup($group);

        $this->assertNull($manifest->getRecordByClassNameId('App\Model\Page', 999));
    }

    public function testGetGroups(): void
    {
        $manifest = new FixtureManifest();
        $group1 = Group::create('App\Model\Page');
        $group2 = Group::create('App\Model\Image');

        $manifest->addGroup($group1);
        $manifest->addGroup($group2);

        $groups = $manifest->getGroups();

        $this->assertCount(2, $groups);
        $this->assertArrayHasKey('App\Model\Page', $groups);
        $this->assertArrayHasKey('App\Model\Image', $groups);
    }

    public function testGetGroupsReturnsEmptyByDefault(): void
    {
        $manifest = new FixtureManifest();

        $this->assertSame([], $manifest->getGroups());
    }
}
