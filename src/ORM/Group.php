<?php

namespace ChrisPenny\DataObjectToFixture\ORM;

use SilverStripe\Core\Injector\Injectable;

/**
 * Class Group
 *
 * @package App\Module
 */
class Group
{
    use Injectable;

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

    public function resetPriority(): void
    {
        $this->priority = 0;
    }

    /**
     * @param int $priority
     */
    public function updateToHighestPriority(int $priority): void
    {
        if ($priority <= $this->priority) {
            return;
        }

        $this->priority = $priority;
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
     * @param Record $record
     */
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

    /**
     * @return bool
     */
    public function isNew(): bool
    {
        return count($this->records) === 0;
    }
}
