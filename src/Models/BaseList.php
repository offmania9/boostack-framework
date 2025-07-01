<?php

namespace Boostack\Models;

use Boostack\Models\Database\Database_PDO;
use Boostack\Models\Log\Log_Driver;
use Boostack\Models\Log\Log_Level;
use Boostack\Models\Log\Logger;

/**
 * Boostack: BaseList.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
abstract class BaseList implements \IteratorAggregate, \JsonSerializable
{

    protected $items;

    protected $PDO;

    protected $baseClassName;

    protected $baseClassTablename;

    /** List items object class */
    const BASE_CLASS = "";

    const ORDER_ASC = "ASC";

    const ORDER_DESC = "DESC";


    protected function init()
    {
        $this->PDO = Database_PDO::getInstance();
        $this->items = [];
        $this->baseClassName = static::BASE_CLASS;
        $this->baseClassTablename = (new $this->baseClassName)->getTablename();
    }

    /**
     * Returns an iterator for iterating through the list like an array.
     * @return \ArrayIterator
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Returns the items array.
     * @return mixed
     */
    public function getItemsArray()
    {
        return $this->items;
    }

    /**
     * Returns the size of the list.
     * @return int
     */
    public function size()
    {
        return count($this->items);
    }

    /**
     * Checks if the list is empty.
     * @return bool
     */
    public function isEmpty()
    {
        return count($this->items) == 0;
    }

    /**
     * Adds an element to the list.
     * @param $element
     */
    public function add($element): void
    {
        $this->items[] = $element;
    }
    /**
     * Clears the items array.
     */
    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * Converts the list items to an array.
     * @return mixed
     */
    public function toArray()
    {
        return $this->items;
    }

    /**
     * Exposes the items to the json_encode() function.
     */
    public function jsonSerialize(): mixed
    {
        return $this->items;
    }


    /**
     * Retrieves values with field filtering, ordering, grouping and pagination.
     * @param array|null $fields
     * @param string $orderColumn
     * @param string $orderType
     * @param int $numitem
     * @param int $currentPage
     * @param array $joins
     * @param array|string|null $groupBy
     * @return int
     * @throws \Exception
     */
    public function view(?array $fields, string $orderColumn = "", string $orderType = "ASC", int $numitem = 25, int $currentPage = 1, array $joins = [], array|string|null $groupBy = null): int
    {
        try {
            $sql = "";
            $orderType = strtoupper($orderType);
            $error = false;

            if (!is_numeric($numitem)) $error = "Wrong num_item type";
            if (!is_numeric($currentPage) || $currentPage < 0) $error = "Wrong current_page format";
            if (!($orderType == self::ORDER_ASC || $orderType == self::ORDER_DESC)) $error = "Wrong order_type format";
            if (!(is_array($fields) && count($fields) > 0)) $error = "Wrong field_view format";
            if ($error !== false) throw new \Exception($error);

            $joinClause = "";
            $tableAliases = [];
            foreach ($joins as $join) {
                if (isset($join['table'], $join['on'])) {
                    if (preg_match('/\s+AS\s+([a-zA-Z0-9_]+)$/i', $join['table'], $matches)) {
                        $alias = $matches[1];
                        $original = preg_replace('/\s+AS\s+[a-zA-Z0-9_]+$/i', '', $join['table']);
                        $tableAliases[$original][] = $alias;
                    } else {
                        $original = $join['table'];
                        $tableAliases[$original][] = $original;
                    }

                    $joinClause .= " JOIN " . $join['table'] . " ON " . $join['on'] . " ";
                }
            }

            $columns = $this->getColumnsFullName($this->baseClassTablename);
            $sqlMaster = "SELECT " . implode(", ", $columns) . " FROM " . $this->baseClassTablename . " " . $joinClause;

            $sqlCount = "SELECT COUNT(*) AS total FROM (SELECT {$this->baseClassTablename}.id FROM " . $this->baseClassTablename . " " . $joinClause;

            $sql .= "WHERE ";
            $separator = " AND ";
            $count = 0;

            foreach ($fields as $option) {
                $field = $option[0];

                if (!str_contains($field, '.')) {
                    $field = "{$this->baseClassTablename}." . $field;
                }

                if ($count > 0) $sql .= $separator;
                $op = strtoupper($option[1]);
                $val = $option[2];

                switch ($op) {
                    case '<>':
                    case '&LT;&GT;':
                        $sql .= ($val === null) ? "$field IS NOT NULL" : "$field != " . $this->PDO->quote($val);
                        break;
                    case 'LIKE':
                        $sql .= "$field LIKE " . $this->PDO->quote("%$val%");
                        break;
                    case '=':
                        $sql .= ($val === null) ? "$field IS NULL" : "$field = " . $this->PDO->quote($val);
                        break;
                    case '<':
                    case '&LT;':
                        $sql .= "$field < " . $this->PDO->quote($val);
                        break;
                    case '<=':
                    case '&LT;=':
                        $sql .= "$field <= " . $this->PDO->quote($val);
                        break;
                    case '>':
                    case '&GT;':
                        $sql .= "$field > " . $this->PDO->quote($val);
                        break;
                    case '>=':
                    case '&GT;=':
                        $sql .= "$field >= " . $this->PDO->quote($val);
                        break;
                }
                $count++;
            }

            if (!empty($groupBy)) {
                if (is_string($groupBy)) {
                    $groupBy = [$groupBy];
                }
                $groupByClean = array_map(fn($col) => str_contains($col, '.') ? $col : "{$this->baseClassTablename}.$col", $groupBy);
                $sql .= " GROUP BY " . implode(", ", $groupByClean);
            }

            $q = $this->PDO->prepare($sqlCount . $sql . ") AS subquery");
            $q->execute();
            $result = $q->fetch();
            $queryNumberResult = intval($result['total']);

            $maxPage = floor($queryNumberResult / $numitem) + 1;
            if ($currentPage > $maxPage) {
                $maxPage = floor($queryNumberResult / 25) + 1;
                $currentPage = 1;
            }

            if ($orderColumn != "") {
                $orderField = str_contains($orderColumn, '.') ? $orderColumn : "{$this->baseClassTablename}.$orderColumn";
                $sql .= " ORDER BY " . $orderField;
                if ($orderType != "") {
                    $sql .= " " . $orderType;
                }
            }

            if ($numitem !== null) {
                $lowerBound = ($currentPage - 1) * $numitem;
                $upperBound = $numitem;
                $sql .= " LIMIT " . $lowerBound . "," . $upperBound;
            }

            $q = $this->PDO->prepare($sqlMaster . $sql);
            $q->execute();
            $queryResults = $q->fetchAll(\PDO::FETCH_ASSOC);
            $this->fill($queryResults);

            return $queryNumberResult;
        } catch (\PDOException $PDOEx) {
            Logger::write($PDOEx->getMessage(), Log_Level::ERROR, Log_Driver::FILE);
            throw new \PDOException("Database Exception. Please see log file.");
        }
    }


    /**
     * Returns the sum of the specified columns, with optional filters, joins, and group by.
     * @param array $columns Columns to sum
     * @param array|null $fields Optional filters (same format as in view())
     * @param array $joins Optional join clauses (e.g., [['table' => 'other', 'on' => 'main.id = other.main_id']])
     * @param array|string|null $groupBy Optional group by column(s)
     * @return array
     * @throws \Exception
     */
    public function sum(array $columns, ?array $fields = null, array $joins = [], array|string|null $groupBy = null): array
    {
        try {
            if (empty($columns)) {
                throw new \Exception("No columns specified for sum.");
            }

            $validColumns = $this->getColumns($this->baseClassTablename);

            $joinClause = "";
            $tableAliases = [];
            foreach ($joins as $join) {
                if (isset($join['table'], $join['on'])) {
                    $joinClause .= " JOIN " . $join['table'] . " ON " . $join['on'] . " ";

                    if (preg_match('/\s+AS\s+([a-zA-Z0-9_]+)$/i', $join['table'], $matches)) {
                        $alias = $matches[1];
                        $original = preg_replace('/\s+AS\s+[a-zA-Z0-9_]+$/i', '', $join['table']);
                        $tableAliases[$original][] = $alias;
                    } else {
                        $original = $join['table'];
                        $tableAliases[$original][] = $original;
                    }
                }
            }

            $columnsToSum = [];
            foreach ($columns as $col) {
                if (str_contains($col, '.')) {
                    $aliasCol = $col;
                    $label = str_replace('.', '_', $col);
                } elseif (in_array($col, $validColumns)) {
                    $aliasCol = "{$this->baseClassTablename}.{$col}";
                    $label = $col;
                } else {
                    continue;
                }

                $columnsToSum[] = "SUM({$aliasCol}) AS `{$label}`";
            }

            if (empty($columnsToSum)) {
                throw new \Exception("No valid columns to sum.");
            }

            $sql = "SELECT " . implode(", ", $columnsToSum) . " FROM `{$this->baseClassTablename}`" . $joinClause;

            if (is_array($fields) && count($fields) > 0) {
                $sql .= " WHERE ";
                $count = 0;

                foreach ($fields as $option) {
                    $field = $option[0];
                    $operator = strtoupper($option[1]);
                    $value = $option[2];

                    if (!str_contains($field, '.')) {
                        $field = "{$this->baseClassTablename}.{$field}";
                    }

                    if ($count > 0) $sql .= " AND ";

                    switch ($operator) {
                        case '<>':
                        case '&LT;&GT;':
                            $sql .= ($value === null)
                                ? "$field IS NOT NULL"
                                : "$field != " . $this->PDO->quote($value);
                            break;
                        case '=':
                            $sql .= ($value === null)
                                ? "$field IS NULL"
                                : "$field = " . $this->PDO->quote($value);
                            break;
                        case 'LIKE':
                            $sql .= "$field LIKE " . $this->PDO->quote("%$value%");
                            break;
                        case '<':
                        case '&LT;':
                            $sql .= "$field < " . $this->PDO->quote($value);
                            break;
                        case '<=':
                        case '&LT;=':
                            $sql .= "$field <= " . $this->PDO->quote($value);
                            break;
                        case '>':
                        case '&GT;':
                            $sql .= "$field > " . $this->PDO->quote($value);
                            break;
                        case '>=':
                        case '&GT;=':
                            $sql .= "$field >= " . $this->PDO->quote($value);
                            break;
                        default:
                            throw new \Exception("Invalid operator '$operator' in filters.");
                    }

                    $count++;
                }
            }

            if (!empty($groupBy)) {
                if (is_string($groupBy)) {
                    $groupBy = [$groupBy];
                }

                $groupByClean = [];
                foreach ($groupBy as $col) {
                    $groupByClean[] = str_contains($col, '.') ? $col : "{$this->baseClassTablename}.{$col}";
                }

                $sql .= " GROUP BY " . implode(", ", $groupByClean);
            }

            $q = $this->PDO->prepare($sql);
            $q->execute();

            return $q->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $PDOEx) {
            Logger::write($PDOEx->getMessage(), Log_Level::ERROR, Log_Driver::FILE);
            throw new \PDOException("Database Exception. Please see log file.");
        }
    }


    /**
     * Checks if a key exists in the items array.
     * @param $key
     * @return bool
     */
    public function hasKey($key)
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Removes an item from the items array.
     * @param $key
     * @param bool $shift
     * @return bool
     */
    protected function remove($key, $shift = true)
    {
        if ($shift) {
            array_splice($this->items, $key, 1);
        } else {
            unset($this->items[$key]);
        }
        return true;
    }

    /**
     * Purge all items from the items array.
     * @param $key
     * @param bool $shift
     */
    public function purgeAllItems(): void
    {
        if (count($this->items) > 0) {
            foreach ($this->items as $item) {
                $item->purge();
            }
        }
    }

    /**
     * Retrieves an item from the items array.
     * @param $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->items[$key] ?? null;
    }

    /**
     * Fills the list with an array of object fields.
     * For example, with query results.
     * @param array $array
     */
    protected function fill($array)
    {
        foreach ($array as $elem) {
            $baseClassInstance = new $this->baseClassName;
            $baseClassInstance->fill($elem);
            $this->items[] = $baseClassInstance;
        }
    }

    /**
     * Loads all items from the database.
     * @param string|null $orderColumn
     * @param string|null $orderType
     * @return int
     * @throws \PDOException
     */
    public function loadAll($orderColumn = NULL, $orderType = NULL)
    {
        try {
            $ob = $orderColumn == NULL ? "" : " ORDER BY " . $orderColumn;
            $ot = $orderType == NULL ? "" : " " . $orderType;
            $sql = "SELECT * FROM " . $this->baseClassTablename . $ob . $ot;
            $q = $this->PDO->prepare($sql);
            $q->execute();
            $queryResults = $q->fetchAll(\PDO::FETCH_ASSOC);
            $this->fill($queryResults);
            return count($queryResults);
        } catch (\PDOException $PDOEx) {
            Logger::write($PDOEx->getMessage(), Log_Level::ERROR, Log_Driver::FILE);
            throw new \PDOException("Database \Exception. Please see log file.", $PDOEx->getCode(), $PDOEx);
        }
    }

    /**
     * Retrieves the columns of the table.
     * @param bool $withoutTraced
     * @return mixed
     * @throws \PDOException
     */
    public function getColumns($withoutTraced = false)
    {
        try {
            if ($withoutTraced) {
                $sql = "SELECT column_name FROM information_schema.columns 
            WHERE table_name = '" . $this->baseClassTablename . "' AND table_schema='" . Config::get("db_name") . "' 
AND column_name NOT IN ('created_at', 'last_update','last_access')";
            } else {
                $sql = "DESCRIBE " . $this->baseClassTablename;
            }
            $q = $this->PDO->prepare($sql);
            $q->execute();
            return $q->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $PDOEx) {
            Logger::write($PDOEx->getMessage(), Log_Level::ERROR, Log_Driver::FILE);
            throw new \PDOException("Database \Exception. Please see log file.", $PDOEx->getCode(), $PDOEx);
        }
    }

    /**
     * Retrieves the columns of the table.
     * @param bool $withoutTraced
     * @return mixed
     * @throws \PDOException
     */
    public function getColumnsFullName($withoutTraced = false)
    {
        try {
            $sql = "DESCRIBE " . $this->baseClassTablename;
            $tableName = $this->baseClassTablename;
            $q = $this->PDO->prepare($sql);
            $q->execute();
            $columns = $q->fetchAll(\PDO::FETCH_COLUMN);
            return array_map(function ($column) use ($tableName): string {
                return "$tableName.$column";
            }, $columns);
        } catch (\PDOException $PDOEx) {
            Logger::write($PDOEx->getMessage(), Log_Level::ERROR, Log_Driver::FILE);
            throw new \PDOException("Database \Exception. Please see log file.", $PDOEx->getCode(), $PDOEx);
        }
    }
}
