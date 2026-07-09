<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseList;
use Boostack\Models\Upload\Asset;

/**
 * Boostack: AssetList.php
 * ========================================================================
 * Typed collection wrapper for `Asset` records.
 *
 * This list is the standard Boostack entry point to query physical uploaded
 * files while preserving framework conventions for:
 * - filtering
 * - ordering
 * - pagination
 * - iteration
 * - typed hydration of `Asset` instances
 *
 * Use this collection when the primary concern is the stored binary file.
 * Use `AssetMetadataList` when the primary concern is the semantic or
 * processing versions associated with those files.
 */
class AssetList extends BaseList
{
    /**
     * Base model class managed by this list.
     */
    const BASE_CLASS = Asset::class;

    /**
     * Initialize the typed list and resolve the mapped table metadata.
     */
    public function __construct()
    {
        parent::init();
    }
}
