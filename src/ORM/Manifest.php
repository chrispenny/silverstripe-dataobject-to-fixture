<?php

namespace ChrisPenny\DataObjectToFixture\ORM;

use ChrisPenny\DataObjectToFixture\Helper\FluentHelper;
use Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use Symfony\Component\Yaml\Yaml;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Class Manifest
 *
 * @package App\Module
 */
class Manifest
{
    /**
     * @var Group[]
     */
    private $groups = [];

    /**
     * @var string[]
     */
    private $warnings = [];

    /**
     * @param DataObject $dataObject
     * @param int|null $ownerPriority
     * @return Manifest
     * @throws Exception
     */
    public function addDataObject(DataObject $dataObject, ?int $ownerPriority = null): Manifest
    {
        if (!$dataObject->exists()) {
            throw new Exception('Your DataObject must be in the DB');
        }

        // Find or create a record based on our DataObject.
        $record = $this->findOrCreateRecordByClassNameID($dataObject->ClassName, $dataObject->ID);

        // This method gets called as part of addRecordHasOneFields() - when that happens, we need to make sure that
        // this related DataObject has a higher priority than it's owner (so that it is rendered higher up in the
        // fixture file.
        if ($ownerPriority !== null) {
            // We need this Group to have a higher priority than the owner.
            $priority = $ownerPriority + 1;
            $group = $this->findOrCreateGroupByClassName($dataObject->ClassName);

            // Update the Group's priority. If the Group has a priority already, it'll keep whichever is higher.
            $group->updateHighestPriority($priority);
        }

        // While we're processing lots of relationships, it's possible that we'll add the same record multiple times.
        // We only want/need to generate the fields for that record once, but we might need to increase it's priority
        // more than once.
        if ($record->isNew()) {
            $this->addDataObjectDBFields($dataObject);
            // has_many fields will include any relationships that you're created using many_many "through".
            $this->addDataObjectHasManyFields($dataObject);
            // Add direct relationships.
            $this->addDataObjectHasOneFields($dataObject);
            // many_many relationships without a "through" object are not supported. Add warning for any relationships
            // we find like that.
            $this->addDataObjectManyManyFieldWarnings($dataObject);
        } else {
            $this->updateDataObjectHasOnePriorities($dataObject);
        }

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

        $group = $this->findOrCreateGroupByClassName(Locale::class);

        // @todo: should be smarter than this. Lookup the highest priority and make this more.
        $group->updateHighestPriority(99);

        /** @var DataList|Locale[] $locales */
        $locales = Locale::get();

        foreach ($locales as $locale) {
            // @todo: should be a bit smarter than this. Developer might have additional fields to export.
            $record = $this->findOrCreateRecordByClassNameID(Locale::class, $locale->ID);
            $record->addFieldValue('Locale', $locale->Locale);
            $record->addFieldValue('Title', $locale->Title);
            $record->addFieldValue('URLSegment', $locale->URLSegment);
            $record->addFieldValue('IsGlobalDefault', $locale->IsGlobalDefault);
        }

        return Yaml::dump($this->toArray(), 3);
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array
     */
    protected function toArray(): array
    {
        $toArrayGroups = [];

        foreach ($this->getGroupsPrioritised() as $group) {
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
        $record = $this->findOrCreateRecordByClassNameID($dataObject->ClassName, $dataObject->ID);

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
        $record = $this->findOrCreateRecordByClassNameID($dataObject->ClassName, $dataObject->ID);
        $group = $this->getGroupByClassName($dataObject->ClassName);

        /** @var array $hasOneRelationships */
        $hasOneRelationships = $dataObject->config()->get('has_one');
        if (!is_array($hasOneRelationships)) {
            return;
        }

        foreach ($hasOneRelationships as $fieldName => $relationClassName) {
            $relationFieldName = sprintf('%sID', $fieldName);

            // This class has requested that it not be included in relationship maps.
            $exclude = Config::inst()->get($relationClassName, 'exclude_from_fixture_relationships');
            if ($exclude) {
                continue;
            }

            /** @var DataObject $relatedObject */
            $relatedObject = $dataObject->relField($fieldName);
            if (!$relatedObject->exists()) {
                continue;
            }

            $relationshipValue = sprintf('=>%s.%s', $relatedObject->ClassName, $relatedObject->ID);

            // Add the relationship field to our current Record.
            $record->addFieldValue($relationFieldName, $relationshipValue);

            // Add the related DataObject. Recursion starts.
            $this->addDataObject($relatedObject, $group->getPriority());
        }
    }

    /**
     * @param DataObject $dataObject
     * @throws Exception
     */
    protected function updateDataObjectHasOnePriorities(DataObject $dataObject): void
    {
        $group = $this->getGroupByClassName($dataObject->ClassName);

        /** @var array $hasOneRelationships */
        $hasOneRelationships = $dataObject->config()->get('has_one');
        if (!is_array($hasOneRelationships)) {
            return;
        }

        foreach ($hasOneRelationships as $fieldName => $relationClassName) {
            /** @var DataObject $relatedObject */
            $relatedObject = $dataObject->relField($fieldName);
            if (!$relatedObject->exists()) {
                continue;
            }

            $relatedGroup = $this->getGroupByClassName($relatedObject->ClassName);

            if ($relatedGroup === null) {

                continue;
            }

            $relatedGroup->updateHighestPriority(($group->getPriority() + 1));
        }
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
            $this->warnings[] = sprintf(
                'many_many relationships without a "through" are not supported. No yml generated for '
                . 'relationship: %s::%s()',
                $dataObject->ClassName,
                $relationshipName
            );
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
        $relatedDataObjects = ArrayList::create();

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
                        $this->warnings[] = sprintf(
                            'DataObject Localisation could not be found for Class: %s | ID: %s | Locale %s',
                            $className,
                            $id,
                            $locale
                        );

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
                                $relatedDataObjects->push($fieldValue);

                                $relationshipValue = sprintf('=>%s.%s', $fieldValue->ClassName, $fieldValue->ID);

                                // Add the relationship field to our current Record.
                                $record->addFieldValue($localisedField, $relationshipValue);

                                continue;
                            }

                            if ($fieldValue instanceof HasManyList) {
                                foreach ($fieldValue as $relatedDataObject) {
                                    $relatedDataObjects->push($relatedDataObject);
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
     * @return Group[]
     */
    protected function getGroupsPrioritised(): array
    {
        $groups = $this->groups;

        // Sort 'em! Highest priority number comes first (rendered at the top of the fixture file).
        uasort($groups, function (Group $a, Group $b) {
            if ($a->getPriority() == $b->getPriority()) {
                return 0;
            }

            return ($a->getPriority() > $b->getPriority()) ? -1 : 1;
        });

        return $groups;
    }

    /**
     * @param string $className
     * @return Group|null
     */
    protected function getGroupByClassName(string $className): ?Group
    {
        if (!array_key_exists($className, $this->groups)) {
            return null;
        }

        return $this->groups[$className];
    }

    /**
     * @param string $className
     * @return Group|null
     * @throws Exception
     */
    protected function createGroup(string $className): ?Group
    {
        $group = new Group($className);

        $this->groups[$className] = $group;

        return $this->getGroupByClassName($className);
    }

    /**
     * @param string $className
     * @return Group
     * @throws Exception
     */
    protected function findOrCreateGroupByClassName(string $className): Group
    {
        $group = $this->getGroupByClassName($className);

        if ($group !== null) {
            return $group;
        }

        $group = $this->createGroup($className);

        // The Group should have been available. If it isn't, that's a paddlin.
        if ($group === null) {
            throw new Exception('Group should have been available');
        }

        return $group;
    }

    /**
     * @param string $className
     * @param mixed $id
     * @return Record
     * @throws Exception
     */
    protected function findOrCreateRecordByClassNameID(string $className, $id): Record
    {
        $group = $this->findOrCreateGroupByClassName($className);

        // The Group should have been available. If it isn't, that's a paddlin.
        if ($group === null) {
            throw new Exception('Group should have been available');
        }

        $record = $group->findOrCreateRecordByID($id);

        // This shouldn't ever happen, but better safe than sorry.
        if ($record === null) {
            throw new Exception('Record should have been available');
        }

        return $record;
    }
}
