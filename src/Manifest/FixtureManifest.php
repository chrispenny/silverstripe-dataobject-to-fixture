<?php

namespace ChrisPenny\DataObjectToFixture\Manifest;

use ChrisPenny\DataObjectToFixture\ORM\Group;
use ChrisPenny\DataObjectToFixture\ORM\Record;
use Exception;

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

}
