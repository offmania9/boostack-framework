<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseClassTraced;

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
     * `uploaded`, `extracting`, `ocr_done`, `llm_done`, `validated`, `ready`,
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
    }
}
