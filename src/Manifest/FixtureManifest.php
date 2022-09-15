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
    private array $groups = [];

    /**
     * @throws Exception
     */
    public function addGroup(Group $group): void
    {
        $this->groups[$group->getClassName()] = $group;
    }

    public function getGroupByClassName(string $className): ?Group
    {
        if (!array_key_exists($className, $this->groups)) {
            return null;
        }

        return $this->groups[$className];
    }

    /**
     * @param string|int $id
     */
    public function getRecordByClassNameId(string $className, $id): ?Record
    {
        $group = $this->getGroupByClassName($className);

        if ($group === null) {
            return null;
        }

        return $group->getRecordById($id);
    }

    /**
     * @return Group[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

}
