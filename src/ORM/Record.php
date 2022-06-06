<?php

namespace ChrisPenny\DataObjectToFixture\ORM;

use SilverStripe\Core\Injector\Injectable;

class Record
{

    use Injectable;

    /**
     * @var int|string|null
     */
    private $id;

    private array $fields = [];

    /**
     * @param mixed $id
     */
    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * @return int|string|null
     */
    public function getId()
    {
        return $this->id;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @param mixed $value
     */
    public function addFieldValue(string $fieldName, $value): Record
    {
        $this->fields[$fieldName] = $value;

        return $this;
    }

    public function removeRelationshipValueForClass(string $forClass): Record
    {
        foreach ($this->fields as $fieldName => $fieldValue) {
            $pattern = sprintf('/=>%s.[0-9]+/', addslashes($forClass));

            if (!preg_match($pattern, $fieldValue)) {
                continue;
            }

            unset($this->fields[$fieldName]);
        }

        return $this;
    }

    public function isNew(): bool
    {
        return count($this->fields) === 0;
    }

}
