<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseClassTraced;
use Boostack\Models\Upload\Upload_File;

/**
 * Boostack: Asset.php
 * ========================================================================
 * Semantic model for a physical uploaded file stored by the framework.
 *
 * `Asset` represents the file identity and storage layer, not the semantic
 * interpretation of the file contents. In the current domain model:
 * - `Asset` answers "what file was stored, where, and by whom?"
 * - `AssetMetadata` answers "what does that file mean in a business or
 *   processing context?"
 *
 * Typical responsibilities of this model:
 * - bind a file to an application object via `object_name` / `object_type`
 * - preserve the storage path and technical file characteristics
 * - preserve upload provenance and uploader audit information
 * - act as the canonical anchor for one or more metadata versions
 *
 * Thanks to `BaseClassTraced`, each asset also carries traced timestamps
 * (`created_at`, `last_update`, `last_access`) and can participate in the
 * standard Boostack lifecycle semantics.
 */
class Asset extends BaseClassTraced
{
    /**
     * Concrete contextual identifier of the object the asset is attached to.
     * Example: `order_received_123`, `offer_to_issue_new`.
     *
     * This is usually more specific than `object_type`.
     *
     * @var string|null
     */
    protected $object_name;

    /**
     * Stable category of the owning object or domain aggregate.
     * Example: `gds_order_received`, `gds_offer_to_issue`.
     *
     * This is usually more stable and reusable for reporting/grouping than
     * `object_name`.
     *
     * @var string|null
     */
    protected $object_type;

    /**
     * Original temporary or user-facing filename associated with the upload.
     *
     * @var string
     */
    protected $temp_name;

    /**
     * Stored filename used in persistent storage.
     *
     * @var string
     */
    protected $filename;

    /**
     * Absolute or resolved path of the stored file.
     *
     * @var string
     */
    protected $filepath;

    /**
     * MIME type reported or inferred for the file.
     *
     * @var string
     */
    protected $type;

    /**
     * File size in bytes.
     *
     * @var int
     */
    protected $size;

    /**
     * File extension without business interpretation.
     * Example: `pdf`, `jpg`, `png`.
     *
     * @var string
     */
    protected $extension;

    /**
     * SHA-256 hash of the stored file contents.
     *
     * Useful for deduplication, idempotency, integrity checks and semantic
     * lineage across reprocessing workflows.
     *
     * @var string|null
     */
    protected $file_hash_sha256;

    /**
     * High-level origin of the upload action.
     * Examples: `manual_upload`, `document_import`, `batch_import`.
     *
     * @var string|null
     */
    protected $upload_source;

    /**
     * Business/application context in which the upload happened.
     * Example: `order_received_new`, `offer_issued_edit`.
     *
     * @var string|null
     */
    protected $source_context;

    /**
     * User who uploaded the physical file into storage.
     *
     * This field identifies the uploader of the binary asset itself.
     * It should not be confused with metadata processing actors such as
     * `requested_by` or `processed_by` in `AssetMetadata`.
     *
     * @var int|null
     */
    protected $created_by;

    /**
     * Default semantic values used when a property is not explicitly populated.
     *
     * @var array<string,mixed>
     */
    protected $default_values = [
        "object_name" => NULL,
        "object_type" => NULL,
        "temp_name" => '',
        "filename" => '',
        "filepath" => '',
        "type" => '',
        "size" => 0,
        "extension" => '',
        "file_hash_sha256" => NULL,
        "upload_source" => NULL,
        "source_context" => NULL,
        "created_by" => NULL,
    ];

    /**
     * Physical table mapped by this semantic model.
     */
    const TABLENAME = "boostack_asset";

    /**
     * Initialize the physical asset record.
     *
     * If an identifier is provided, the stored asset is loaded from the
     * database. Otherwise the object is prepared with the semantic defaults
     * declared in `default_values`.
     *
     * @param int|null $id Asset identifier to load, if available.
     */
    public function __construct($id = NULL)
    {
        parent::init($id);
    }

    /**
     * Populate the file-related technical fields from an upload abstraction.
     *
     * This method intentionally fills only the intrinsic file characteristics
     * carried by `Upload_File`:
     * - original name
     * - MIME type
     * - size
     * - extension
     *
     * It does not fill contextual fields such as `object_name`,
     * `object_type`, `upload_source` or `created_by`, because those belong to
     * the surrounding application workflow rather than to the file payload.
     *
     * @param Upload_File $uploadFile Upload wrapper containing validated file
     *                                information.
     */
    public function loadFromFile(Upload_File $uploadFile): void
    {
        $this->temp_name = $uploadFile->name;
        $this->filename = $uploadFile->name;
        $this->type = $uploadFile->type;
        $this->size = $uploadFile->size;
        $this->extension = $uploadFile->extension;
    }
}
