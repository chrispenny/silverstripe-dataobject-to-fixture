<?php

namespace ChrisPenny\DataObjectToFixture\Service;

use ChrisPenny\DataObjectToFixture\Helper\FluentHelper;
use ChrisPenny\DataObjectToFixture\Manifest\FixtureManifest;
use ChrisPenny\DataObjectToFixture\Manifest\RelationshipManifest;
use ChrisPenny\DataObjectToFixture\ORM\Group;
use ChrisPenny\DataObjectToFixture\ORM\Record;
use Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\RelationList;
use Symfony\Component\Yaml\Yaml;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

class FixtureService
{

    use Injectable;

    private ?FixtureManifest $fixtureManifest;

    private ?RelationshipManifest $relationshipManifest;

    private bool $validated = false;

    private array $warnings = [];

    private ?int $allowedDepth = null; // phpcs:ignore

    public function __construct()
    {
        $this->fixtureManifest = new FixtureManifest();
        // The RelationshipManifest is a simple mapping of Class names and what other Classes that have relationships
        // to. This will allow us to order (as best we can) our fixture output later on
        $this->relationshipManifest = new RelationshipManifest();
    }

    /**
     * @throws Exception
     */
    public function addDataObject(DataObject $dataObject, int $currentDepth = 0): ?Record
    {
        // Check isInDB() rather than exists(), as exists() has additional checks for (eg) Files
        if (!$dataObject->isInDB()) {
            throw new Exception('Your DataObject must be in the DB');
        }

        // If you've requested that we only process to a certain depth, then we'll return early if we've hit that depth
        if ($this->getAllowedDepth() !== null && $currentDepth >= $this->getAllowedDepth()) {
            return null;
        }

        $currentDepth += 1;

        // Any time we add a new DataObject, we need to set validated back to false
        $this->validated = false;

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

        // If the DataObject has Fluent applied, then we also need to add Localised fields
        if ($dataObject->hasExtension(FluentExtension::class)) {
            $this->addDataObjectLocalisedFields($dataObject, $currentDepth);
        }

        // Add direct has_one relationships
        $this->addDataObjectHasOneFields($dataObject, $currentDepth);
        // has_many fields may also include relationships that you've created using many_many "through" (so long as
        // you also defined the has_many)
        $this->addDataObjectHasManyFields($dataObject, $currentDepth);
        // Add many_many fields that do not contain through relationships
        $this->addDataObjectManyManyFields($dataObject, $currentDepth);
        // Add many_many fields that contain through relationships
        $this->addDataObjectManyManyThroughFields($dataObject, $currentDepth);

        return $record;
    }

    /**
     * @throws Exception
     */
    public function outputFixture(): string
    {
        // One last thing we need to do before we output this, is make sure, if we're using Fluent, that we've added
        // each of our Locales to the fixture with the highest priority possible.
        if (!class_exists(Locale::class)) {
            return Yaml::dump($this->toArray(), 3);
        }

        // We don't have any Locales created, so there is nothing for us to add here.
        if (Locale::get()->count() === 0) {
            return Yaml::dump($this->toArray(), 3);
        }

        // Find/create a Group for Locale so that we can set it as our highest priority.
        $group = $this->findOrCreateGroupByClassName(Locale::class);

        // Only add the Locale Records if this Group was/is new.
        if ($group->isNew()) {
            $this->relationshipManifest->addGroup($group);

            /** @var DataList|Locale[] $locales */
            $locales = Locale::get();

            // Add all Locale Records.
            foreach ($locales as $locale) {
                $this->addDataObject($locale);
            }
        }

        return Yaml::dump($this->toArray(), 3);
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getAllowedDepth(): ?int
    {
        return $this->allowedDepth;
    }

    public function setAllowedDepth(?int $allowedDepth = null): FixtureService
    {
        if ($allowedDepth === 0) {
            $this->addWarning('You set an allowed depth of 0. We have assumed that you mean "no limit".');

            return $this;
        }

        $this->allowedDepth = $allowedDepth;

        return $this;
    }

    private function toArray(): array
    {
        $toArrayGroups = [];

        foreach ($this->relationshipManifest->getPrioritisedOrder() as $className) {
            $group = $this->fixtureManifest->getGroupByClassName($className);

            if (!$group) {
                continue;
            }

            $records = $group->toArray();

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

        return $toArrayGroups;
    }

    /**
     * @throws Exception
     */
    private function addDataObjectDbFields(DataObject $dataObject): void
    {
        $record = $this->fixtureManifest->getRecordByClassNameID($dataObject->ClassName, $dataObject->ID);

        if ($record === null) {
            throw new Exception(
                sprintf('Unable to find Record "%s" in Group "%s"', $dataObject->ID, $dataObject->ClassName)
            );
        }

        $dbFields = $dataObject->config()->get('db');

        if (!is_array($dbFields)) {
            return;
        }

        foreach (array_keys($dbFields) as $fieldName) {
            // DB fields are pretty simple key => value.
            $value = $dataObject->relField($fieldName);

            $record->addFieldValue($fieldName, $value);
        }
    }

    /**
     * @throws Exception
     */
    private function addDataObjectHasOneFields(DataObject $dataObject, int $currentDepth = 0): void
    {
        // The Group should already exist at this point
        $group = $this->fixtureManifest->getGroupByClassName($dataObject->ClassName);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($group === null) {
            throw new Exception(sprintf('Unable to find Group "%s"', $dataObject->ClassName));
        }

        // The Record should already exist at this point
        $record = $group->getRecordByID($dataObject->ID);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($record === null) {
            throw new Exception(
                sprintf('Unable to find Record "%s" in Group "%s"', $dataObject->ID, $dataObject->ClassName)
            );
        }

        /** @var array $hasOneRelationships */
        $hasOneRelationships = $dataObject->config()->get('has_one');

        if (!is_array($hasOneRelationships)) {
            return;
        }

        foreach ($hasOneRelationships as $fieldName => $relationClassName) {
            $relationFieldName = sprintf('%sID', $fieldName);
            // field_classname_map provides devs with the opportunity to describe polymorphic relationships
            $fieldClassNameMap = $dataObject->config()->get('field_classname_map');

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
                $fieldName
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

            $relatedObjectID = (int) $dataObject->{$relationFieldName};

            // We cannot query a DataObject. This relationship needs to be described in field_classname_map
            if ($relationClassName === DataObject::class) {
                continue;
            }

            // Fetch the related DataObject
            $relatedObject = DataObject::get($relationClassName)->byID($relatedObjectID);

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

            // Add the related DataObject. Before we add the relationship, let's make sure that the Record was actually
            // added
            $relatedRecord = $this->addDataObject($relatedObject, $currentDepth);

            // It wasn't (most likely because we've hit the "depth" limit), so we shouldn't add the relationship
            if (!$relatedRecord) {
                $this->addWarning(sprintf(
                    'We were unable to add record %s.%s when processing the has_one relationships for %s.%s',
                    $relatedObject->ClassName,
                    $relatedObject->ID,
                    $dataObject->ClassName,
                    $dataObject->ID
                ));

                continue;
            }

            // Add the relationship field to our current Record
            $record->addFieldValue($relationFieldName, $relationshipValue);

            // Find the Group for the DataObject that we should have just added
            $relatedGroup = $this->fixtureManifest->getGroupByClassName($relatedObject->ClassName);

            // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
            if ($relatedGroup === null) {
                throw new Exception(sprintf('Unable to find Group "%s"', $relatedObject->ClassName));
            }

            // Add a relationship map for these Groups. That being, our origin DataObject class relies on the related
            // DataObject class (EG: Page has ElementalArea)
            $this->relationshipManifest->addRelationship($group, $relatedGroup);
        }
    }

    /**
     * @throws Exception
     */
    private function addDataObjectHasManyFields(DataObject $dataObject, int $currentDepth = 0): void
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
                // Add the related DataObject. Recursion starts
                $this->addDataObject($relatedObject, $currentDepth);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function addDataObjectManyManyFields(DataObject $dataObject, int $currentDepth = 0): void
    {
        // The Group should already exist at this point
        $group = $this->fixtureManifest->getGroupByClassName($dataObject->ClassName);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($group === null) {
            throw new Exception(sprintf('Unable to find Group "%s"', $dataObject->ClassName));
        }

        // The Record should already exist at this point
        $record = $group->getRecordByID($dataObject->ID);

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

            // This many_many relationship has already been represented, so we don't want to add it again
            // Note: many_many relationship can be defined on one, or both sides of the relationship, but it can only
            // be represented once in our fixture
            if ($this->relationshipManifest->hasManyManyRelationship($dataObject->ClassName, $relationFieldName, $relationClassName)) {
                continue;
            }

            // Track that this many_many relationship has been represented already, so that when we addDataObject()
            // below we don't cause infinite recursion
            $this->relationshipManifest->addManyManyRelationship($dataObject->ClassName, $relationFieldName, $relationClassName);

            // We're going to add these many_many relationships as an array
            $resolvedRelationships = [];
            /** @var DataList|DataObject[] $relatedObjects */
            $relatedObjects = $dataObject->relField($relationFieldName);

            foreach ($relatedObjects as $relatedObject) {
                // Add the related DataObject. Before we add the relationship, let's make sure that the Record was
                // actually added
                $relatedRecord = $this->addDataObject($relatedObject, $currentDepth);

                // It wasn't (most likely because we've hit the "depth" limit), so we shouldn't add the relationship
                if (!$relatedRecord) {
                    $this->addWarning(sprintf(
                        'We were unable to add record %s.%s when processing the many_many relationships for %s.%s',
                        $relatedObject->ClassName,
                        $relatedObject->ID,
                        $dataObject->ClassName,
                        $dataObject->ID
                    ));

                    continue;
                }

                // Add the related DataObject as one of our resolved relationships
                $resolvedRelationships[] = sprintf('=>%s.%s', $relatedObject->ClassName, $relatedObject->ID);
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
    private function addDataObjectManyManyThroughFields(DataObject $dataObject, int $currentDepth = 0): void
    {
        // The Group should already exist at this point
        $group = $this->fixtureManifest->getGroupByClassName($dataObject->ClassName);

        // We can't easily recover if it doesn't (mostly because it's unclear *why* it wouldn't be available)
        if ($group === null) {
            throw new Exception(sprintf('Unable to find Group "%s"', $dataObject->ClassName));
        }

        // The Record should already exist at this point
        $record = $group->getRecordByID($dataObject->ID);

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
                // Add the related DataObject. Before we add the relationship, let's make sure that the Record was
                // actually added
                $relatedRecord = $this->addDataObject($relatedObject, $currentDepth);

                // It wasn't (most likely because we've hit the "depth" limit), so we shouldn't add the relationship
                if (!$relatedRecord) {
                    $this->addWarning(sprintf(
                        'We were unable to add record %s.%s when processing the many_many relationships for %s.%s',
                        $relatedObject->ClassName,
                        $relatedObject->ID,
                        $dataObject->ClassName,
                        $dataObject->ID
                    ));

                    continue;
                }

                // Add the related DataObject as one of our resolved relationships
                $resolvedRelationships[] = sprintf('=>%s.%s', $relatedObject->ClassName, $relatedObject->ID);
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
     * @param DataObject|FluentExtension $dataObject
     * @throws Exception
     */
    private function addDataObjectLocalisedFields(DataObject $dataObject, int $currentDepth = 0): void
    {
        $localeCodes = FluentHelper::getLocaleCodesByObjectInstance($dataObject);
        $localisedTables = $dataObject->getLocalisedTables();

        // There are no Localisations for us to export for this DataObject.
        if (count($localeCodes) === 0) {
            return;
        }

        // Somehow... there aren't any Localised tables for this DataObject?
        if (count($localisedTables) === 0) {
            return;
        }

        // In order to get the Localised data for this DataObject, we must re-fetch it while we have a FluentState set.
        $className = $dataObject->ClassName;
        $id = $dataObject->ID;

        // We can't add related DataObject from within the FluentState - if we do that, we'll be adding the Localised
        // record as if it was the base record.
        $relatedDataObjects = [];

        foreach ($localeCodes as $locale) {
            FluentState::singleton()->withState(
                function (FluentState $state) use (
                    $localisedTables,
                    $relatedDataObjects,
                    $locale,
                    $className,
                    $id,
                    $currentDepth
                ): void {
                    $state->setLocale($locale);

                    // Re-fetch our DataObject. This time it should be Localised with all of the specific content that
                    // we need to export for this Locale.
                    $localisedDataObject = DataObject::get($className)->byID($id);

                    if ($localisedDataObject === null) {
                        // Let's not break the entire process because of this, but we should flag it up as a warning.
                        $this->addWarning(sprintf(
                            'DataObject Localisation could not be found for Class: %s | ID: %s | Locale %s',
                            $className,
                            $id,
                            $locale
                        ));

                        return;
                    }

                    $localisedID = sprintf('%s%s', $id, $locale);

                    foreach ($localisedTables as $localisedTable => $localisedFields) {
                        $localisedTableName = sprintf('%s_%s', $localisedTable, FluentExtension::SUFFIX);
                        $record = $this->findOrCreateRecordByClassNameId($localisedTableName, $localisedID);

                        $record->addFieldValue('RecordID', sprintf('=>%s.%s', $className, $id));
                        $record->addFieldValue('Locale', $locale);

                        foreach ($localisedFields as $localisedField) {
                            $isIDField = (substr($localisedField, -2) === 'ID');

                            if ($isIDField) {
                                $relationshipName = substr($localisedField, 0, -2);

                                $fieldValue = $localisedDataObject->relField($relationshipName);
                            } else {
                                $fieldValue = $localisedDataObject->relField($localisedField);
                            }

                            // Check if this is a "regular" field value, if it is then add it and continue
                            if (!$fieldValue instanceof DataObject && !$fieldValue instanceof RelationList) {
                                $record->addFieldValue($localisedField, $fieldValue);

                                continue;
                            }

                            // Remaining field values are going to be relational values, so we need to check whether or
                            // not we're already at our max allowed depth before adding those relationships
                            if ($this->getAllowedDepth() !== null && $currentDepth > $this->getAllowedDepth()) {
                                continue;
                            }

                            if ($fieldValue instanceof DataObject) {
                                $relatedDataObjects[] = $fieldValue;

                                $relationshipValue = sprintf('=>%s.%s', $fieldValue->ClassName, $fieldValue->ID);

                                // Add the relationship field to our current Record.
                                $record->addFieldValue($localisedField, $relationshipValue);

                                continue;
                            }

                            if ($fieldValue instanceof HasManyList) {
                                foreach ($fieldValue as $relatedDataObject) {
                                    $relatedDataObjects[] = $relatedDataObject;
                                }
                            }

                            // No other field types are supported (EG: ManyManyList)
                        }
                    }
                }
            );
        }

        foreach ($relatedDataObjects as $relatedDataObject) {
            $this->addDataObject($relatedDataObject, $currentDepth);
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
     * @param string|int $id
     * @throws Exception
     */
    private function findOrCreateRecordByClassNameId(string $className, $id): Record
    {
        $group = $this->findOrCreateGroupByClassName($className);

        // The Group should have been available. If it isn't, that's a paddlin.
        if ($group === null) {
            throw new Exception(sprintf('Group "%s" should have been available', $className));
        }

        $record = $group->getRecordByID($id);

        // If the Record already exists, then we can just return it.
        if ($record !== null) {
            return $record;
        }

        // Create and add the new Record, and then return it.
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
