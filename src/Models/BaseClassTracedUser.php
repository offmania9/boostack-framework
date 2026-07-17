<?php

namespace Boostack\Models;

use Boostack\Models\Auth;
use Boostack\Models\BaseClassTraced;
use Boostack\Models\Config;
use Boostack\Models\Session\Session;

abstract class BaseClassTracedUser extends BaseClassTraced
{
    protected $created_by;
    protected $updated_by;

    /** @var array<string,array<string,bool>> */
    private static array $tableColumnPresenceCache = [];
    private ?string $resolvedDatabaseName = null;

    protected function init($id = null)
    {
        $this->default_values['created_by'] = null;
        $this->default_values['updated_by'] = null;
        parent::init($id);
        $this->syncUserAuditFieldExclusions();
    }

    public function fill($array)
    {
        $this->syncUserAuditFieldExclusions();
        return parent::fill($array);
    }

    public function clearAndFill($array)
    {
        $this->syncUserAuditFieldExclusions();
        return parent::clearAndFill($array);
    }

    public function save($forcedID = null)
    {
        $this->syncUserAuditFieldExclusions();
        $this->applyUserAuditFieldsBeforeSave();
        return parent::save($forcedID);
    }

    protected function applyUserAuditFieldsBeforeSave(): void
    {
        $currentUserId = $this->resolveCurrentUserId();
        if ($currentUserId === null) {
            return;
        }

        if ($this->hasColumn('created_by') && empty($this->id) && (int)($this->created_by ?? 0) <= 0) {
            $this->created_by = $currentUserId;
        }

        if ($this->hasColumn('updated_by') && (int)($this->updated_by ?? 0) <= 0) {
            $this->updated_by = $currentUserId;
            return;
        }

        if ($this->hasColumn('updated_by') && !empty($this->id)) {
            $this->updated_by = $currentUserId;
        }
    }

    protected function syncUserAuditFieldExclusions(): void
    {
        $this->setCustomExcludedState('created_by', !$this->hasColumn('created_by'));
        $this->setCustomExcludedState('updated_by', !$this->hasColumn('updated_by'));
    }

    private function setCustomExcludedState(string $field, bool $excluded): void
    {
        $fieldIndex = array_search($field, $this->custom_excluded, true);
        if ($excluded) {
            if ($fieldIndex === false) {
                $this->custom_excluded[] = $field;
            }
            return;
        }

        if ($fieldIndex !== false) {
            unset($this->custom_excluded[$fieldIndex]);
            $this->custom_excluded = array_values($this->custom_excluded);
        }
    }

    private function hasColumn(string $column): bool
    {
        $tableName = static::TABLENAME;
        if ($tableName === '') {
            return false;
        }

        if (!isset(self::$tableColumnPresenceCache[$tableName])) {
            self::$tableColumnPresenceCache[$tableName] = $this->loadTableColumnPresence($tableName);
        }

        return self::$tableColumnPresenceCache[$tableName][$column] ?? false;
    }

    /**
     * @return array<string,bool>
     */
    private function loadTableColumnPresence(string $tableName): array
    {
        $databaseName = $this->resolveDatabaseNameForSchemaChecks();
        if ($databaseName === '') {
            return [
                'created_by' => false,
                'updated_by' => false,
            ];
        }

        $stmt = $this->PDO->prepare(
            'SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = :tableSchema
                AND TABLE_NAME = :tableName
                AND COLUMN_NAME IN (\'created_by\', \'updated_by\')'
        );
        $stmt->execute([
            'tableSchema' => $databaseName,
            'tableName' => $tableName,
        ]);

        $presence = [
            'created_by' => false,
            'updated_by' => false,
        ];

        foreach ((array)$stmt->fetchAll(\PDO::FETCH_COLUMN) as $columnName) {
            $presence[(string)$columnName] = true;
        }

        return $presence;
    }

    private function resolveDatabaseNameForSchemaChecks(): string
    {
        if ($this->resolvedDatabaseName !== null) {
            return $this->resolvedDatabaseName;
        }

        try {
            $databaseName = $this->PDO->query('SELECT DATABASE()')->fetchColumn();
            if (is_string($databaseName) && trim($databaseName) !== '') {
                $this->resolvedDatabaseName = trim($databaseName);
                return $this->resolvedDatabaseName;
            }
        } catch (\Throwable) {
            // Fallback below.
        }

        $configuredDatabase = trim((string)Config::get('db_name'));
        $this->resolvedDatabaseName = $configuredDatabase;

        return $this->resolvedDatabaseName;
    }

    protected function resolveCurrentUserId(): ?int
    {
        $user = Auth::getUserLoggedObject();
        if (is_object($user) && isset($user->id)) {
            return (int)$user->id;
        }

        $sessionUserId = Session::getUserID();
        if (is_numeric($sessionUserId) && (int)$sessionUserId > 0) {
            return (int)$sessionUserId;
        }

        return null;
    }
}
