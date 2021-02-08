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
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use Symfony\Component\Yaml\Yaml;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

class FixtureService
{
    use Injectable;

    /**
     * @var FixtureManifest
     */
    private $fixtureManifest;

    /**
     * @var RelationshipManifest
     */
    private $relationshipManifest;

    /**
     * @var bool
     */
    private $validated = false;

    /**
     * @var bool
     */
    private $organised = false;

    /**
     * @var string[]
     */
    private $warnings = [];

    public function __construct()
    {
        $this->fixtureManifest = new FixtureManifest();
        $this->relationshipManifest = new RelationshipManifest();
    }

    /**
     * @param DataObject $dataObject
     * @return FixtureService
     * @throws Exception
     */
    public function addDataObject(DataObject $dataObject): FixtureService
    {
        if (!$dataObject->exists()) {
            throw new Exception('Your DataObject must be in the DB');
        }

        // Any time we add a new DataObject, we need to set validated back to false.
        $this->validated = false;
        $this->organised = false;

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
        // Add direct relationships.
        $this->addDataObjectHasOneFields($dataObject);
        // Add belongs to relationships.
        $this->addDataObjectBelongsToFields($dataObject);
        // has_many fields will include any relationships that you're created using many_many "through".
        $this->addDataObjectHasManyFields($dataObject);
        // many_many relationships without a "through" object are not supported. Add warning for any relationships
        // we find like that.
        $this->addDataObjectManyManyFieldWarnings($dataObject);

        // If the DataObject has Fluent applied, then we also need to add Localised fields.
        if ($dataObject->hasExtension(FluentExtension::class)) {
            $this->addDataObjectLocalisedFields($dataObject);
        }

        return $this;
    }

    /**
     * @return string
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
        // Yes, it's possible that the Locale Group is already set as the highest priority, and that we're about to
        // increase that by 1 when we don't need to, but, it really doens't matter.
        $localePriority = $this->fixtureManifest->findMaxPriority() + 1;
        $group->updateToHighestPriority($localePriority);

        // Only add the Locale Records if this Group was/is new.
        if ($group->isNew()) {
            /** @var DataList|Locale[] $locales */
            $locales = Locale::get();

            // Add all Locale Records.
            foreach ($locales as $locale) {
                $this->addDataObject($locale);
            }
        }

        // Make sure our groups are organised in the best order that we can figure out.
        $this->validateRelationships();

        $this->organiseFixtureManifest();

        return Yaml::dump($this->toArray(), 3);
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        // Make sure this is done before returning our warnings.
        $this->validateRelationships();

        return $this->warnings;
    }

    /**
     * @return array
     */
    protected function toArray(): array
    {
        $toArrayGroups = [];

        foreach ($this->fixtureManifest->getGroupsPrioritised() as $group) {
            $toArrayGroups[$group->getClassName()] = $group->toArray();
        }

        return $toArrayGroups;
    }

    /**
     * @param DataObject $dataObject
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

        foreach ($dbFields as $fieldName => $fieldType) {
            // DB fields are pretty simple key => value.
            $value = $dataObject->relField($fieldName);

            $record->addFieldValue($fieldName, $value);
        }
    }

    /**
     * @param DataObject $dataObject
     * @throws Exception
     */
    protected function addDataObjectHasOneFields(DataObject $dataObject): void
    {
        $group = $this->fixtureManifest->getGroupByClassName($dataObject->ClassName);

        if ($group === null) {
            throw new Exception(sprintf('Unable to find Group "%s"', $dataObject->ClassName));
        }

        $record = $group->getRecordByID($dataObject->ID);

        if ($group === null) {
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
            $exclude = Config::inst()->get($relationClassName, 'exclude_from_fixture_relationships');

            if ($exclude) {
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
            if($relationClassName == DataObject::class) {
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
            $this->addDataObject($relatedObject);

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
     * @param DataObject $dataObject
     * @throws Exception
     */
    protected function addDataObjectBelongsToFields(DataObject $dataObject): void
    {
        // belongs_to fixture definitions don't appear to be support currently. This is how we can eventually solve
        // looping relationships though...
        return;
    }

    /**
     * @param DataObject $dataObject
     * @param string $fromObjectClassName
     * @param string $fromRelationship
     * @return bool
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
     * @param DataObject $dataObject
     * @throws Exception
     */
    protected function addDataObjectHasManyFields(DataObject $dataObject): void
    {
        /** @var array $hasManyRelationships */
        $hasManyRelationships = $dataObject->config()->get('has_many');
        if (!is_array($hasManyRelationships)) {
            return;
        }

        $schema = $dataObject->getSchema();

        foreach ($hasManyRelationships as $relationFieldName => $relationClassName) {
            // Use Schema to make sure that this relationship has a reverse has_one created. This will throw an
            // Exception if there isn't (SilverStripe always expects you to have it).
            $schema->getRemoteJoinField($dataObject->ClassName, $relationFieldName, 'has_many');
            
            // This class has requested that it not be included in relationship maps.
            $exclude = Config::inst()->get($relationClassName, 'exclude_from_fixture_relationships');
            if ($exclude) {
                continue;
            }
            
            // If we have the correct relationship mapping (a "has_one" relationship on the object in the "has_many"),
            // then we can simply add each of these records and let the "has_one" be added by addRecordHasOneFields().
            foreach ($dataObject->relField($relationFieldName) as $relatedObject) {
                // Add the related DataObject. Recursion starts.
                $this->addDataObject($relatedObject);
            }
        }
    }

    /**
     * @param DataObject $dataObject
     */
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
    protected function addDataObjectLocalisedFields(DataObject $dataObject): void
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
                function (FluentState $state) use(
                    $localisedTables,
                    $relatedDataObjects,
                    $locale,
                    $className,
                    $id
                ): void {
                    $state->setLocale($locale);

                    // Re-fetch our DataObject. This time it should be Localised with all of the specific content that we
                    // need to export for this Locale.
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
                            $isIDField = (substr($localisedField, -2) === 'ID') ;

                            if ($isIDField) {
                                $relationshipName = substr($localisedField, 0, -2);

                                $fieldValue = $localisedDataObject->relField($relationshipName);
                            } else {
                                $fieldValue = $localisedDataObject->relField($localisedField);
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

                                continue;
                            }

                            $record->addFieldValue($localisedField, $fieldValue);
                        }
                    }
                }
            );
        }

        foreach ($relatedDataObjects as $relatedDataObject) {
            $this->addDataObject($relatedDataObject);
        }
    }

    /**
     * @param string $className
     * @return Group
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
     * @param string $className
     * @param string|int $id
     * @return Record
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

    /**
     * @throws Exception
     */
    protected function organiseFixtureManifest(): void
    {
        // We can skip this if no extra DataObjects were added since the last time.
        if ($this->organised) {
            return;
        }

        // Reset all of our Groups back to priority 0.
        foreach ($this->fixtureManifest->getGroups() as $group) {
            $group->resetPriority();
        }

        foreach ($this->relationshipManifest->getRelationships() as $fromClass => $toClasses) {
            $this->updateGroupPriorities($fromClass, $toClasses);
        }
    }

    /**
     * @param string $fromClass
     * @param array $toClasses
     * @throws Exception
     */
    protected function updateGroupPriorities(string $fromClass, array $toClasses)
    {
        $fromGroup = $this->fixtureManifest->getGroupByClassName($fromClass);

        if ($fromGroup === null) {
            throw new Exception(sprintf('Unable to find Group "%s"', $fromClass));
        }

        // If a $fromClass has no relationships, then it can safely be chucked at the top of the priorities.
        if (count($toClasses) === 0) {
            $fromGroup->updateToHighestPriority(999);
        }

        $newPriority = $fromGroup->getPriority() + 1;
        $relationships = $this->relationshipManifest->getRelationships();

        foreach ($toClasses as $toClass) {
            $toGroup = $this->fixtureManifest->getGroupByClassName($toClass);

            if ($toGroup === null) {
                throw new Exception(sprintf('Unable to find Group "%s"', $fromClass));
            }

            $toGroup->updateToHighestPriority($newPriority);

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

            // This needs to be recursive, rather than a stack (while loop). It's important that we traverse one entire
            // tree before starting a new one.
            $this->updateGroupPriorities($toClass, $childToClass);
        }
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

    /**
     * @param array $parentage
     * @param array $toClasses
     */
    protected function removeLoopingRelationships(array $parentage, array $toClasses)
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
                        'A relationships was removed between "%s" and "%s". This occurs if we have detected a loop'
                        . '. Until belongs_to relationships are supported in fixtures, you might not be able to rely on'
                        . ' fixtures generated to have the appropriate priority order',
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

    /**
     * @param string $message
     */
    protected function addWarning(string $message): void
    {
        if (in_array($message, $this->warnings)) {
            return;
        }

        $this->warnings[] = $message;
    }
}
