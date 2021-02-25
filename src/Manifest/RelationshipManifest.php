<?php

namespace ChrisPenny\DataObjectToFixture\Manifest;

use ChrisPenny\DataObjectToFixture\Helper\KahnSorter;
use ChrisPenny\DataObjectToFixture\ORM\Group;
use Exception;
use SilverStripe\Core\Config\Config;

class RelationshipManifest
{

    /**
     * @var array
     */
    private $relationships = [];

    /**
     * @var array
     */
    private $excludedRelationships = [];

    /**
     * @return array
     */
    public function getRelationships(): array
    {
        return $this->relationships;
    }

    /**
     * @param Group $group
     */
    public function addGroup(Group $group): void
    {
        // If we've added this group to relationships already, then we have everything we need
        if (array_key_exists($group->getClassName(), $this->relationships)) {
            return;
        }

        // Add this group as a new relationship
        $this->relationships[$group->getClassName()] = [];

        // Check to see if this class has any relationships that it wants to exclude as we process it
        $excludes = Config::inst()->get($group->getClassName(), 'excluded_fixture_relationships');

        // No it doesn't, so we can just return here
        if (!$excludes) {
            return;
        }

        // Add the list of relationships to exclude from our fixtures
        $this->excludedRelationships[$group->getClassName()] = $excludes;
    }

    /**
     * @param Group $from
     * @param Group $to
     * @throws Exception
     */
    public function addRelationshipFromTo(Group $from, Group $to): void
    {
        if (!array_key_exists($from->getClassName(), $this->relationships)) {
            $this->relationships[$from->getClassName()] = [];
        }

        if (in_array($to->getClassName(), $this->relationships[$from->getClassName()])) {
            return;
        }

        $this->relationships[$from->getClassName()][] = $to->getClassName();
    }

    public function resetRelationships(): void
    {
        $this->relationships = [];
    }

    /**
     * @param string $fromClass
     * @param string $toClass
     */
    public function removeRelationship(string $fromClass, string $toClass): void
    {
        // Find the key for this relationship.
        $key = array_search($fromClass, $this->relationships[$toClass]);

        // It doesn't exit, so just return.
        if ($key === false) {
            return;
        }

        // Remove it.
        unset($this->relationships[$toClass][$key]);
    }

    /**
     * @param string $className
     * @param string $relationshipName
     * @return bool
     */
    public function shouldExcludeRelationship(string $className, string $relationshipName): bool
    {
        if (!array_key_exists($className, $this->excludedRelationships)) {
            return false;
        }

        $exclusions = $this->excludedRelationships[$className];

        return in_array($relationshipName, $exclusions);
    }

    /**
     * @return array
     */
    public function getPrioritisedOrder(): array
    {
        $kahnSorter = new KahnSorter($this->getRelationships());

        return $kahnSorter->sort();
    }

    /**
     * @return array
     */
    public function getExcludedRelationships(): array
    {
        return $this->excludedRelationships;
    }

}
