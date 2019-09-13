<?php

namespace ChrisPenny\DataObjectToFixture\ORM;

/**
 * Class Record
 *
 * @package App\Module
 */
class Record
{
    /**
     * @var int|string|null
     */
    private $id;

    /**
     * @var array
     */
    private $fields = [];

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

    /**
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @param string $fieldName
     * @param mixed $value
     * @return Record
     */
    public function addFieldValue(string $fieldName, $value): Record
    {
        $this->fields[$fieldName] = $value;
        return $this;
    }

    /**
     * @return bool
     */
    public function isNew(): bool
    {
        return count($this->fields) === 0;
    }
}
