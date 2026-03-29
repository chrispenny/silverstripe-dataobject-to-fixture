<?php

namespace ChrisPenny\DataObjectToFixture\Tests\Service;

use ChrisPenny\DataObjectToFixture\Service\FixtureService;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockElement;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockExcludedObject;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockImage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockTag;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Models\MockThroughTarget;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockNativePoly;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPageWithExclusions;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Pages\MockPolymorphicPage;
use ChrisPenny\DataObjectToFixture\Tests\Mocks\Relations\MockThroughObject;
use Exception;
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
        MockNativePoly::class,
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

        $expected = [
            MockPage::class => [
                $page->ID => ['Title' => 'Test Page'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
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

        // Image is a dependency of Page, so it appears first
        $expected = [
            MockImage::class => [
                $image->ID => ['Name' => 'test-image.jpg'],
            ],
            MockPage::class => [
                $page->ID => [
                    'Title' => 'Page With Image',
                    'Image' => sprintf('=>%s.%s', MockImage::class, $image->ID),
                ],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
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

        // Page is a dependency of Element (via has_one), so Page appears first
        $expected = [
            MockPage::class => [
                $page->ID => ['Title' => 'Page With Elements'],
            ],
            MockElement::class => [
                $element->ID => [
                    'Title' => 'Element 1',
                    'Sort' => 1,
                    'Parent' => sprintf('=>%s.%s', MockPage::class, $page->ID),
                ],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
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

        $parsed = Yaml::parse($service->outputFixture());

        // MockPage and MockTag are independent (no has_one edge), so their relative order
        // depends on the topological sort's processing order. Build expected to match.
        $expected = [
            MockTag::class => [
                $tag1->ID => ['Title' => 'Tag A'],
                $tag2->ID => ['Title' => 'Tag B'],
            ],
            MockPage::class => [
                $page->ID => [
                    'Title' => 'Page With Tags',
                    'Tags' => [
                        sprintf('=>%s.%s', MockTag::class, $tag1->ID),
                        sprintf('=>%s.%s', MockTag::class, $tag2->ID),
                    ],
                ],
            ],
        ];

        $this->assertSame($expected, $parsed);
    }

    public function testManyManyTagExcludeFromFixtureRelationships(): void
    {
        MockTag::config()->set('exclude_from_fixture_relationships', true);

        $page = MockPage::create();
        $page->Title = 'Page Excluding Tags';
        $page->write();

        $tag = MockTag::create();
        $tag->Title = 'Excluded Tag';
        $tag->write();

        $page->Tags()->add($tag);

        $service = new FixtureService();
        $service->addDataObject($page);

        $expected = [
            MockPage::class => [
                $page->ID => ['Title' => 'Page Excluding Tags'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
    }

    public function testManyManyPageExcludedFromFixtureRelationships(): void
    {
        MockPage::config()->set('excluded_fixture_relationships', ['Tags']);

        $page = MockPage::create();
        $page->Title = 'Page Excluding Tags Relation';
        $page->write();

        $tag = MockTag::create();
        $tag->Title = 'Excluded Via Relation';
        $tag->write();

        $page->Tags()->add($tag);

        $service = new FixtureService();
        $service->addDataObject($page);

        $expected = [
            MockPage::class => [
                $page->ID => ['Title' => 'Page Excluding Tags Relation'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
    }

    public function testManyManyThroughExcludedFixtureRelationships(): void
    {
        MockPage::config()->set('excluded_fixture_relationships', ['ThroughTargets']);

        $page = MockPage::create();
        $page->Title = 'Page Excluding Through';
        $page->write();

        $target = MockThroughTarget::create();
        $target->Title = 'Excluded Through Target';
        $target->write();

        $page->ThroughTargets()->add($target);

        $service = new FixtureService();
        $service->addDataObject($page);

        $expected = [
            MockPage::class => [
                $page->ID => ['Title' => 'Page Excluding Through'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
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

        $parsed = Yaml::parse($service->outputFixture());

        $expected = [
            MockThroughTarget::class => [
                $target->ID => ['Title' => 'Through Target'],
            ],
            MockPage::class => [
                $page->ID => [
                    'Title' => 'Page With Through',
                    'ThroughTargets' => [
                        sprintf('=>%s.%s', MockThroughTarget::class, $target->ID),
                    ],
                ],
            ],
        ];

        $this->assertSame($expected, $parsed);
    }

    public function testExcludeFromFixtureRelationships(): void
    {
        $excluded = MockExcludedObject::create();
        $excluded->Title = 'Excluded';
        $excluded->write();

        $service = new FixtureService();
        $service->addDataObject($excluded);

        $expected = [
            MockExcludedObject::class => [
                $excluded->ID => ['Title' => 'Excluded'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
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

        // Only the page — image is excluded via excluded_fixture_relationships
        $expected = [
            MockPageWithExclusions::class => [
                $page->ID => ['Title' => 'Page With Excluded Relations'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
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

        $expected = [
            MockImage::class => [
                $image->ID => ['Name' => 'polymorphic-image.jpg'],
            ],
            MockPolymorphicPage::class => [
                $page->ID => [
                    'Title' => 'Polymorphic Page',
                    'PolymorphicHasOne' => sprintf('=>%s.%s', MockImage::class, $image->ID),
                ],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
    }

    public function testPolymorphicHasOneWithoutClassnameWarns(): void
    {
        $image = MockImage::create();
        $image->Name = 'no-map-image.jpg';
        $image->write();

        $page = MockPolymorphicPage::create();
        $page->Title = 'Unmapped Polymorphic';
        $page->PolymorphicHasOneID = $image->ID;
        // Don't set PolymorphicHasOneClass — the map resolves to empty/null
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $this->assertSame(
            [sprintf(
                'field_classname_map for "PolymorphicHasOneID" in "%s" did not resolve to a valid class name',
                MockPolymorphicPage::class
            )],
            $service->getWarnings()
        );
    }

    public function testArrayFormatPolymorphicHasOne(): void
    {
        $image = MockImage::create();
        $image->Name = 'native-poly.jpg';
        $image->write();

        $page = MockNativePoly::create();
        $page->Title = 'Native Polymorphic';
        $page->OwnerID = $image->ID;
        $page->OwnerClass = MockImage::class;
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $parsed = Yaml::parse($service->outputFixture());

        $expected = [
            MockImage::class => [
                $image->ID => ['Name' => 'native-poly.jpg'],
            ],
            MockNativePoly::class => [
                $page->ID => [
                    'Title' => 'Native Polymorphic',
                    'Owner' => sprintf('=>%s.%s', MockImage::class, $image->ID),
                ],
            ],
        ];

        $this->assertSame($expected, $parsed);
    }

    public function testHasOneSkipsWhenNoValue(): void
    {
        $page = MockPage::create();
        $page->Title = 'No Image';
        // ImageID is 0 / not set — hasValue() returns false, so the has_one should be skipped
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $expected = [
            MockPage::class => [
                $page->ID => ['Title' => 'No Image'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
    }

    public function testHasOneWarnsWhenRelatedObjectNotFound(): void
    {
        // Create and delete an image so we have an ID that no longer exists
        $image = MockImage::create();
        $image->Name = 'deleted.jpg';
        $image->write();
        $deletedId = $image->ID;
        $image->delete();

        $page = MockPage::create();
        $page->Title = 'Dangling Reference';
        $page->ImageID = $deletedId;
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);

        $this->assertSame(
            [sprintf('Related Object "ImageID" found on "%s" was not a DataObject', MockPage::class)],
            $service->getWarnings()
        );
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

        $parsed = Yaml::parse($service->outputFixture());

        // Image has no deps, Page depends on Image, Element depends on Page
        $expected = [
            MockImage::class => [
                $image->ID => ['Name' => 'chain-image.jpg'],
            ],
            MockPage::class => [
                $page->ID => [
                    'Title' => 'Chain Page',
                    'Image' => sprintf('=>%s.%s', MockImage::class, $image->ID),
                ],
            ],
            MockElement::class => [
                $element->ID => [
                    'Title' => 'Chain Element',
                    'Sort' => 0,
                    'Parent' => sprintf('=>%s.%s', MockPage::class, $page->ID),
                ],
            ],
        ];

        $this->assertSame($expected, $parsed);
    }

    public function testAddDataObjectDoesNotDuplicateRecords(): void
    {
        $page = MockPage::create();
        $page->Title = 'Unique Page';
        $page->write();

        $service = new FixtureService();
        $service->addDataObject($page);
        $service->addDataObject($page);

        $expected = [
            MockPage::class => [
                $page->ID => ['Title' => 'Unique Page'],
            ],
        ];

        $this->assertSame($expected, Yaml::parse($service->outputFixture()));
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

        $this->assertSame(
            ['Same warning', 'Different warning'],
            $service->getWarnings()
        );
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

        $parsed = Yaml::parse($service->outputFixture());

        // MockPage and MockImage are independent — build expected matching the actual order
        $expected = [
            MockImage::class => [
                $image->ID => ['Name' => 'standalone.jpg'],
            ],
            MockPage::class => [
                $page1->ID => ['Title' => 'Page One'],
                $page2->ID => ['Title' => 'Page Two'],
            ],
        ];

        $this->assertSame($expected, $parsed);
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
