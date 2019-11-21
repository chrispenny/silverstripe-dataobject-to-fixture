<?php

namespace ChrisPenny\DataObjectToFixture\Manifest;

use ChrisPenny\DataObjectToFixture\ORM\Group;
use ChrisPenny\DataObjectToFixture\ORM\Record;
use Exception;

/**
 * Class Manifest
 *
 * @package App\Module
 */
class FixtureManifest
{
    /**
     * @var Group[]
     */
    private $groups = [];

    /**
     * @param Group $group
     * @throws Exception
     */
    public function addGroup(Group $group): void
    {
        $this->groups[$group->getClassName()] = $group;
    }

    /**
     * @param string $className
     * @return Group|null
     */
    public function getGroupByClassName(string $className): ?Group
    {
        if (!array_key_exists($className, $this->groups)) {
            return null;
        }

        return $this->groups[$className];
    }

    /**
     * @param string $className
     * @param string|int $id
     * @return Record|null
     */
    public function getRecordByClassNameID(string $className, $id): ?Record
    {
        $group = $this->getGroupByClassName($className);

        if ($group === null) {
            return null;
        }

        $record = $group->getRecordByID($id);

        if ($group === null) {
            return null;
        }

        return $record;
    }

    /**
     * @return Group[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return Group[]
     */
    public function getGroupsPrioritised(): array
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
     * @return int
     */
    public function findMaxPriority(): int
    {
        // 0 is the default priority. If we have no groups, then the highest priority is 0.
        if (count($this->groups) === 0) {
            return 0;
        }

        $groups = $this->getGroupsPrioritised();

        /** @var Group $highestGroup */
        $highestGroup = array_shift($groups);

        return $highestGroup->getPriority();
    }
}
