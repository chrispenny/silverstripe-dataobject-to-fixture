<?php

namespace ChrisPenny\DataObjectToFixture\Tests\ORM;

use ChrisPenny\DataObjectToFixture\ORM\Group;
use ChrisPenny\DataObjectToFixture\ORM\Record;
use SilverStripe\Dev\SapphireTest;

/**
 * @phpcs:disable
 */
class GroupTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testGetClassName(): void
    {
        $group = Group::create('App\Model\Page');

        $this->assertSame('App\Model\Page', $group->getClassName());
    }

    public function testIsNewOnFreshGroup(): void
    {
        $group = Group::create('App\Model\Page');

        $this->assertTrue($group->isNew());
    }

    public function testIsNotNewAfterAddingRecord(): void
    {
        $group = Group::create('App\Model\Page');
        $group->addRecord(Record::create(1));

        $this->assertFalse($group->isNew());
    }

    public function testAddRecordAndGetRecordById(): void
    {
        $group = Group::create('App\Model\Page');
        $record = Record::create(42);
        $group->addRecord($record);

        $this->assertSame($record, $group->getRecordById(42));
    }

    public function testGetRecordByIdReturnsNullWhenNotFound(): void
    {
        $group = Group::create('App\Model\Page');

        $this->assertNull($group->getRecordById(999));
    }

    public function testGetRecords(): void
    {
        $group = Group::create('App\Model\Page');
        $record1 = Record::create(1);
        $record2 = Record::create(2);
        $group->addRecord($record1);
        $group->addRecord($record2);

        $records = $group->getRecords();

        $this->assertCount(2, $records);
        $this->assertSame($record1, $records[1]);
        $this->assertSame($record2, $records[2]);
    }

    public function testAddRecordOverwritesSameId(): void
    {
        $group = Group::create('App\Model\Page');
        $record1 = Record::create(1);
        $record1->addFieldValue('Title', 'Original');
        $record2 = Record::create(1);
        $record2->addFieldValue('Title', 'Replacement');

        $group->addRecord($record1);
        $group->addRecord($record2);

        $this->assertCount(1, $group->getRecords());
        $this->assertSame('Replacement', $group->getRecordById(1)->getFields()['Title']);
    }

    public function testToArray(): void
    {
        $group = Group::create('App\Model\Page');
        $record = Record::create(1);
        $record->addFieldValue('Title', 'Test Page');
        $record->addFieldValue('Sort', 5);
        $group->addRecord($record);

        $result = $group->toArray();

        $this->assertSame(
            [
                1 => [
                    'Title' => 'Test Page',
                    'Sort' => 5,
                ],
            ],
            $result
        );
    }

    public function testToArraySkipsRecordsWithNoFields(): void
    {
        $group = Group::create('App\Model\Page');

        // Record with fields
        $record1 = Record::create(1);
        $record1->addFieldValue('Title', 'Has Fields');
        $group->addRecord($record1);

        // Record with no fields (should be skipped)
        $record2 = Record::create(2);
        $group->addRecord($record2);

        $result = $group->toArray();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }

    public function testToArrayReturnsEmptyForAllEmptyRecords(): void
    {
        $group = Group::create('App\Model\Page');
        $group->addRecord(Record::create(1));
        $group->addRecord(Record::create(2));

        $this->assertSame([], $group->toArray());
    }
}
