<?php

namespace Boostack\Models;

use Boostack\Models\Auth;
use Boostack\Models\BaseClassTraced;
use Boostack\Models\Config;
use Boostack\Models\Log\Log_Driver;
use Boostack\Models\Log\Log_Level;
use Boostack\Models\Log\Logger;
use Boostack\Models\Session\Session;

abstract class BaseClassTracedUser extends BaseClassTraced
{
    protected $created_by;
    protected $updated_by;

    /** @var array<string,array<string,bool>> */
    private static array $tableColumnPresenceCache = [];
    /** @var array<string,bool> */
    private static array $missingAuditColumnWarningCache = [];
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

        $isNew = empty($this->id);

        if ($this->hasColumn('created_by') && $isNew) {
            $this->created_by = $currentUserId;
        }

        if ($this->hasColumn('created_by') && !$isNew) {
            $persistedState = $this->captureDatabaseState((int)$this->id, true);
            if ($persistedState !== null && array_key_exists('created_by', $persistedState)) {
                $this->created_by = $persistedState['created_by'];
            }
        }

        if ($this->hasColumn('updated_by')) {
            $this->updated_by = $currentUserId;
        }
    }

    protected function syncUserAuditFieldExclusions(): void
    {
        foreach (['created_by', 'updated_by'] as $field) {
            $hasColumn = $this->hasColumn($field);
            $this->setCustomExcludedState($field, !$hasColumn);
            if (!$hasColumn) {
                $this->warnMissingAuditColumn($field);
            }
        }
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

    private function warnMissingAuditColumn(string $column): void
    {
        $tableName = static::TABLENAME;
        if ($tableName === '') {
            return;
        }

        $cacheKey = $tableName . '.' . $column;
        if (isset(self::$missingAuditColumnWarningCache[$cacheKey])) {
            return;
        }

        self::$missingAuditColumnWarningCache[$cacheKey] = true;
        Logger::write(
            "Schema mismatch: expected column `{$column}` not found on table `{$tableName}`. The field will be skipped until the related migration is applied.",
            Log_Level::WARNING,
            Log_Driver::FILE
        );
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

    protected function captureDatabaseState(?int $id = null, bool $includeDeleted = true): ?array
    {
        $targetId = $id ?? (isset($this->id) ? (int)$this->id : 0);
        if ($targetId <= 0 || static::TABLENAME === '') {
            return null;
        }

        $sql = "SELECT * FROM `" . static::TABLENAME . "` WHERE id = :id";
        if (!$includeDeleted && $this->hasSoftDelete()) {
            $sql .= " AND deleted_at IS NULL";
        }

        $stmt = $this->PDO->prepare($sql);
        $stmt->bindValue(':id', $targetId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || $row === []) {
            return null;
        }

        return $this->normalizeAuditSnapshot($row);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    protected function normalizeAuditSnapshot(array $snapshot): array
    {
        unset(
            $snapshot['id'],
            $snapshot['default_values'],
            $snapshot['system_excluded'],
            $snapshot['custom_excluded'],
            $snapshot['PDO'],
            $snapshot['soft_delete']
        );

        return $snapshot;
    }
}
