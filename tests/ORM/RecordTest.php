<?php

namespace ChrisPenny\DataObjectToFixture\Tests\ORM;

use ChrisPenny\DataObjectToFixture\ORM\Record;
use SilverStripe\Dev\SapphireTest;

/**
 * @phpcs:disable
 */
class RecordTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testGetIdWithInt(): void
    {
        $record = Record::create(42);

        $this->assertSame(42, $record->getId());
    }

    public function testGetIdWithString(): void
    {
        $record = Record::create('abc');

        $this->assertSame('abc', $record->getId());
    }

    public function testIsNewOnFreshRecord(): void
    {
        $record = Record::create(1);

        $this->assertTrue($record->isNew());
    }

    public function testIsNotNewAfterAddingField(): void
    {
        $record = Record::create(1);
        $record->addFieldValue('Title', 'Test');

        $this->assertFalse($record->isNew());
    }

    public function testAddFieldValueAndGetFields(): void
    {
        $record = Record::create(1);
        $record->addFieldValue('Title', 'Test');
        $record->addFieldValue('Sort', 5);

        $this->assertSame(
            ['Title' => 'Test', 'Sort' => 5],
            $record->getFields()
        );
    }

    public function testAddFieldValueOverwrites(): void
    {
        $record = Record::create(1);
        $record->addFieldValue('Title', 'Original');
        $record->addFieldValue('Title', 'Updated');

        $this->assertSame('Updated', $record->getFields()['Title']);
    }

    public function testAddFieldValueReturnsSelf(): void
    {
        $record = Record::create(1);
        $result = $record->addFieldValue('Title', 'Test');

        $this->assertSame($record, $result);
    }

    public function testFluentChaining(): void
    {
        $record = Record::create(1);
        $record
            ->addFieldValue('Title', 'Test')
            ->addFieldValue('Sort', 1)
            ->addFieldValue('Content', 'Hello');

        $this->assertCount(3, $record->getFields());
    }

    public function testRemoveRelationshipValueForClass(): void
    {
        $record = Record::create(1);
        $record->addFieldValue('Title', 'Test');
        $record->addFieldValue('Image', '=>App\Model\Image.123');

        $record->removeRelationshipValueForClass('App\Model\Image');

        $fields = $record->getFields();

        $this->assertArrayHasKey('Title', $fields);
        $this->assertArrayNotHasKey('Image', $fields);
    }

    public function testRemoveRelationshipValueForClassWithNamespacedClass(): void
    {
        $record = Record::create(1);
        $record->addFieldValue('Parent', '=>My\Namespace\ParentClass.456');
        $record->addFieldValue('Other', '=>My\Namespace\OtherClass.789');

        $record->removeRelationshipValueForClass('My\Namespace\ParentClass');

        $fields = $record->getFields();

        $this->assertArrayNotHasKey('Parent', $fields);
        $this->assertArrayHasKey('Other', $fields);
    }

    public function testRemoveRelationshipValueForClassNonMatching(): void
    {
        $record = Record::create(1);
        $record->addFieldValue('Title', 'Test');
        $record->addFieldValue('Image', '=>App\Model\Image.123');

        $record->removeRelationshipValueForClass('App\Model\OtherClass');

        $this->assertCount(2, $record->getFields());
    }

    public function testRemoveRelationshipValueForClassMultipleFields(): void
    {
        $record = Record::create(1);
        $record->addFieldValue('Title', 'Test');
        $record->addFieldValue('Image', '=>App\Model\Image.1');
        $record->addFieldValue('Banner', '=>App\Model\Image.2');

        $record->removeRelationshipValueForClass('App\Model\Image');

        $fields = $record->getFields();

        $this->assertCount(1, $fields);
        $this->assertArrayHasKey('Title', $fields);
    }

    public function testRemoveRelationshipValueForClassReturnsSelf(): void
    {
        $record = Record::create(1);
        $result = $record->removeRelationshipValueForClass('SomeClass');

        $this->assertSame($record, $result);
    }
}
