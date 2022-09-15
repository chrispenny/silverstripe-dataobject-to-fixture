<?php

namespace ChrisPenny\DataObjectToFixture\Manifest;

use ChrisPenny\DataObjectToFixture\Helper\KahnSorter;
use ChrisPenny\DataObjectToFixture\ORM\Group;
use Exception;
use SilverStripe\Core\Config\Config;

class RelationshipManifest
{

    private array $relationships = [];

    private array $excludedRelationships = [];

    private array $manyManyRelationships = [];

    public function getRelationships(): array
    {
        return $this->relationships;
    }

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
     * @throws Exception
     */
    public function addRelationship(string $from, string $to): void
    {
        if (!array_key_exists($from, $this->relationships)) {
            $this->relationships[$from] = [];
        }

        if (in_array($to, $this->relationships[$from], true)) {
            return;
        }

        $this->relationships[$from][] = $to;
    }

    public function hasManyManyThroughRelationship(string $throughClass): bool
    {
        // Tracking through classes is way easier... They always have to be defined in a really structured way, and they
        // always have to share the same through class
        return array_key_exists($throughClass, $this->manyManyRelationships);
    }

    /**
     * @param string $fromClass
     * @param string $fromRelation
     * @param string $to Could be dot notation (ClassName.RelationshipName) or it could just be a ClassName
     * @return bool
     */
    public function hasManyManyRelationship(string $fromClass, string $fromRelation, string $to): bool
    {
        // many_many relationships can be defined on either side of the relationship (or both sides of the relationship)

        // Another tricky aspect of many_many is that one (or both) sides of the relationship might have been defined
        // using dot notation. So we just have to consider both

        // We always track our relationships in $manyManyRelationships using ClassName.RelationshipName as the key

        // Silverstripe does not support polymorphic many_many relationships (without a through object). So, we
        // essentially know that each many_many relationship can only relate to one other DataObject

        // We always track our relationships in $manyManyRelationships using ClassName.RelationshipName as the key,
        // which means they're all unique
        $from = sprintf('%s.%s', $fromClass, $fromRelation);

        // Let's first check to see if our $from exists
        $relationshipTo = $this->manyManyRelationships[$from] ?? null;

        // We can see that this relationship has already been tracked as a key, so we know we're covered, we don't
        // care about checking the $relationshipTo value (see above comment about SS not supporting polymorphic)
        if ($relationshipTo) {
            return true;
        }

        // Now we want to check to see if this relationship has potentially been tracked in the other direction
        // Again though, remember that we don't know if the relationship was defined with dot notations by the dev

        // Let's search to see if we can find our $from (dot notation) as a value in the array
        $relationshipFrom = array_search($from, $this->manyManyRelationships, true);

        // Again, remember that these relationships are unique and can't be polymorphic, so if we find this particular
        // dot notation as a value, then it means we have already tracked this relationship
        if ($relationshipFrom) {
            return true;
        }

        // If the $to value was provided in dot notation, then it's also possible that this exists as a key
        $relationshipTo = $this->manyManyRelationships[$to] ?? null;

        // We can see that this relationship has already been tracked as a key, so we know we're covered, we don't
        // care about checking the $relationshipTo value (see above comment about SS not supporting polymorphic)
        if ($relationshipTo) {
            return true;
        }

        // The last possibility is that our $to might not have been provided in dot notation, so we'll have to go
        // searching for a match

        // Grab the ClassName out of our $to value. It doesn't matter if $to was provided in dot notation or not
        [$toClass] = explode('.', $to);

        // Let's search to see if we can find just the $fromClass (without dot notation)
        $relationshipsFrom = array_keys($this->manyManyRelationships, $fromClass, true);

        // Because we are searching for non-dot notation, it's possible that we have received multiple results for
        // classes that relate to our $fromClass
        // If we didn't find any results above, that's fine, nothing will happen in this loop
        foreach ($relationshipsFrom as $relationshipFrom) {
            // We would expect the $toClass to specifically be represented at the very start of our $relationshipFrom
            if (strpos($toClass, $relationshipFrom) === 0) {
                return true;
            }
        }

        // The relationship has not been tracked. What a journey
        return false;
    }

    public function addManyManyRelationship(string $fromClass, string $fromRelation, string $to): void
    {
        // We always store our keys as dot notation
        $from = sprintf('%s.%s', $fromClass, $fromRelation);

        if (!array_key_exists($from, $this->manyManyRelationships)) {
            $this->manyManyRelationships[$from] = [];
        }

        // $to value *might* be dot notation, if a developer defined it as such
        $this->manyManyRelationships[$from][] = $to;
    }

    public function addManyManyThroughRelationship(string $throughClass): void
    {
        // Tracking through classes is way easier... They always have to be defined in a really structured way, and they
        // always have to share the same through class. So, we're just setting this value to true, and we don't have to
        // worry beyond that
        $this->manyManyRelationships[$throughClass] = true;
    }

    public function resetRelationships(): void
    {
        $this->relationships = [];
    }

    public function removeRelationship(string $fromClass, string $toClass): void
    {
        // Find the key for this relationship
        $key = array_search($fromClass, $this->relationships[$toClass], true);

        // It doesn't exit, so just return
        if ($key === false) {
            return;
        }

        // Remove it
        unset($this->relationships[$toClass][$key]);
    }

    public function shouldExcludeRelationship(string $className, string $relationshipName): bool
    {
        if (!array_key_exists($className, $this->excludedRelationships)) {
            return false;
        }

        $exclusions = $this->excludedRelationships[$className];

        return in_array($relationshipName, $exclusions, true);
    }

    public function getPrioritisedOrder(): array
    {
        $kahnSorter = new KahnSorter($this->getRelationships());

        return $kahnSorter->sort();
    }

    public function getExcludedRelationships(): array
    {
        return $this->excludedRelationships;
    }

}
