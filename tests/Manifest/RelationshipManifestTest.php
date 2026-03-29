<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Manifest;

use ChrisPenny\DataObjectToFixture\Manifest\RelationshipManifest;
use ChrisPenny\DataObjectToFixture\ORM\Group;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPageWithExclusions;
use SilverStripe\Dev\SapphireTest;

/**
 * @phpcs:disable
 */
class RelationshipManifestTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected static $extra_dataobjects = [
        MockPage::class,
        MockPageWithExclusions::class,
    ];

    public function testAddGroupCreatesRelationshipEntry(): void
    {
        $manifest = new RelationshipManifest();
        $group = Group::create(MockPage::class);

        $manifest->addGroup($group);

        $relationships = $manifest->getRelationships();

        $this->assertArrayHasKey(MockPage::class, $relationships);
        $this->assertSame([], $relationships[MockPage::class]);
    }

    public function testAddGroupSkipsDuplicate(): void
    {
        $manifest = new RelationshipManifest();
        $group = Group::create(MockPage::class);

        $manifest->addGroup($group);
        // Add relationship to verify it's not reset on second addGroup
        $manifest->addRelationship(MockPage::class, 'SomeOtherClass');
        $manifest->addGroup($group);

        $this->assertSame(['SomeOtherClass'], $manifest->getRelationships()[MockPage::class]);
    }

    public function testAddGroupWithExcludedRelationships(): void
    {
        $manifest = new RelationshipManifest();
        $group = Group::create(MockPageWithExclusions::class);

        $manifest->addGroup($group);

        $excluded = $manifest->getExcludedRelationships();

        $this->assertSame(
            [MockPageWithExclusions::class => ['Image']],
            $excluded
        );
    }

    public function testAddRelationship(): void
    {
        $manifest = new RelationshipManifest();

        $manifest->addRelationship('ClassA', 'ClassB');

        $this->assertSame(['ClassA' => ['ClassB']], $manifest->getRelationships());
    }

    public function testAddRelationshipSkipsDuplicate(): void
    {
        $manifest = new RelationshipManifest();

        $manifest->addRelationship('ClassA', 'ClassB');
        $manifest->addRelationship('ClassA', 'ClassB');

        $this->assertSame(['ClassB'], $manifest->getRelationships()['ClassA']);
    }

    public function testAddRelationshipCreatesFromKeyIfMissing(): void
    {
        $manifest = new RelationshipManifest();

        // Don't call addGroup first — addRelationship should create the key
        $manifest->addRelationship('NewClass', 'ClassB');

        $this->assertSame(['NewClass' => ['ClassB']], $manifest->getRelationships());
    }

    public function testShouldExcludeRelationshipReturnsTrue(): void
    {
        $manifest = new RelationshipManifest();
        $group = Group::create(MockPageWithExclusions::class);
        $manifest->addGroup($group);

        $this->assertTrue($manifest->shouldExcludeRelationship(MockPageWithExclusions::class, 'Image'));
    }

    public function testShouldExcludeRelationshipReturnsFalseForUnknownClass(): void
    {
        $manifest = new RelationshipManifest();

        $this->assertFalse($manifest->shouldExcludeRelationship('UnknownClass', 'Image'));
    }

    public function testShouldExcludeRelationshipReturnsFalseForNonExcludedRelation(): void
    {
        $manifest = new RelationshipManifest();
        $group = Group::create(MockPageWithExclusions::class);
        $manifest->addGroup($group);

        $this->assertFalse($manifest->shouldExcludeRelationship(MockPageWithExclusions::class, 'Title'));
    }

    public function testRemoveRelationship(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addRelationship('ClassB', 'ClassA');

        $manifest->removeRelationship('ClassA', 'ClassB');

        $this->assertSame([], $manifest->getRelationships()['ClassB']);
    }

    public function testRemoveRelationshipNonExistent(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addRelationship('ClassA', 'ClassB');

        // Should not throw
        $manifest->removeRelationship('NonExistent', 'ClassA');

        $this->assertSame(['ClassB'], $manifest->getRelationships()['ClassA']);
    }

    public function testResetRelationships(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addRelationship('ClassA', 'ClassB');

        $manifest->resetRelationships();

        $this->assertSame([], $manifest->getRelationships());
    }

    public function testHasManyManyThroughRelationship(): void
    {
        $manifest = new RelationshipManifest();

        $this->assertFalse($manifest->hasManyManyThroughRelationship('ThroughClass'));

        $manifest->addManyManyThroughRelationship('ThroughClass');

        $this->assertTrue($manifest->hasManyManyThroughRelationship('ThroughClass'));
    }

    public function testHasManyManyRelationshipDirectMatch(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addManyManyRelationship('ClassA', 'Tags', 'ClassB');

        // ClassA.Tags was stored as a key — should match
        $this->assertTrue($manifest->hasManyManyRelationship('ClassA', 'Tags', 'ClassB'));
    }

    public function testHasManyManyRelationshipReverseMatchViaThroughKey(): void
    {
        $manifest = new RelationshipManifest();
        // Through relationships are stored with the through class as key and `true` as value
        // This makes them findable via array_search when checking in the reverse direction
        $manifest->addManyManyThroughRelationship('ThroughClass');

        $this->assertTrue($manifest->hasManyManyThroughRelationship('ThroughClass'));
        $this->assertFalse($manifest->hasManyManyThroughRelationship('OtherClass'));
    }

    public function testHasManyManyRelationshipToAsDotNotationKey(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addManyManyRelationship('ClassB', 'Items', 'ClassA');

        // $to is dot notation and matches a stored key
        $this->assertTrue($manifest->hasManyManyRelationship('ClassA', 'Tags', 'ClassB.Items'));
    }

    public function testHasManyManyRelationshipNoMatch(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addManyManyRelationship('ClassX', 'Relation', 'ClassY');

        $this->assertFalse($manifest->hasManyManyRelationship('ClassA', 'Tags', 'ClassB'));
    }

    public function testGetPrioritisedOrder(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addRelationship('Page', 'Image');
        $manifest->addRelationship('Image', '');

        // Image should come before Page
        $order = $manifest->getPrioritisedOrder();

        $pageIndex = array_search('Page', $order, true);
        $imageIndex = array_search('Image', $order, true);

        $this->assertNotFalse($pageIndex);
        $this->assertNotFalse($imageIndex);
        $this->assertLessThan($pageIndex, $imageIndex);
    }

    public function testGetPrioritisedOrderLazyLoads(): void
    {
        $manifest = new RelationshipManifest();
        $manifest->addRelationship('A', 'B');

        // First call triggers process()
        $order1 = $manifest->getPrioritisedOrder();
        // Second call should return cached result
        $order2 = $manifest->getPrioritisedOrder();

        $this->assertSame($order1, $order2);
    }

    public function testGetPrioritisedOrderErrors(): void
    {
        $manifest = new RelationshipManifest();
        // Create a circular dependency
        $manifest->addRelationship('ClassA', 'ClassB');
        $manifest->addRelationship('ClassB', 'ClassA');

        $this->assertEqualsCanonicalizing(
            [
                'Node `ClassA` has `1` left over dependencies, and so could not be sorted',
                'Node `ClassB` has `1` left over dependencies, and so could not be sorted',
            ],
            $manifest->getPrioritisedOrderErrors()
        );
    }
}
