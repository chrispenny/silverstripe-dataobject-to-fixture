<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Service;

use ChrisPenny\DataObjectToFixture\Service\FixtureService;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockElement;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockExcludedObject;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockImage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockTag;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockThroughTarget;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPageWithExclusions;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPolymorphicPage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Relations\MockThroughObject;
use Exception;
use InvalidArgumentException;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpcs:disable
 */
class FixtureServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        MockPage::class,
        MockPageWithExclusions::class,
        MockPolymorphicPage::class,
        MockImage::class,
        MockElement::class,
        MockTag::class,
        MockThroughTarget::class,
        MockThroughObject::class,
        MockExcludedObject::class,
    ];

    public function testAddDataObjectThrowsExceptionWhenNotInDb(): void
    {
        $service = new FixtureService();
        $page = MockPage::create();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Your DataObject must be in the DB');

        $service->addDataObject($page);
    }

    public function testSimpleDbFields(): void
    {
        $page = MockPage::create();
        $page->Title = 'Test Page';
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        $this->assertArrayHasKey(MockPage::class, $parsed);

        $pageData = $parsed[MockPage::class][$page->ID];
        $this->assertSame('Test Page', $pageData['Title']);
    }

    public function testHasOneRelationship(): void
    {
        $image = MockImage::create();
        $image->Name = 'test-image.jpg';
        $image->write();

        $page = MockPage::create();
        $page->Title = 'Page With Image';
        $page->ImageID = $image->ID;
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        // Both classes should be present
        $this->assertArrayHasKey(MockPage::class, $parsed);
        $this->assertArrayHasKey(MockImage::class, $parsed);

        // The page should reference the image using fixture syntax
        $pageData = $parsed[MockPage::class][$page->ID];
        $expectedRef = sprintf('=>%s.%s', MockImage::class, $image->ID);
        $this->assertSame($expectedRef, $pageData['Image']);
    }

    public function testHasManyRelationship(): void
    {
        $page = MockPage::create();
        $page->Title = 'Page With Elements';
        $page->write();

        $element = MockElement::create();
        $element->Title = 'Element 1';
        $element->Sort = 1;
        $element->ParentID = $page->ID;
        $element->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        // Both classes should appear
        $this->assertArrayHasKey(MockPage::class, $parsed);
        $this->assertArrayHasKey(MockElement::class, $parsed);

        // The element should have a has_one reference back to the page
        $elementData = $parsed[MockElement::class][$element->ID];
        $expectedRef = sprintf('=>%s.%s', MockPage::class, $page->ID);
        $this->assertSame($expectedRef, $elementData['Parent']);
        $this->assertSame('Element 1', $elementData['Title']);
    }

    public function testManyManyRelationship(): void
    {
        $page = MockPage::create();
        $page->Title = 'Page With Tags';
        $page->write();

        $tag1 = MockTag::create();
        $tag1->Title = 'Tag A';
        $tag1->write();

        $tag2 = MockTag::create();
        $tag2->Title = 'Tag B';
        $tag2->write();

        $page->Tags()->add($tag1);
        $page->Tags()->add($tag2);

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        $this->assertArrayHasKey(MockPage::class, $parsed);
        $this->assertArrayHasKey(MockTag::class, $parsed);

        // The page record should have Tags as an array of references
        $pageData = $parsed[MockPage::class][$page->ID];
        $this->assertIsArray($pageData['Tags']);
        $this->assertContains(sprintf('=>%s.%s', MockTag::class, $tag1->ID), $pageData['Tags']);
        $this->assertContains(sprintf('=>%s.%s', MockTag::class, $tag2->ID), $pageData['Tags']);
    }

    public function testManyManyThroughRelationship(): void
    {
        $page = MockPage::create();
        $page->Title = 'Page With Through';
        $page->write();

        $target = MockThroughTarget::create();
        $target->Title = 'Through Target';
        $target->write();

        $page->ThroughTargets()->add($target);

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        $this->assertArrayHasKey(MockPage::class, $parsed);
        $this->assertArrayHasKey(MockThroughTarget::class, $parsed);
    }

    public function testExcludeFromFixtureRelationships(): void
    {
        $excluded = MockExcludedObject::create();
        $excluded->Title = 'Excluded';
        $excluded->write();

        $service = new FixtureService();
        $service->addDataObject($excluded);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        // The excluded object itself should still appear in the fixture when directly added
        $this->assertArrayHasKey(MockExcludedObject::class, $parsed);

        $recordData = $parsed[MockExcludedObject::class][$excluded->ID];
        $this->assertSame('Excluded', $recordData['Title']);
    }

    public function testExcludedFixtureRelationships(): void
    {
        $image = MockImage::create();
        $image->Name = 'should-be-excluded.jpg';
        $image->write();

        $page = MockPageWithExclusions::create();
        $page->Title = 'Page With Excluded Relations';
        $page->ImageID = $image->ID;
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        // The page should be in the fixture
        $this->assertArrayHasKey(MockPageWithExclusions::class, $parsed);
        // But the image should NOT be traversed (it's excluded via excluded_fixture_relationships)
        $this->assertArrayNotHasKey(MockImage::class, $parsed);
    }

    public function testPolymorphicHasOneWithFieldClassnameMap(): void
    {
        $image = MockImage::create();
        $image->Name = 'polymorphic-image.jpg';
        $image->write();

        $page = MockPolymorphicPage::create();
        $page->Title = 'Polymorphic Page';
        $page->PolymorphicHasOneID = $image->ID;
        $page->PolymorphicHasOneClass = MockImage::class;
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        // Both classes should appear
        $this->assertArrayHasKey(MockPolymorphicPage::class, $parsed);
        $this->assertArrayHasKey(MockImage::class, $parsed);

        $imageData = $parsed[MockImage::class][$image->ID];
        $this->assertSame('polymorphic-image.jpg', $imageData['Name']);
    }

    public function testPolymorphicHasOneWithoutClassnameThrowsException(): void
    {
        $image = MockImage::create();
        $image->Name = 'no-map-image.jpg';
        $image->write();

        $page = MockPolymorphicPage::create();
        $page->Title = 'Unmapped Polymorphic';
        $page->PolymorphicHasOneID = $image->ID;
        // Don't set PolymorphicHasOneClass — the map resolves to empty string
        $page->write();

        $service = new FixtureService();

        // When the field_classname_map resolves to an empty/invalid class name, Silverstripe throws
        // an InvalidArgumentException from DataObject::get()
        $this->expectException(InvalidArgumentException::class);

        $service->addDataObject($page);
    }

    public function testAddDataObjectProcessesRelatedObjectsViaStack(): void
    {
        // Create a chain: Page -> Image (via has_one) and Page -> Element (via has_many)
        $image = MockImage::create();
        $image->Name = 'chain-image.jpg';
        $image->write();

        $page = MockPage::create();
        $page->Title = 'Chain Page';
        $page->ImageID = $image->ID;
        $page->write();

        $element = MockElement::create();
        $element->Title = 'Chain Element';
        $element->ParentID = $page->ID;
        $element->write();

        $service = new FixtureService();
        // Only add the page — related objects should be pulled in automatically
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        $this->assertArrayHasKey(MockPage::class, $parsed);
        $this->assertArrayHasKey(MockImage::class, $parsed);
        $this->assertArrayHasKey(MockElement::class, $parsed);

        $imageData = $parsed[MockImage::class][$image->ID];
        $this->assertSame('chain-image.jpg', $imageData['Name']);

        $elementData = $parsed[MockElement::class][$element->ID];
        $this->assertSame('Chain Element', $elementData['Title']);
    }

    public function testAddDataObjectDoesNotDuplicateRecords(): void
    {
        $page = MockPage::create();
        $page->Title = 'Unique Page';
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        // Only one record for this page
        $this->assertCount(1, $parsed[MockPage::class]);
    }

    public function testOutputFixtureProducesValidYaml(): void
    {
        $page = MockPage::create();
        $page->Title = 'YAML Test';
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey(MockPage::class, $parsed);
    }

    public function testGetWarningsReturnsEmptyByDefault(): void
    {
        $service = new FixtureService();

        $this->assertSame([], $service->getWarnings());
    }

    public function testWarningsAreNotDuplicated(): void
    {
        $service = new FixtureService();

        // Use reflection to call addWarning directly to test deduplication
        $reflection = new \ReflectionClass(FixtureService::class);
        $method = $reflection->getMethod('addWarning');
        $method->setAccessible(true);

        $method->invoke($service, 'Same warning');
        $method->invoke($service, 'Same warning');
        $method->invoke($service, 'Different warning');

        $warnings = $service->getWarnings();

        $this->assertCount(2, $warnings);
        $this->assertSame('Same warning', $warnings[0]);
        $this->assertSame('Different warning', $warnings[1]);
    }

    public function testMultipleDataObjectsAcrossClasses(): void
    {
        $page1 = MockPage::create();
        $page1->Title = 'Page One';
        $page1->write();

        $page2 = MockPage::create();
        $page2->Title = 'Page Two';
        $page2->write();

        $image = MockImage::create();
        $image->Name = 'standalone.jpg';
        $image->write();

        $service = new FixtureService();
        $service->addDataObject($page1);
        $service->addDataObject($page2);
        $service->addDataObject($image);

        $output = $service->outputFixture();
        $parsed = Yaml::parse($output);

        // Both classes should be present
        $this->assertArrayHasKey(MockPage::class, $parsed);
        $this->assertArrayHasKey(MockImage::class, $parsed);

        // Both pages should be represented
        $pageRecords = $parsed[MockPage::class];
        $this->assertCount(2, $pageRecords);
    }

    public function testFixtureOutputOrderReflectsDependencies(): void
    {
        // Image has no dependencies, Page depends on Image via has_one
        $image = MockImage::create();
        $image->Name = 'dependency-test.jpg';
        $image->write();

        $page = MockPage::create();
        $page->Title = 'Dependency Page';
        $page->ImageID = $image->ID;
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $output = $service->outputFixture();

        // In the YAML output, MockImage should appear before MockPage
        $imagePos = strpos($output, MockImage::class);
        $pagePos = strpos($output, MockPage::class);

        $this->assertNotFalse($imagePos);
        $this->assertNotFalse($pagePos);
        $this->assertLessThan($pagePos, $imagePos);
    }
}
