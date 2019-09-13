<?php

namespace ChrisPenny\DataObjectToFixture\ORM;

/**
 * Class Group
 *
 * @package App\Module
 */
class Group
{
    /**
     * @var string
     */
    private $className;

    /**
     * @var int
     */
    private $priority = 0;

    /**
     * @var array|Record[]
     */
    private $records = [];

    /**
     * @param string $className
     */
    public function __construct(string $className)
    {
        $this->className = $className;
    }

    /**
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
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
     * @return Record|null
     */
    public function getRecordByID($id): ?Record
    {
        if (!array_key_exists($id, $this->records)) {
            return null;
        }

        return $this->records[$id];
    }

    /**
     * @param mixed $id
     * @return Record
     */
    public function findOrCreateRecordByID($id): Record
    {
        $record = $this->getRecordByID($id);
        if ($record !== null) {
            return $record;
        }

        return $this->createRecord($id);
    }

    /**
     * @param int $priority
     */
    public function updateHighestPriority(int $priority): void
    {
        if ($priority <= $this->priority) {
            return;
        }

        $this->priority = $priority;
    }

    /**
     * @return Record[]
     */
    public function toArray(): array
    {
        $records = [];

        foreach ($this->records as $record) {
            $records[$record->getId()] = $record->getFields();
        }

        return $records;
    }

    /**
     * @return bool
     */
    public function isNew(): bool
    {
        return count($this->records) === 0;
    }

    /**
     * @param mixed $id
     * @return Record
     */
    protected function createRecord($id): Record
    {
        $record = new Record($id);

        $this->records[$id] = $record;

        return $record;
    }
}
