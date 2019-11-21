<?php

namespace ChrisPenny\DataObjectToFixture\Manifest;

use ChrisPenny\DataObjectToFixture\ORM\Group;
use Exception;

class RelationshipManifest
{
    /**
     * @var array
     */
    public $relationships = [];

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
        if (array_key_exists($group->getClassName(), $this->relationships)) {
            return;
        }

        $this->relationships[$group->getClassName()] = [];
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
}
