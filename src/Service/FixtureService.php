<?php

namespace ChrisPenny\DataObjectToFixture\Service;

use ChrisPenny\DataObjectToFixture\Manifest\FixtureManifest;
use ChrisPenny\DataObjectToFixture\Manifest\RelationshipManifest;
use ChrisPenny\DataObjectToFixture\ORM\Group;
use ChrisPenny\DataObjectToFixture\ORM\Record;
use Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use Symfony\Component\Yaml\Yaml;

class FixtureService
{

    use Injectable;

    private ?FixtureManifest $fixtureManifest;

    private ?RelationshipManifest $relationshipManifest;

    private array $warnings = [];

    private ?int $allowedDepth = null;

    private ?array $toArrayCached = null;

    /**
     * @var DataObject[]
     */
    private array $dataObjectStack = [];

    public function __construct()
    {
        // The Fixture manifest is a simple (ish) mapping of Groups (classes) and Records (DataObjects). For example,
        // the Group under the key `Page` will contain any/all Page DataObjects that were added to the fixture
        $this->fixtureManifest = new FixtureManifest();
        // The RelationshipManifest is a simple mapping of Class names and what other Classes they have relationships
        // to. This will allow us to order (as best we can) our FixtureManifest later on
        $this->relationshipManifest = new RelationshipManifest();
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @throws Exception
     */
    public function addDataObject(DataObject $dataObject): void
    {
        // Remove our cached array fixture, as you've just added another DataObject
        $this->toArrayCached = null;

        // Add this initial DataObject to the stack that we'll process
        $this->dataObjectStack[] = $dataObject;

        // Keep running until the stack is empty
        while ($this->dataObjectStack) {
            // Drop out the next item in the stack
            $dataObject = array_shift($this->dataObjectStack);

            // processDataObject() can itself add more DataObjects to the stack to be processed
            $this->processDataObject($dataObject);
        }
    }

    /**
     * @throws Exception
     */
    public function outputFixture(): string
    {
        return Yaml::dump($this->toArray(), 4, 2);
    }

    private function toArray(): array
    {
        // If we have a cache of this array already, then return it now
        if ($this->toArrayCached !== null) {
            return $this->toArrayCached;
        }

        $toArrayGroups = [];

        // getPrioritisedOrder() uses our KahnSorter to arrange our fixture (as best we can) with dependencies at the
        // top of the fixture
        // Note: This isn't actually as important now that Populate supports "retries"
        $prioritisedOrder = $this->relationshipManifest->getPrioritisedOrder();
        $prioritisedOrderErrors = $this->relationshipManifest->getPrioritisedOrderErrors();

        // There would have been some dependencies that we could not resolve (most likely they had a direct looping
        // relationship)
        foreach ($prioritisedOrderErrors as $prioritisedOrderError) {
            $this->addWarning($prioritisedOrderError);
        }

        foreach ($prioritisedOrder as $className) {
            $group = $this->fixtureManifest->getGroupByClassName($className);

            // Sanity check, but this should always be present if it was represented in our PrioritisedOrder
            if (!$group) {
                continue;
            }

            // Grab all the records for this Group
            $records = $group->toArray();

            // Rather than break here, we'll add a warning, as there really should have been records
            if (count($records) === 0) {
                $this->addWarning(sprintf(
                    'Fixture output: No records were found for Group/ClassName "%s". You might need to check that you'
                    . ' do not have any relationships pointing to this Group/ClassName.',
                    $group->getClassName(),
                ));

                continue;
            }

            $toArrayGroups[$group->getClassName()] = $records;
        }

        // Update our cache
        $this->toArrayCached = $toArrayGroups;

        return $this->toArrayCached;
    }

    /**
     * @throws Exception
     */
    private function processDataObject(DataObject $dataObject): ?Record
    {
        // Check isInDB() rather than exists(), as exists() has additional checks for (eg) Files
        if (!$dataObject->isInDB()) {
            throw new Exception('Your DataObject must be in the DB');
        }

        // Find or create a record based on the DataObject you want to add
        $record = $this->findOrCreateRecordByClassNameId($dataObject->ClassName, $dataObject->ID);

        // This addDataObject() method gets called many times as we try to build out the structure of related
        // DataObjects. It's quite likely that we will come across the same record multiple times. We only need
        // to add it once
        if (!$record->isNew()) {
            return $record;
        }

        // The Group should have been created when we found or created the Record
        $group = $this->fixtureManifest->getGroupByClassName($dataObject->ClassName);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($group === null) {
            throw new Exception(sprintf('Group for class should have been available: %s', $dataObject->ClassName));
        }

        // Add this record to our relationship manifest
        $this->relationshipManifest->addGroup($group);

        // Add the standard DB fields for this record
        $this->addDataObjectDbFields($dataObject);

        // Add direct has_one relationships
        $this->addDataObjectHasOneFields($dataObject);
        // has_many fields may also include relationships that you've created using many_many "through" (so long as
        // you also defined the has_many)
        $this->addDataObjectHasManyFields($dataObject);
        // Add many_many fields that do not contain through relationships
        $this->addDataObjectManyManyFields($dataObject);
        // Add many_many fields that contain through relationships
        $this->addDataObjectManyManyThroughFields($dataObject);

        return $record;
    }

    /**
     * @throws Exception
     */
    private function addDataObjectDbFields(DataObject $dataObject): void
    {
        $record = $this->fixtureManifest->getRecordByClassNameId($dataObject->ClassName, $dataObject->ID);

        // The Record should already exist at this point (as we only call this method processDataObject()
        if ($record === null) {
            // We should just bail out
            throw new Exception(
                sprintf('Unable to find Record "%s" in Group "%s"', $dataObject->ID, $dataObject->ClassName)
            );
        }

        // Find all the DB fields that have been configured for this DataObject
        $dbFields = $dataObject->config()->get('db');

        if (!is_array($dbFields)) {
            return;
        }

        foreach (array_keys($dbFields) as $fieldName) {
            // DB fields are pretty simple key => value. Using relField means that we follow Silverstripe convention
            // for if a developer has created any override methods/etc
            $value = $dataObject->relField($fieldName);

            $record->addFieldValue($fieldName, $value);
        }
    }

    /**
     * @throws Exception
     */
    private function addDataObjectHasOneFields(DataObject $dataObject): void
    {
        // The Record should already exist at this point
        $record = $this->fixtureManifest->getRecordByClassNameId($dataObject->ClassName, $dataObject->ID);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($record === null) {
            throw new Exception(
                sprintf('Unable to find Record "%s" in Group "%s"', $dataObject->ID, $dataObject->ClassName)
            );
        }

        /** @var array $hasOneRelationships */
        $hasOneRelationships = $dataObject->config()->get('has_one');

        // Nothing for us to do here if there are no defined has_one
        if (!is_array($hasOneRelationships)) {
            return;
        }

        foreach ($hasOneRelationships as $relationName => $relationClassName) {
            // Relationship field names (as represented in the Database) are always appended with `ID`
            $relationFieldName = sprintf('%sID', $relationName);
            // field_classname_map provides devs with the opportunity to describe polymorphic relationships (see the
            // README for details)
            $fieldClassNameMap = $dataObject->config()->get('field_classname_map');

            // Apply the map that has been specified
            if ($fieldClassNameMap !== null && array_key_exists($relationFieldName, $fieldClassNameMap)) {
                $relationClassName = $dataObject->relField($fieldClassNameMap[$relationFieldName]);
            }

            // Check to see if class has requested that it not be included in relationship maps
            $excludeClass = Config::inst()->get($relationClassName, 'exclude_from_fixture_relationships');

            // Yup, exclude this class
            if ($excludeClass) {
                continue;
            }

            // Check to see if this particular relationship wants to be excluded
            $excludeRelationship = $this->relationshipManifest->shouldExcludeRelationship(
                $dataObject->ClassName,
                $relationName
            );

            // Yup, exclude this relationship
            if ($excludeRelationship) {
                continue;
            }

            // If there is no value, then, we have a relationship field, but no relationship active
            if (!$dataObject->hasValue($relationFieldName)) {
                continue;
            }

            // We expect this value to be an ID for a related object, if it's not an INT, then that's invalid
            if (!is_numeric($dataObject->{$relationFieldName})) {
                continue;
            }

            $relatedObjectId = (int) $dataObject->{$relationFieldName};

            // We cannot query a DataObject. This relationship needs to be described in field_classname_map (see the
            // README for details)
            if ($relationClassName === DataObject::class) {
                $this->addWarning(sprintf(
                    'Relationship "%s" found in "%s" has only been defined as DataObject. Polymorphic relationships'
                        . ' need to be described in field_classname_map (see the README for details)',
                    $relationFieldName,
                    $dataObject->ClassName
                ));

                continue;
            }

            // Fetch the related DataObject
            $relatedObject = DataObject::get($relationClassName)->byID($relatedObjectId);

            // We expect the relationship to be a DataObject
            if (!$relatedObject instanceof DataObject) {
                $this->addWarning(sprintf(
                    'Related Object "%s" found on "%s" was not a DataObject',
                    $relationFieldName,
                    $dataObject->ClassName
                ));

                continue;
            }

            // Build out the value that we want to use in our Fixture. This should be a "relationship" value
            $relationshipValue = sprintf('=>%s.%s', $relatedObject->ClassName, $relatedObject->ID);

            // Add the relationship field to our current Record
            $record->addFieldValue($relationName, $relationshipValue);

            // Add a relationship map for these Groups. That being, our origin DataObject class relies on the related
            // DataObject class (EG: Page has ElementalArea)
            $this->relationshipManifest->addRelationship($dataObject->ClassName, $relatedObject->ClassName);

            // Add the related DataObject to the stack to be processed
            $this->dataObjectStack[] = $relatedObject;
        }
    }

    /**
     * @throws Exception
     */
    private function addDataObjectHasManyFields(DataObject $dataObject): void
    {
        /** @var array $hasManyRelationships */
        $hasManyRelationships = $dataObject->config()->get('has_many');

        if (!is_array($hasManyRelationships)) {
            return;
        }

        $schema = $dataObject->getSchema();

        foreach ($hasManyRelationships as $relationFieldName => $relationClassName) {
            // Relationships are sometimes defined as ClassName.FieldName. Drop the .FieldName
            $cleanRelationshipClassName = strtok($relationClassName, '.');
            // Use Schema to make sure that this relationship has a reverse has_one created. This will throw an
            // Exception if there isn't (SilverStripe always expects you to have it)
            $schema->getRemoteJoinField($dataObject->ClassName, $relationFieldName, 'has_many');

            // Check to see if this class has requested that it not be included in relationship maps
            $excludeClass = Config::inst()->get($cleanRelationshipClassName, 'exclude_from_fixture_relationships');

            // Yup, exclude this class
            if ($excludeClass) {
                continue;
            }

            // Check to see if this particular relationship wants to be excluded
            $excludeRelationship = $this->relationshipManifest->shouldExcludeRelationship(
                $dataObject->ClassName,
                $relationFieldName
            );

            // Yup, exclude this relationship
            if ($excludeRelationship) {
                continue;
            }

            // If we have the correct relationship mapping (a "has_one" relationship on the object in the "has_many"),
            // then we can simply add each of these records and let the "has_one" be added by addRecordHasOneFields()
            foreach ($dataObject->relField($relationFieldName) as $relatedObject) {
                // Add the related DataObject to the stack to be processed
                $this->dataObjectStack[] = $relatedObject;
            }
        }
    }

    /**
     * @throws Exception
     */
    private function addDataObjectManyManyFields(DataObject $dataObject): void
    {
        // The Record should already exist at this point
        $record = $this->fixtureManifest->getRecordByClassNameId($dataObject->ClassName, $dataObject->ID);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($record === null) {
            throw new Exception(
                sprintf('Unable to find Record "%s" in Group "%s"', $dataObject->ID, $dataObject->ClassName)
            );
        }

        $manyManyRelationships = $dataObject->config()->get('many_many');

        if (!is_array($manyManyRelationships)) {
            return;
        }

        foreach ($manyManyRelationships as $relationFieldName => $relationClassName) {
            // many_many relationships can also be defined with a through object. These are handled by the
            // addDataObjectManyManyThroughFields() method
            if (is_array($relationClassName)) {
                continue;
            }

            // TL;DR: many_many is really tough. Developers could choose to define it only in one direction, or in
            // both directions, and they could choose to define it either with, or without dot notation in either
            // direction

            $hasManyManyRelationship = $this->relationshipManifest->hasManyManyRelationship(
                $dataObject->ClassName,
                $relationFieldName,
                $relationClassName
            );

            // This many_many relationship has already been represented, so we don't want to add it again
            // Note: many_many relationship can be defined on one, or both sides of the relationship, but it can only
            // be represented once in our fixture
            if ($hasManyManyRelationship) {
                continue;
            }

            // Track that this many_many relationship has been represented already, so that when we addDataObject()
            // below we don't cause infinite recursion
            $this->relationshipManifest->addManyManyRelationship(
                $dataObject->ClassName,
                $relationFieldName,
                $relationClassName
            );

            // We're going to add these many_many relationships as an array
            $resolvedRelationships = [];
            /** @var DataList|DataObject[] $relatedObjects */
            $relatedObjects = $dataObject->relField($relationFieldName);

            foreach ($relatedObjects as $relatedObject) {
                // Add the related DataObject as one of our resolved relationships
                $resolvedRelationships[] = sprintf('=>%s.%s', $relatedObject->ClassName, $relatedObject->ID);

                // Add the related DataObject to our stack to be processed
                $this->dataObjectStack[] = $relatedObject;
            }

            // There are no relationships for us to track
            if (!$resolvedRelationships) {
                continue;
            }

            // Add all of these relationships to our Record
            $record->addFieldValue($relationFieldName, $resolvedRelationships);
        }
    }

    /**
     * @throws Exception
     */
    private function addDataObjectManyManyThroughFields(DataObject $dataObject): void
    {
        // The Record should already exist at this point
        $record = $this->fixtureManifest->getRecordByClassNameId($dataObject->ClassName, $dataObject->ID);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($record === null) {
            throw new Exception(
                sprintf('Unable to find Record "%s" in Group "%s"', $dataObject->ID, $dataObject->ClassName)
            );
        }

        $manyManyRelationships = $dataObject->config()->get('many_many');
        // many_many with through information can also be tracked in has_many, so we won't want to duplicate our
        // efforts
        $hasManyRelationships = $dataObject->config()->get('has_many');

        if (!is_array($manyManyRelationships)) {
            return;
        }

        foreach ($manyManyRelationships as $relationFieldName => $relationshipValue) {
            // This many_many relationship does not contain "through" information, so we don't want to process this here
            if (!is_array($relationshipValue) || !array_key_exists('through', $relationshipValue)) {
                continue;
            }

            // This should always simply be defined as the class name (no dot notation)
            $throughClass = $relationshipValue['through'];
            $represented = false;

            // First we'll check if we've already represented this relationship
            if ($this->relationshipManifest->hasManyManyThroughRelationship($throughClass)) {
                continue;
            }

            // Checking to see if this through relationship was also represented in a has_many is a bit of a mission,
            // as we can't know what the relationship name is, and the class definition could be provided with (or
            // without) dot notation
            foreach ($hasManyRelationships as $hasManyRelationship) {
                // Grab the first array item after the explode(). Doesn't matter if this is dot notation or not, it
                // should always equal the class name portion of that string
                [$hasManyClass] = explode('.', $hasManyRelationship);

                // Yup! There is a has_many that already explains this relationship
                if ($hasManyClass === $throughClass) {
                    $represented = true;

                    break;
                }
            }

            // Skip this relationship, as it's already described by a has_many
            if ($represented) {
                continue;
            }

            // Track that this many_many relationship has been represented already, so that when we addDataObject()
            // below we don't cause infinite recursion
            $this->relationshipManifest->addManyManyThroughRelationship($throughClass);

            // We're going to add these many_many relationships as an array
            $resolvedRelationships = [];
            /** @var DataList|DataObject[] $relatedObjects */
            $relatedObjects = $dataObject->relField($relationFieldName);

            foreach ($relatedObjects as $relatedObject) {
                // Add the related DataObject as one of our resolved relationships
                $resolvedRelationships[] = sprintf('=>%s.%s', $relatedObject->ClassName, $relatedObject->ID);

                // Add the related DataObject to the stack to be processed
                $this->dataObjectStack[] = $relatedObject;
            }

            // There are no relationships for us to track
            if (!$resolvedRelationships) {
                continue;
            }

            // Add all of these relationships to our Record
            $record->addFieldValue($relationFieldName, $resolvedRelationships);
        }
    }

    /**
     * @throws Exception
     */
    private function findOrCreateGroupByClassName(string $className): Group
    {
        $group = $this->fixtureManifest->getGroupByClassName($className);

        if ($group !== null) {
            return $group;
        }

        $group = Group::create($className);
        $this->fixtureManifest->addGroup($group);

        return $group;
    }

    /**
     * @throws Exception
     */
    private function findOrCreateRecordByClassNameId(string $className, string|int $id): Record
    {
        $group = $this->findOrCreateGroupByClassName($className);

        // The Group should have been available. If it isn't, that's a paddlin
        if ($group === null) {
            throw new Exception(sprintf('Group "%s" should have been available', $className));
        }

        $record = $group->getRecordById($id);

        // If the Record already exists, then we can just return it
        if ($record !== null) {
            return $record;
        }

        // Create and add the new Record, and then return it
        $record = Record::create($id);
        $group->addRecord($record);

        return $record;
    }

    private function addWarning(string $message): void
    {
        if (in_array($message, $this->warnings, true)) {
            return;
        }

        $this->warnings[] = $message;
    }

}
