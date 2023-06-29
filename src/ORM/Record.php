<?php

namespace ChrisPenny\DataObjectToFixture\ORM;

use SilverStripe\Core\Injector\Injectable;

class Record
{

    use Injectable;

    private array $fields = [];

    public function __construct(private readonly string|int $id)
    {
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function addFieldValue(string $fieldName, mixed $value): Record
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
