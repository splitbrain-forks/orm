<?php

namespace ORM\Dbal;

use ORM\Entity;
use ORM\EntityManager;
use ORM\Exception\InvalidArgument;
use ORM\Exception\NotScalar;
use ORM\Exception\UnsupportedDriver;

/**
 * Base class for database abstraction
 *
 * @package ORM
 * @author  Thomas Flori <thflori@gmail.com>
 */
abstract class Dbal
{
    /** @var array */
    protected static $typeMapping = [];

    /** @var EntityManager */
    protected $entityManager;
    /** @var string */
    protected $quotingCharacter = '"';
    /** @var string */
    protected $identifierDivider = '.';
    /** @var string */
    protected $booleanTrue = '1';
    /** @var string */
    protected $booleanFalse = '0';

    /**
     * Dbal constructor.
     *
     * @param EntityManager $entityManager
     * @param array         $options
     */
    public function __construct(EntityManager $entityManager, array $options = [])
    {
        $this->entityManager = $entityManager;

        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * Set $option to $value
     *
     * @param string $option
     * @param mixed  $value
     * @return self
     */
    public function setOption($option, $value)
    {
        switch ($option) {
            case EntityManager::OPT_IDENTIFIER_DIVIDER:
                $this->identifierDivider = $value;
                break;

            case EntityManager::OPT_QUOTING_CHARACTER:
                $this->quotingCharacter = $value;
                break;

            case EntityManager::OPT_BOOLEAN_TRUE:
                $this->booleanTrue = $value;
                break;

            case EntityManager::OPT_BOOLEAN_FALSE:
                $this->booleanFalse = $value;
                break;
        }
        return $this;
    }

    /**
     * Returns $identifier quoted for use in a sql statement
     *
     * @param string $identifier Identifier to quote
     * @return string
     */
    public function escapeIdentifier($identifier)
    {
        $quote = $this->quotingCharacter;
        $divider = $this->identifierDivider;
        return $quote . str_replace($divider, $quote . $divider . $quote, $identifier) . $quote;
    }

    /**
     * Returns $value formatted to use in a sql statement.
     *
     * @param  mixed $value The variable that should be returned in SQL syntax
     * @return string
     * @throws NotScalar
     */
    public function escapeValue($value)
    {
        $type   = is_object($value) ? get_class($value) : gettype($value);
        $method = [ $this, 'escape' . ucfirst($type) ];

        if (is_callable($method)) {
            return call_user_func($method, $value);
        } else {
            throw new NotScalar('$value has to be scalar data type. ' . gettype($value) . ' given');
        }
    }

    /**
     * Describe a table
     *
     * @param string $table
     * @return Table|Column[]
     * @throws UnsupportedDriver
     */
    public function describe($table)
    {
        throw new UnsupportedDriver('Not supported for this driver');
    }

    /**
     * Inserts $entity in database and synchronizes the entity
     *
     * Returns whether the insert was successful or not.
     *
     * @param Entity $entity
     * @param bool   $useAutoIncrement
     * @return bool
     * @throws UnsupportedDriver
     */
    public function insert(Entity $entity, $useAutoIncrement = true)
    {
        $statement = $this->buildInsertStatement($entity);

        if ($useAutoIncrement && $entity::isAutoIncremented()) {
            throw new UnsupportedDriver('Auto incremented column for this driver is not supported');
        }

        $this->entityManager->getConnection()->query($statement);
        return $this->entityManager->sync($entity, true);
    }

    /**
     * Inserts $entities in one query
     *
     * If update is false the entities will not be synchronized after insert.
     *
     * @param Entity[] $entities
     * @param bool $update
     * @param bool $useAutoIncrement
     * @return bool
     * @throws UnsupportedDriver
     * @throws InvalidArgument
     * @throws \ORM\Exception\NoConnection
     */
    public function bulkInsert(array $entities, $update = true, $useAutoIncrement = true)
    {
        if (count($entities) === 0) {
            throw new InvalidArgument('$entities should not be empty');
        }
        $statement = $this->buildInsertStatement(...$entities);

        $entity = reset($entities);
        if ($useAutoIncrement && $entity::isAutoIncremented()) {
            throw new UnsupportedDriver('Auto incremented column for this driver is not supported');
        }

        $pdo = $this->entityManager->getConnection()->query($statement);
        if ($update) {
            $this->syncInserted(...$entities);
        }
        return true;
    }

    /**
     * Update $entity in database and returns success
     *
     * @param Entity $entity
     * @return bool
     * @internal
     */
    public function update(Entity $entity)
    {
        $data       = $entity->getData();
        $primaryKey = $entity->getPrimaryKey();

        $where = [];
        foreach ($primaryKey as $attribute => $value) {
            $col     = $entity::getColumnName($attribute);
            $where[] = $this->escapeIdentifier($col) . ' = ' . $this->escapeValue($value);
            if (isset($data[$col])) {
                unset($data[$col]);
            }
        }

        $set = [];
        foreach ($data as $col => $value) {
            $set[] = $this->escapeIdentifier($col) . ' = ' . $this->escapeValue($value);
        }

        $statement = 'UPDATE ' . $this->escapeIdentifier($entity::getTableName()) . ' ' .
                     'SET ' . implode(',', $set) . ' ' .
                     'WHERE ' . implode(' AND ', $where);
        $this->entityManager->getConnection()->query($statement);

        return $this->entityManager->sync($entity, true);
    }

    /**
     * Delete $entity from database
     *
     * This method does not delete from the map - you can still receive the entity via fetch.
     *
     * @param Entity $entity
     * @return bool
     */
    public function delete(Entity $entity)
    {
        $primaryKey = $entity->getPrimaryKey();
        $where      = [];
        foreach ($primaryKey as $attribute => $value) {
            $col     = $entity::getColumnName($attribute);
            $where[] = $this->escapeIdentifier($col) . ' = ' . $this->escapeValue($value);
        }

        $statement = 'DELETE FROM ' . $this->escapeIdentifier($entity::getTableName()) . ' ' .
                     'WHERE ' . implode(' AND ', $where);
        $this->entityManager->getConnection()->query($statement);

        return true;
    }

    /**
     * Build the insert statement for $entity
     *
     * @param Entity $entity
     * @param Entity[] $entities
     * @return string
     */
    protected function buildInsertStatement(Entity $entity, Entity ...$entities)
    {
        array_unshift($entities, $entity);
        $cols = [];
        $rows = [];
        foreach ($entities as $entity) {
            $data = $entity->getData();
            $cols = array_unique(array_merge($cols, array_keys($data)));
            $rows[] = $data;
        }

        $cols = array_combine($cols, array_map([$this, 'escapeIdentifier'], $cols));

        $statement = 'INSERT INTO ' . $this->escapeIdentifier($entity::getTableName()) . ' ' .
                     '(' . implode(',', $cols) . ') VALUES ';

        $statement .= implode(',', array_map(function ($values) use ($cols) {
            $result = [];
            foreach ($cols as $key => $col) {
                $result[] = isset($values[$key]) ? $this->escapeValue($values[$key]) : $this->escapeNULL();
            }
            return '(' . implode(',', $result) . ')';
        }, $rows));

        return $statement;
    }

    /**
     * Update the autoincrement value
     *
     * @param Entity     $entity
     * @param int|string $value
     */
    protected function updateAutoincrement(Entity $entity, $value)
    {
        $var    = $entity::getPrimaryKeyVars()[0];
        $column = $entity::getColumnName($var);

        $entity->setOriginalData(array_merge($entity->getData(), [ $column => $value ]));
        $entity->__set($var, $value);
    }

    protected function syncInserted(Entity ...$entities)
    {
        if (count($entities) === 0) {
            throw new Exception\InvalidArgument('$entities should not be empty');
        }

        $entity = reset($entities);
        $vars = $entity::getPrimaryKeyVars();
        $cols = array_map([$entity, 'getColumnName'], $vars);
        $primary = array_combine($vars, $cols);

        $query = "SELECT * FROM " . $this->escapeIdentifier($entity::getTableName()) . " WHERE ";
        $query .= count($cols) > 1 ? '(' . implode(',', array_map([$this, 'escapeIdentifier'], $cols)) . ')' : $cols[0];
        $query .= ' IN (';
        $pKeys = [];
        foreach ($entities as $entity) {
            $pKey = array_map([$this, 'escapeValue'], $entity->getPrimaryKey());
            $pKeys[] = count($cols) > 1 ? '(' . implode(',', $pKey) . ')' : reset($pKey);
        }
        $query .= implode(',', $pKeys) . ')';

        $statement = $this->entityManager->getConnection()->query($query);
        $left = $entities;
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            foreach ($left as $k => $entity) {
                foreach ($primary as $var => $col) {
                    if ($entity->$var != $row[$col]) {
                        continue 2;
                    }
                }

                $this->entityManager->map($entity, true);
                $entity->setOriginalData($row);
                $entity->reset();
                unset($left[$k]);
                break;
            }
        }
    }

    /**
     * Normalize $type
     *
     * The type returned by mysql is for example VARCHAR(20) - this function converts it to varchar
     *
     * @param string $type
     * @return string
     */
    protected function normalizeType($type)
    {
        $type = strtolower($type);

        if (($pos = strpos($type, '(')) !== false && $pos > 0) {
            $type = substr($type, 0, $pos);
        }

        return trim($type);
    }

    /**
     * Extract content from parenthesis in $type
     *
     * @param string $type
     * @return string
     */
    protected function extractParenthesis($type)
    {
        if (preg_match('/\((.+)\)/', $type, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Escape a string for query
     *
     * @param string $value
     * @return string
     */
    protected function escapeString($value)
    {
        return $this->entityManager->getConnection()->quote($value);
    }

    /**
     * Escape an integer for query
     *
     * @param int $value
     * @return string
     */
    protected function escapeInteger($value)
    {
        return (string) $value;
    }

    /**
     * Escape a double for Query
     *
     * @param double $value
     * @return string
     */
    protected function escapeDouble($value)
    {
        return (string) $value;
    }

    /**
     * Escape NULL for query
     *
     * @return string
     */
    protected function escapeNULL()
    {
        return 'NULL';
    }

    /**
     * Escape a boolean for query
     *
     * @param bool $value
     * @return string
     */
    protected function escapeBoolean($value)
    {
        return ($value) ? $this->booleanTrue : $this->booleanFalse;
    }

    /**
     * Escape a date time object for query
     *
     * @param \DateTime $value
     * @return mixed
     */
    protected function escapeDateTime(\DateTime $value)
    {
        $value->setTimezone(new \DateTimeZone('UTC'));
        return $this->escapeString($value->format('Y-m-d\TH:i:s.u\Z'));
    }
}
