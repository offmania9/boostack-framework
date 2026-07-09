<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseList;

/**
 * Boostack: AssetMetadataList.php
 * ========================================================================
 * Typed collection wrapper for `AssetMetadata` records.
 *
 * This list is the standard Boostack entry point to query metadata versions
 * linked to uploaded assets while preserving framework conventions for:
 * - filtering
 * - ordering
 * - pagination
 * - iteration
 * - typed hydration of model instances
 */
class AssetMetadataList extends BaseList
{
    /**
     * Base model class managed by this list.
     */
    public const BASE_CLASS = AssetMetadata::class;

    /**
     * Initialize the typed list and resolve the mapped table metadata.
     */
    public function __construct()
    {
        parent::init();
    }
}
