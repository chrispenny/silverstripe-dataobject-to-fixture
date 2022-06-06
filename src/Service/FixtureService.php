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
        $this->relationshipManifest = new RelationshipManifest();
    }

    /**
     * @throws Exception
     */
    public function addDataObject(DataObject $dataObject, int $currentDepth = 0): FixtureService
    {
        // Check isInDB() rather than exists(), as exists() has additional checks for (eg) Files
        if (!$dataObject->isInDB()) {
            throw new Exception('Your DataObject must be in the DB');
        }

        $currentDepth += 1;

        // Any time we add a new DataObject, we need to set validated back to false.
        $this->validated = false;

        // Find or create a record based on the DataObject you want to add.
        $record = $this->findOrCreateRecordByClassNameID($dataObject->ClassName, $dataObject->ID);

        // This addDataObject() method gets called many times as we try to build out the structure of related
        // DataObjects. It's quite likely that the we will come across the same record multiple times. We only need
        // to add it once.
        if (!$record->isNew()) {
            return $this;
        }

        $group = $this->fixtureManifest->getGroupByClassName($dataObject->ClassName);

        if ($group === null) {
            throw new Exception(sprintf('Group for class should have been available: %s', $dataObject->ClassName));
        }

        // Add this record to our relationship manifest
        $this->relationshipManifest->addGroup($group);
        // Add the standard DB fields for this record
        $this->addDataObjectDBFields($dataObject);

        // If the DataObject has Fluent applied, then we also need to add Localised fields.
        if ($dataObject->hasExtension(FluentExtension::class)) {
            $this->addDataObjectLocalisedFields($dataObject, $currentDepth);
        }

        if ($this->getAllowedDepth() !== null && $currentDepth > $this->getAllowedDepth()) {
            return $this;
        }

        // Add direct relationships.
        $this->addDataObjectHasOneFields($dataObject, $currentDepth);
        // Add belongs to relationships.
        $this->addDataObjectBelongsToFields($dataObject, $currentDepth);
        // has_many fields will include any relationships that you're created using many_many "through".
        $this->addDataObjectHasManyFields($dataObject, $currentDepth);
        // many_many relationships without a "through" object are not supported. Add warning for any relationships
        // we find like that.
        $this->addDataObjectManyManyFieldWarnings($dataObject);

        return $this;
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

        // Make sure our groups are organised in the best order that we can figure out.
        $this->validateRelationships();

        return Yaml::dump($this->toArray(), 3);
    }

    public function getWarnings(): array
    {
        // Make sure this is done before returning our warnings.
        $this->validateRelationships();

        return $this->warnings;
    }

    public function getAllowedDepth(): ?int
    {
        return $this->allowedDepth;
    }

    public function setAllowedDepth(?int $allowedDepth = null): FixtureService
    {
        if ($allowedDepth === 0) {
            $this->addWarning('You set an allowed depth of 0. We have assumed you meant 1.');

            $allowedDepth = 1;
        }

        $this->allowedDepth = $allowedDepth;

        return $this;
    }

    protected function toArray(): array
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
    protected function addDataObjectDBFields(DataObject $dataObject): void
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
    protected function addDataObjectHasOneFields(DataObject $dataObject, int $currentDepth = 0): void
    {
        $group = $this->fixtureManifest->getGroupByClassName($dataObject->ClassName);

        if ($group === null) {
            throw new Exception(sprintf('Unable to find Group "%s"', $dataObject->ClassName));
        }

        $record = $group->getRecordByID($dataObject->ID);

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
            $fieldClassNameMap = $dataObject->config()->get('field_classname_map');

            if ($fieldClassNameMap !== null && array_key_exists($relationFieldName, $fieldClassNameMap)) {
                $relationClassName = $dataObject->relField($fieldClassNameMap[$relationFieldName]);
            }

            // This class has requested that it not be included in relationship maps.
            $excludeClass = Config::inst()->get($relationClassName, 'exclude_from_fixture_relationships');

            if ($excludeClass) {
                continue;
            }

            $excludeRelationship = $this->relationshipManifest->shouldExcludeRelationship(
                $dataObject->ClassName,
                $fieldName
            );

            if ($excludeRelationship) {
                continue;
            }

            // If there is no value, then, we have a relationship field, but no relationship active.
            if (!$dataObject->hasValue($relationFieldName)) {
                continue;
            }

            // We expect this value to be an ID for a related object.
            if (!is_numeric($dataObject->{$relationFieldName})) {
                continue;
            }

            $relatedObjectID = (int) $dataObject->{$relationFieldName};

            // We cannot query a DataObject
            if ($relationClassName === DataObject::class) {
                continue;
            }

            $relatedObject = DataObject::get($relationClassName)->byID($relatedObjectID);

            // We expect the relationship to be a DataObject.
            if (!$relatedObject instanceof DataObject) {
                $this->addWarning(sprintf(
                    'Related Object "%s" found on "%s" was not a DataObject',
                    $relationFieldName,
                    $dataObject->ClassName
                ));

                continue;
            }

            // @todo: this method currently returns false as belongs_to is not supported in fixtures atm.
            // Don't add the relationship here, we'll add it as part of the belongs to relationship additions.
            if ($this->hasBelongsToRelationship($relatedObject, $dataObject->ClassName, $fieldName)) {
                continue;
            }

            $relationshipValue = sprintf('=>%s.%s', $relatedObject->ClassName, $relatedObject->ID);

            // Add the relationship field to our current Record.
            $record->addFieldValue($relationFieldName, $relationshipValue);

            // Add the related DataObject.
            $this->addDataObject($relatedObject, $currentDepth);

            // Find the Group for the DataObject that we should have just added.
            $relatedGroup = $this->fixtureManifest->getGroupByClassName($relatedObject->ClassName);

            if ($relatedGroup === null) {
                throw new Exception(sprintf('Unable to find Group "%s"', $relatedObject->ClassName));
            }

            // Add a relationship map for these Groups.
            $this->relationshipManifest->addRelationshipFromTo($group, $relatedGroup);
        }
    }

    /**
     * @phpcs:disable
     */
    protected function addDataObjectBelongsToFields(DataObject $dataObject, int $currentDepth = 0): void
    {
        // belongs_to fixture definitions don't appear to be support currently. This is how we can eventually solve
        // looping relationships though...
        return;
    }

    /**
     * @phpcs:disable
     */
    protected function hasBelongsToRelationship(
        DataObject $dataObject,
        string $fromObjectClassName,
        string $fromRelationship
    ): bool {
        // Belongs to fixture definitions don't appear to be support currently. This is how we can eventually solve
        // looping relationships though...
        return false;
    }

    /**
     * @throws Exception
     */
    protected function addDataObjectHasManyFields(DataObject $dataObject, int $currentDepth = 0): void
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
            // Exception if there isn't (SilverStripe always expects you to have it).
            $schema->getRemoteJoinField($dataObject->ClassName, $relationFieldName, 'has_many');

            // This class has requested that it not be included in relationship maps.
            $excludeClass = Config::inst()->get($cleanRelationshipClassName, 'exclude_from_fixture_relationships');

            if ($excludeClass) {
                continue;
            }

            $excludeRelationship = $this->relationshipManifest->shouldExcludeRelationship(
                $dataObject->ClassName,
                $relationFieldName
            );

            if ($excludeRelationship) {
                continue;
            }

            // If we have the correct relationship mapping (a "has_one" relationship on the object in the "has_many"),
            // then we can simply add each of these records and let the "has_one" be added by addRecordHasOneFields().
            foreach ($dataObject->relField($relationFieldName) as $relatedObject) {
                // Add the related DataObject. Recursion starts.
                $this->addDataObject($relatedObject, $currentDepth);
            }
        }
    }

    protected function addDataObjectManyManyFieldWarnings(DataObject $dataObject): void
    {
        /** @var array $manyManyRelationships */
        $manyManyRelationships = $dataObject->config()->get('many_many');

        if (!is_array($manyManyRelationships)) {
            return;
        }

        if (count($manyManyRelationships) === 0) {
            return;
        }

        foreach ($manyManyRelationships as $relationshipName => $relationshipValue) {
            // This many_many relationship has a "through" object, so we're all good.
            if (is_array($relationshipValue) && array_key_exists('through', $relationshipValue)) {
                continue;
            }

            // This many_many relationship is being excluded anyhow, so we're also all good here.
            $exclude = Config::inst()->get($relationshipValue, 'exclude_from_fixture_relationships');

            if ($exclude) {
                continue;
            }

            // Ok, so, you're probably expecting the fixture to include this relationship... but it won't. Here's your
            // warning.
            $this->addWarning(sprintf(
                'many_many relationships without a "through" are not supported. No yml generated for '
                    . 'relationship: %s::%s()',
                $dataObject->ClassName,
                $relationshipName
            ));
        }
    }

    /**
     * @param DataObject|FluentExtension $dataObject
     * @throws Exception
     */
    protected function addDataObjectLocalisedFields(DataObject $dataObject, int $currentDepth = 0): void
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
                        $record = $this->findOrCreateRecordByClassNameID($localisedTableName, $localisedID);

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
    protected function findOrCreateGroupByClassName(string $className): Group
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
    protected function findOrCreateRecordByClassNameID(string $className, $id): Record
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

    protected function validateRelationships(): void
    {
        // We can skip this if no extra DataObjects were added since the last time.
        if ($this->validated) {
            return;
        }

        foreach ($this->relationshipManifest->getRelationships() as $fromClass => $toClasses) {
            if (count($toClasses) === 0) {
                continue;
            }

            $parentage = [$fromClass];

            $this->removeLoopingRelationships($parentage, $toClasses);
        }

        $this->validated = true;
    }

    protected function removeLoopingRelationships(array $parentage, array $toClasses): void
    {
        $relationships = $this->relationshipManifest->getRelationships();

        foreach ($toClasses as $toClass) {
            // This To Class does not have any additional relationships that we need to consider.
            if (!array_key_exists($toClass, $relationships)) {
                continue;
            }

            // Grab the To Classes for this child relationship.
            $childToClass = $relationships[$toClass];

            // Sanity check, but we should only have keys when there are relationships. In any case, if there are no
            // relationships for this Class, then there is nothing for us to do here.
            if (count($childToClass) === 0) {
                continue;
            }

            // Check to see if there is any intersection between this Classes relationships, and the parentage tree
            // that we have drawn so far.
            $loopingRelationships = array_intersect($parentage, $childToClass);

            // If we find an intersection, then we need to remove them. The relationships are removed from the
            // manifest itself.
            if (count($loopingRelationships) > 0) {
                // We can keep the original relationship, but we'll remove the one that loops back to the original.
                foreach ($loopingRelationships as $loopingRelationship) {
                    $this->relationshipManifest->removeRelationship($toClass, $loopingRelationship);

                    $this->addWarning(sprintf(
                        'A relationships was removed between "%s" and "%s". This occurs if we have detected a'
                            . ' loop . Until belongs_to relationships are supported in fixtures, you might not be able'
                            . ' to rely on fixtures generated to have the appropriate priority order. You might want to'
                            . ' consider adding one of these relationships to `excluded_fixture_relationships`.',
                        $loopingRelationship,
                        $toClass
                    ));

                    // Find the Group for the relationship that has a loop.
                    $group = $this->fixtureManifest->getGroupByClassName($loopingRelationship);

                    if ($group === null) {
                        continue;
                    }

                    // Loop through each Record and remove this relationship.
                    foreach ($group->getRecords() as $record) {
                        $record->removeRelationshipValueForClass($toClass);
                    }
                }
            }

            // Re-fetch the relationships now that any intersections have been removed.
            $childToClass = $relationships[$toClass];

            // Check to see if we still have any relationships that need to be traversed.
            if (count($childToClass) === 0) {
                continue;
            }

            $parentage[] = $toClass;

            // This needs to be recursive, rather than a stack (while loop). It's important that we traverse one entire
            // tree before starting a new one.
            $this->removeLoopingRelationships($parentage, $childToClass);
        }
    }

    protected function addWarning(string $message): void
    {
        if (in_array($message, $this->warnings, true)) {
            return;
        }

        $this->warnings[] = $message;
    }

}
