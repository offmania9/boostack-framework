<?php

namespace Boostack\Models\Database;

/**
 * Boostack: TableHandler.php
 * ========================================================================
 * Copyright 2014-2024 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
/**
 * Class TableHandler
 */
class TableHandler
{
    /** @var \PDO The \PDO object for database connection. */
    private $PDO;

    /** @var string The name of the table. */
    private $tableName;

    /** @var array The array of columns in the table. */
    private $columns = [];

    /** @var array The array of foreign keys in the table. */
    private $foreignKeys = [];

    /** @var array The array of indices in the table. */
    private $indices = [];

    /**
     * TableHandler constructor.
     */
    public function __construct()
    {
        $this->PDO = Database_PDO::getInstance();
    }

    /**
     * Set the name of the table.
     *
     * @param string $tableName The name of the table.
     */
    public function setTableName($tableName)
    {
        $this->tableName = $tableName;
    }

    /**
     * Add a column to the table.
     *
     * @param string $columnName The name of the column.
     * @param string $definition The definition of the column.
     */
    public function addColumn($columnName, $definition)
    {
        $this->columns[$columnName] = $definition;
    }

    /**
     * Add an index to a column.
     *
     * @param string $columnName The name of the column.
     * @param string $indexType The type of the index (e.g., 'INDEX', 'UNIQUE', 'PRIMARY KEY').
     */
    public function addIndex($columnName, $indexType = 'INDEX')
    {
        $this->indices[] = [
            'column' => $columnName,
            'type' => $indexType,
        ];
    }

    /**
     * Add a foreign key to the table.
     *
     * @param string $columnName The name of the column.
     * @param string $referencedTable The name of the referenced table.
     * @param string $referencedColumn The name of the referenced column.
     * @param string $onDelete The action on delete (default is 'CASCADE').
     * @param string $onUpdate The action on update (default is 'CASCADE').
     */
    public function addForeignKey($columnName, $referencedTable, $referencedColumn, $onDelete = 'CASCADE', $onUpdate = 'CASCADE')
    {
        $this->foreignKeys[] = [
            'column' => $columnName,
            'referenced_table' => $referencedTable,
            'referenced_column' => $referencedColumn,
            'on_delete' => $onDelete,
            'on_update' => $onUpdate,
        ];
    }

    /**
     * Create the table in the database.
     */
    public function createTable()
    {
        if (empty($this->tableName) || empty($this->columns)) {
            die("Table name and columns must be set before creating the table.");
        }

        $columnsString = '';
        foreach ($this->columns as $column => $definition) {
            $columnsString .= "`$column` $definition, ";
        }

        $foreignKeysString = '';
        foreach ($this->foreignKeys as $fk) {
            $foreignKeysString .= "FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['referenced_table']}`(`{$fk['referenced_column']}`) ON DELETE {$fk['on_delete']} ON UPDATE {$fk['on_update']}, ";
        }

        $indicesString = '';
        foreach ($this->indices as $index) {
            if ($index['type'] == 'PRIMARY KEY') {
                $indicesString .= "{$index['type']} (`{$index['column']}`), ";
            } else {
                $indicesString .= "{$index['type']} (`{$index['column']}`), ";
            }
        }

        $columnsString = rtrim($columnsString . $foreignKeysString . $indicesString, ', ');

        $sql = "CREATE TABLE IF NOT EXISTS `{$this->tableName}` ($columnsString)";

        try {
            $this->PDO->exec($sql);
            echo "Table '{$this->tableName}' created successfully.<br>";
        } catch (\PDOException $e) {
            die("Table creation failed: " . $e->getMessage());
        }
    }

    /**
     * Reset the table handler, clearing table name, columns, and foreign keys.
     */
    public function reset()
    {
        $this->tableName = null;
        $this->columns = [];
        $this->foreignKeys = [];
        $this->indices = [];
    }

    /**
     * Drop the specified table from the database.
     *
     * @param string $tableName The name of the table to drop.
     */
    public function dropTable($tableName)
    {
        $sql = "DROP TABLE IF EXISTS `$tableName`";
        try {
            $this->PDO->exec($sql);
            echo "Table '$tableName' dropped successfully.<br>";
        } catch (\PDOException $e) {
            die("Failed to drop table '$tableName': " . $e->getMessage());
        }
    }

    /**
     * Drop all tables from the database.
     */
    public function dropAllTables()
    {
        $sql = "SHOW TABLES";
        try {
            $stmt = $this->PDO->query($sql);
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $this->dropTable($table);
            }
            echo "All tables dropped successfully.<br>";
        } catch (\PDOException $e) {
            die("Failed to retrieve tables: " . $e->getMessage());
        }
    }

    /**
     * Generate and save the PHP class file for the table.
     *
     * @param string $namespace The namespace for the class (e.g., "My\Models\[NomeClasse]").
     * @param string $className The name of the class (e.g., "[NomeClasse]").
     */
    public function generateClassFile($namespace, $className)
    {
        $classContent = $this->generateClassContent($namespace, $className);
        $filePath = $_SERVER['DOCUMENT_ROOT'] . "/My/Models/$className.php";

        file_put_contents($filePath, $classContent);
        echo "Class file '$className.php' generated successfully.<br>";
    }

    /**
     * Generate the PHP class content based on the table structure.
     *
     * @param string $namespace The namespace for the class (e.g., "My\Models\[NomeClasse]").
     * @param string $className The name of the class (e.g., "[NomeClasse]").
     * @return string The PHP class content.
     */
    private function generateClassContent($namespace, $className)
    {
        $classContent = "<?php\n\n";
        $classContent .= "namespace $namespace;\n\n";

        // Check if the table contains specific columns
        $useBaseClassTraced = $this->tableContainsTracedColumns();

        if ($useBaseClassTraced) {
            $classContent .= "use Boostack\\Models\\BaseClassTraced;\n\n";
            $classContent .= "class $className extends BaseClassTraced\n";
        } else {
            $classContent .= "use Boostack\\Models\\BaseClass;\n\n";
            $classContent .= "class $className extends BaseClass\n";
        }

        $classContent .= "{\n";

        // Define properties and default values
        $classContent .= $this->generatePropertiesAndDefaults();

        // Define TABLENAME constant
        $classContent .= "\n    const TABLENAME = \"{$this->tableName}\";\n";

        // Constructor
        $classContent .= "    public function __construct(\$id = NULL)\n";
        $classContent .= "    {\n";
        $classContent .= "        parent::init(\$id);\n";
        $classContent .= "    }\n";

        $classContent .= "}\n";

        return $classContent;
    }

    /**
     * Check if the table contains all traced columns (created_at, last_update, last_access, deleted_at).
     *
     * @return bool True if all traced columns are present, false otherwise.
     */
    private function tableContainsTracedColumns()
    {
        $tracedColumns = ['created_at', 'last_update', 'last_access', 'deleted_at'];
        $columnNames = array_keys($this->columns);
        $missingColumns = array_diff($tracedColumns, $columnNames);
        return empty($missingColumns);
    }

    /**
     * Generate property declarations and default values for the PHP class,
     * excluding standard columns.
     *
     * @return string The property declarations and default values.
     */
    private function generatePropertiesAndDefaults()
    {
        $properties = "";

        foreach ($this->columns as $columnName => $definition) {
            // Exclude standard columns
            if (!in_array($columnName, ['id', 'created_at', 'last_update', 'last_access', 'deleted_at'])) {
                $properties .= "    protected \${$columnName};\n";
            }
        }

        $properties .= "\n    protected \$default_values = [\n";

        foreach ($this->columns as $columnName => $definition) {
            // Exclude standard columns
            if (!in_array($columnName, ['id', 'created_at', 'last_update', 'last_access', 'deleted_at'])) {
                $defaultValue = $this->getDefaultForColumn($columnName, $definition);
                $properties .= "        \"$columnName\" => $defaultValue,\n";
            }
        }
        $properties .= "    ];\n";
        return $properties;
    }

    /**
     * Get the default value for a column based on its definition.
     *
     * @param string $columnName The name of the column.
     * @param string $columnDefinition The definition of the column from the database.
     * @return string The PHP representation of the default value for the column.
     */
    private function getDefaultForColumn($columnName, $columnDefinition)
    {
        // Extract the default value from the column definition
        if (preg_match("/DEFAULT\s+([^\s,]+)/i", $columnDefinition, $matches)) {
            $defaultValue = $matches[1];

            // Handle CURRENT_TIMESTAMP() and other expressions without quotes
            if (stripos($defaultValue, 'CURRENT_TIMESTAMP') !== false) {
                return "'$defaultValue'";
            }

            // Check if the default value is a string and needs quotes
            if (!is_numeric($defaultValue) && strtoupper($defaultValue) !== 'NULL') {
                return "'$defaultValue'";
            }

            return $defaultValue;
        }

        // If no default value is found, determine based on data type and NULL allowance
        $nullAllowed = (stripos($columnDefinition, 'NULL') !== false && stripos($columnDefinition, 'NOT NULL') === false);

        if ($nullAllowed) {
            return 'NULL';
        }

        // Match different column types and assign default values accordingly
        if (preg_match('/int|bool|float|decimal|double|real/i', $columnDefinition)) {
            return 0;
        } elseif (preg_match('/char|varchar|text|blob|binary|enum|set/i', $columnDefinition)) {
            // Specific handling for SET type
            if (preg_match('/set/i', $columnDefinition)) {
                // Extract possible values from the SET definition
                if (preg_match("/set\s*\((.*?)\)/i", $columnDefinition, $setMatches)) {
                    $setValues = explode(',', $setMatches[1]);
                    // Trim and remove quotes from each value
                    $setValues = array_map(function ($value) {
                        return trim($value, " '\"");
                    }, $setValues);
                    // Return the first value as the default
                    return "'" . $setValues[0] . "'";
                }
            }
            return "''";
        } elseif (preg_match('/date|time|year|timestamp|datetime/i', $columnDefinition)) {
            // Use a common default value for date/time types
            return "'0000-00-00 00:00:00'";
        } else {
            return 'NULL';
        }
    }


    /**
     * Generate and save the PHP class file for the table based on database information.
     *
     * @param string $className The name of the class (e.g., "[NomeClasse]").
     * @param string $namespace The namespace for the class (default is "My\Models").
     */
    public function generateClassFileFromDatabase($className, $namespace = "My\Models")
    {
        // Fetch columns from the database table
        $columns = $this->fetchTableColumns();

        // Set table name and columns in the TableHandler instance
        $this->setTableName($this->tableName); // Ensure table name is set
        //$this->columns = $columns;

        // Generate and save the PHP class file
        $classContent = $this->generateClassContent($namespace, $className);
        $filePath = $this->getClassFilePath($namespace, $className);

        file_put_contents($filePath, $classContent);
        echo "Class file '{$className}.php' generated successfully.<br>";
    }

    /**
     * Generate and save the PHP list class file for the table based on database information.
     *
     * @param string $className The name of the main class (e.g., "[NomeClasse]").
     * @param string $namespace The namespace for the list class (default is "My\Models").
     */
    public function generateListClassFile($className, $namespace = "My\Models")
    {
        $listClassName = $className . "List";
        $classContent = $this->generateListClassContent($namespace, $className, $listClassName);
        $filePath = $this->getClassFilePath($namespace, $listClassName);

        file_put_contents($filePath, $classContent);
        echo "List class file '{$listClassName}.php' generated successfully.<br>";
    }

    /**
     * Generate the full path to the PHP class file based on namespace and class name.
     *
     * @param string $namespace The namespace for the class (e.g., "My\Models").
     * @param string $className The name of the class (e.g., "[NomeClasse]").
     * @return string The full path to the PHP class file.
     */
    private function getClassFilePath($namespace, $className)
    {
        $basePath = $_SERVER['DOCUMENT_ROOT'] . "/";
        $classPath = str_replace('\\', '/', $namespace); // Convert namespace to directory path
        return $basePath . $classPath . "/{$className}.php";
    }

    /**
     * Fetch columns from the current table in the database.
     *
     * @return array Associative array of column names and their definitions.
     */
    private function fetchTableColumns()
    {
        $sql = "SHOW COLUMNS FROM `{$this->tableName}`";
        try {
            $stmt = $this->PDO->query($sql);
            $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $tableColumns = [];
            foreach ($columns as $column) {
                $columnName = $column['Field'];
                $columnType = $column['Type'];
                // Exclude standard columns if needed (id, created_at, etc.)
                if (!in_array($columnName, ['id'])) { //, 'created_at', 'last_update', 'last_access', 'deleted_at'
                    $tableColumns[$columnName] = $columnType;
                }
            }

            return $tableColumns;
        } catch (\PDOException $e) {
            die("Failed to fetch table columns: " . $e->getMessage());
        }
    }

    /**
     * Generate the PHP content for the list class based on the table structure.
     *
     * @param string $namespace The namespace for the list class (e.g., "My\Models\[NomeClasse]").
     * @param string $className The name of the main class (e.g., "[NomeClasse]").
     * @param string $listClassName The name of the list class (e.g., "[NomeClasse]List").
     * @return string The PHP class content for the list class.
     */

    private function generateListClassContent($namespace, $className, $listClassName)
    {
        $classContent = "<?php\n\n";
        $classContent .= "namespace $namespace;\n\n";
        $classContent .= "use Boostack\\Models\\BaseList;\n";
        $classContent .= "use $namespace\\$className;\n\n";
        $classContent .= "class $listClassName extends BaseList\n";
        $classContent .= "{\n";
        $classContent .= "    const BASE_CLASS = $className::class;\n\n";
        $classContent .= "    public function __construct()\n";
        $classContent .= "    {\n";
        $classContent .= "        parent::init();\n";
        $classContent .= "    }\n";
        $classContent .= "}\n";

        return $classContent;
    }
}
