<?php

namespace ChrisPenny\DataObjectToFixture\ORM;

use SilverStripe\Core\Injector\Injectable;

class Group
{

    use Injectable;

    private ?string $className;

    /**
     * @var array|Record[]
     */
    private array $records = [];

    public function __construct(string $className)
    {
        $this->className = $className;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return array|Record[]
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @param mixed $id
     */
    public function getRecordByID($id): ?Record
    {
        if (!array_key_exists($id, $this->records)) {
            return null;
        }

        return $this->records[$id];
    }

    public function addRecord(Record $record): void
    {
        $this->records[$record->getId()] = $record;
    }

    /**
     * @return Record[]
     */
    public function toArray(): array
    {
        $records = [];

        foreach ($this->records as $record) {
            $fields = $record->getFields();

            if (count($fields) === 0) {
                continue;
            }

            $records[$record->getId()] = $record->getFields();
        }

        return $records;
    }

    public function isNew(): bool
    {
        return count($this->records) === 0;
    }

}
