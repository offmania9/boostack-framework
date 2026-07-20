<?php

namespace Boostack\Models\Upload;

use Boostack\Models\Config;
use Boostack\Models\BaseClassTraced;
use Boostack\Models\Log\Log_Driver;
use Boostack\Models\Log\Log_Level;
use Boostack\Models\Log\Logger;

/**
 * Boostack: AssetMetadata.php
 * ========================================================================
 * Semantic model for metadata versions linked to a physical uploaded asset.
 *
 * The goal of this class is to make explicit that an asset and its metadata are
 * different concerns:
 * - `Asset` identifies and stores the physical file
 * - `AssetMetadata` describes a contextual or processing version of that file
 *
 * Typical usages:
 * - attachment context attached to a form/module
 * - OCR/LLM document analysis runs
 * - batch enrichments or reprocessing passes
 * - version lineage across multiple semantic interpretations of the same file
 *
 * Semantic dimensions represented by the instance properties:
 * - identity/linkage: `id_asset`
 * - business context: `source`, `metadata_type`, `source_context`
 * - document classification: `document_type`, `document_direction`
 * - business outcome: `status`
 * - technical pipeline state: `process_status`
 * - extracted payloads: `raw_text`, `extracted_json`, `validated_json`,
 *   `prefill_json`, `checks_json`, `metadata_json`
 * - queue/worker state:
 *   - `processing_attempts`: number of technical executions already attempted
 *   - `next_retry_at`: next eligible retry time for deferred processing
 *   - `claimed_at`: timestamp of the current worker claim
 *   - `claimed_by`: logical worker identity that claimed the record
 * - audit actors:
 *   - `created_by`: creator of the metadata version row
 *   - `requested_by`: actor that requested processing/reprocessing
 *   - `processed_by`: actor or batch worker that executed processing
 * - lineage: `supersedes_metadata_id`
 *
 * Thanks to `BaseClassTraced`, each record also carries traced timestamps
 * (`created_at`, `last_update`, `last_access`) and supports soft delete.
 */
class AssetMetadata extends BaseClassTraced
{
    /**
     * Cache of real column presence for partially migrated schemas.
     *
     * @var array<string,array<string,bool>>
     */
    private static array $tableColumnPresenceCache = [];
    /**
     * Cache of schema mismatch warnings already emitted.
     *
     * @var array<string,bool>
     */
    private static array $missingColumnWarningCache = [];

    /**
     * Lazily resolved database name used for INFORMATION_SCHEMA checks.
     *
     * @var string|null
     */
    private ?string $resolvedDatabaseName = null;

    /**
     * Foreign key to `boostack_asset.id`.
     * It identifies the physical file this metadata version belongs to.
     *
     * @var int|null
     */
    protected $id_asset;

    /**
     * High-level origin of the metadata payload.
     * Examples: `manual_upload`, `document_import`, `batch_enrichment`.
     *
     * @var string
     */
    protected $source;

    /**
     * Functional category of the metadata version.
     * Examples: `attachment_context`, `document_analysis`.
     *
     * @var string
     */
    protected $metadata_type;

    /**
     * Canonical process name that produced this metadata version.
     * Useful to distinguish different pipelines over time.
     *
     * @var string|null
     */
    protected $process_name;

    /**
     * Optional version label of the producing process, prompt set or workflow.
     *
     * @var string|null
     */
    protected $process_version;

    /**
     * Application context in which the metadata was created.
     * Example: `order_received_new`, `offer_issued_edit`.
     *
     * @var string|null
     */
    protected $source_context;

    /**
     * Document family declared or inferred for the asset.
     * Example: `order`, `offer`, `invoice`.
     *
     * @var string|null
     */
    protected $document_type;

    /**
     * Business direction of the document.
     * Typical values: `issued`, `received`, `unknown`.
     *
     * @var string|null
     */
    protected $document_direction;

    /**
     * Business-level outcome of the metadata version.
     * This answers "how should the application interpret this version?".
     *
     * Typical values are enforced by schema:
     * `captured`, `processed`, `warning`, `error`, `superseded`.
     *
     * @var string
     */
    protected $status;

    /**
     * Technical step of the current processing run.
     * This answers "where is the pipeline right now?".
     *
     * Typical values:
     * `queued`, `processing`, `uploaded`, `extracting`, `text_extracted`,
     * `ocr_done`, `llm_done`, `validated`, `ready`, `retry`, `skipped`,
     * `error`.
     *
     * @var string|null
     */
    protected $process_status;

    /**
     * OCR method or engine used during text extraction.
     *
     * @var string|null
     */
    protected $ocr_method;

    /**
     * OCR language hint or detected language set.
     *
     * @var string|null
     */
    protected $ocr_language;

    /**
     * Raw text extracted from the original file before semantic validation.
     *
     * @var string|null
     */
    protected $raw_text;

    /**
     * Raw structured payload produced by the extraction stage.
     *
     * @var string|null
     */
    protected $extracted_json;

    /**
     * Structured payload after validation and normalization.
     *
     * @var string|null
     */
    protected $validated_json;

    /**
     * Application-ready prefill payload derived from validated data.
     *
     * @var string|null
     */
    protected $prefill_json;

    /**
     * Confidence score emitted by the processing pipeline.
     *
     * @var float|int|string|null
     */
    protected $confidence_score;

    /**
     * Human-readable summary of the semantic result.
     *
     * @var string|null
     */
    protected $summary;

    /**
     * Canonical JSON envelope describing the metadata version as a whole.
     * This is the best compact snapshot to preserve semantic context over time.
     *
     * @var string|null
     */
    protected $metadata_json;

    /**
     * Serialized checks, warnings and validation diagnostics.
     *
     * @var string|null
     */
    protected $checks_json;

    /**
     * Latest technical or business error message associated with the run.
     *
     * @var string|null
     */
    protected $error_message;

    /**
     * Timestamp when processing of this metadata version started.
     *
     * @var string|null
     */
    protected $process_started_at;

    /**
     * Timestamp when processing reached a terminal state.
     *
     * @var string|null
     */
    protected $process_completed_at;

    /**
     * Legacy compatibility pointer to `gds_document_import.id`.
     * It exists only to support transitional reads/writes during migration.
     *
     * @var int|null
     */
    protected $import_id;

    /**
     * Self-reference to the metadata version superseded by this one.
     * It enables lineage and reprocessing history for the same asset.
     *
     * @var int|null
     */
    protected $supersedes_metadata_id;

    /**
     * Creator of the metadata row itself.
     * This is the actor who instantiated the version record.
     *
     * @var int|null
     */
    protected $created_by;

    /**
     * Actor who requested the analysis, enrichment or reprocessing.
     * It may differ from `processed_by` in delegated or scheduled scenarios.
     *
     * @var int|null
     */
    protected $requested_by;

    /**
     * Actor or batch worker that actually executed the processing.
     * In synchronous interactive flows it often matches `requested_by`.
     *
     * @var int|null
     */
    protected $processed_by;

    /**
     * Number of processing executions already attempted for this version row.
     * It allows scheduled workers to cap retries without losing lineage.
     *
     * @var int
     */
    protected $processing_attempts;

    /**
     * Earliest timestamp at which the metadata row becomes eligible for retry.
     *
     * @var string|null
     */
    protected $next_retry_at;

    /**
     * Timestamp when a worker claimed this metadata row for processing.
     * It helps detect stale in-progress jobs in scheduled pipelines.
     *
     * @var string|null
     */
    protected $claimed_at;

    /**
     * Logical identifier of the worker that claimed the row.
     * Example: `cron:asset_metadata_enrichment`.
     *
     * @var string|null
     */
    protected $claimed_by;

    /**
     * Metadata versions are soft-deleted to preserve history and auditability.
     *
     * @var bool
     */
    protected $soft_delete = true;

    /**
     * Default semantic values used when a property is not explicitly populated.
     *
     * @var array<string,mixed>
     */
    protected $default_values = [
        'id_asset' => null,
        'source' => 'manual_upload',
        'metadata_type' => 'attachment_context',
        'process_name' => null,
        'process_version' => null,
        'source_context' => null,
        'document_type' => null,
        'document_direction' => null,
        'status' => 'captured',
        'process_status' => null,
        'ocr_method' => null,
        'ocr_language' => null,
        'raw_text' => null,
        'extracted_json' => null,
        'validated_json' => null,
        'prefill_json' => null,
        'confidence_score' => null,
        'summary' => null,
        'metadata_json' => null,
        'checks_json' => null,
        'error_message' => null,
        'process_started_at' => null,
        'process_completed_at' => null,
        'import_id' => null,
        'supersedes_metadata_id' => null,
        'created_by' => null,
        'requested_by' => null,
        'processed_by' => null,
        'processing_attempts' => 0,
        'next_retry_at' => null,
        'claimed_at' => null,
        'claimed_by' => null,
    ];

    /**
     * Physical table mapped by this semantic model.
     */
    public const TABLENAME = 'boostack_asset_metadata';

    /**
     * Initialize the semantic metadata record.
     *
     * If an identifier is provided, the existing version is loaded and its traced
     * fields are managed by `BaseClassTraced`. Without an identifier, the object
     * is prepared with the semantic defaults declared in `default_values`.
     *
     * @param int|null $id Metadata version identifier to load, if available.
     */
    public function __construct($id = null)
    {
        parent::init($id);
        $this->syncSchemaColumnExclusions();
    }

    public function fill($array)
    {
        $this->syncSchemaColumnExclusions();
        return parent::fill($array);
    }

    public function clearAndFill($array)
    {
        $this->syncSchemaColumnExclusions();
        return parent::clearAndFill($array);
    }

    public function save($forcedID = null)
    {
        $this->syncSchemaColumnExclusions();
        return parent::save($forcedID);
    }

    private function syncSchemaColumnExclusions(): void
    {
        foreach ($this->getSchemaAwareColumns() as $column) {
            $isPresent = $this->hasColumn($column);
            $fieldIndex = array_search($column, $this->custom_excluded, true);

            if (!$isPresent) {
                if ($fieldIndex === false) {
                    $this->custom_excluded[] = $column;
                }
                $this->warnMissingColumn($column);
                continue;
            }

            if ($fieldIndex !== false) {
                unset($this->custom_excluded[$fieldIndex]);
                $this->custom_excluded = array_values($this->custom_excluded);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function getSchemaAwareColumns(): array
    {
        return [
            'id_asset',
            'source',
            'metadata_type',
            'process_name',
            'process_version',
            'source_context',
            'document_type',
            'document_direction',
            'status',
            'process_status',
            'ocr_method',
            'ocr_language',
            'raw_text',
            'extracted_json',
            'validated_json',
            'prefill_json',
            'confidence_score',
            'summary',
            'metadata_json',
            'checks_json',
            'error_message',
            'process_started_at',
            'process_completed_at',
            'import_id',
            'supersedes_metadata_id',
            'created_by',
            'requested_by',
            'processed_by',
            'processing_attempts',
            'next_retry_at',
            'claimed_at',
            'claimed_by',
            'created_at',
            'last_update',
            'last_access',
            'deleted_at',
        ];
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
            return [];
        }

        $stmt = $this->PDO->prepare(
            'SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = :tableSchema
                AND TABLE_NAME = :tableName'
        );
        $stmt->execute([
            'tableSchema' => $databaseName,
            'tableName' => $tableName,
        ]);

        $presence = [];
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

        $this->resolvedDatabaseName = trim((string)Config::get('db_name'));
        return $this->resolvedDatabaseName;
    }

    private function warnMissingColumn(string $column): void
    {
        $tableName = static::TABLENAME;
        if ($tableName === '') {
            return;
        }

        $cacheKey = $tableName . '.' . $column;
        if (isset(self::$missingColumnWarningCache[$cacheKey])) {
            return;
        }

        self::$missingColumnWarningCache[$cacheKey] = true;
        Logger::write(
            "Schema mismatch: expected column `{$column}` not found on table `{$tableName}`. The field will be skipped until the related migration is applied.",
            Log_Level::WARNING,
            Log_Driver::FILE
        );
    }
}
