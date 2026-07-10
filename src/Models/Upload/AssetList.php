<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseList;
use Boostack\Models\Config;
use PDO;

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
     * Hard limit used by the DAM page to prevent oversized payloads.
     */
    private const MAX_PAGE_SIZE = 200;

    /**
     * Extensions commonly treated as image previews in the DAM UI.
     *
     * @var array<int,string>
     */
    private const IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'bmp',
        'webp',
        'svg',
        'tif',
        'tiff',
    ];

    /**
     * Extensions commonly treated as documents in the DAM UI.
     *
     * @var array<int,string>
     */
    private const DOCUMENT_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'csv',
        'tsv',
        'txt',
        'rtf',
        'odt',
        'ods',
        'xml',
        'json',
        'html',
        'htm',
        'md',
    ];

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

    /**
     * Search assets for the internal DAM page using asset fields plus the
     * latest linked metadata snapshot for each asset.
     *
     * The query intentionally keeps the list payload compact:
     * - no long JSON blobs in the list view
     * - only the latest metadata version is joined
     * - all filters are executed server-side with pagination
     *
     * @param array<string,mixed> $filters
     * @return array{page:int,pageSize:int,total:int,rows:array<int,array<string,mixed>>}
     */
    public function searchDamAssets(
        array $filters,
        int $page = 1,
        int $pageSize = 50,
        string $sort = 'created_at',
        string $direction = self::ORDER_DESC
    ): array {
        $page = max(1, $page);
        $pageSize = min(max(1, $pageSize), self::MAX_PAGE_SIZE);
        $offset = ($page - 1) * $pageSize;
        $requiredFileValidity = $this->resolveFileValidityFilter($filters);

        if ($requiredFileValidity !== null) {
            $allRows = $this->fetchDamSearchRows($this->stripFileValidityFilters($filters), $sort, $direction, null, null);
            $filteredRows = $this->filterRowsByFileValidity($allRows, $requiredFileValidity);
            $total = count($filteredRows);

            return [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'rows' => array_slice($filteredRows, $offset, $pageSize),
            ];
        }

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $this->countDamAssets($filters),
            'rows' => $this->fetchDamSearchRows($filters, $sort, $direction, $offset, $pageSize),
        ];
    }

    /**
     * Count assets matching the DAM filters.
     *
     * @param array<string,mixed> $filters
     */
    public function countDamAssets(array $filters): int
    {
        $requiredFileValidity = $this->resolveFileValidityFilter($filters);
        if ($requiredFileValidity !== null) {
            return count(
                $this->filterRowsByFileValidity(
                    $this->fetchDamSearchRows($this->stripFileValidityFilters($filters), 'created_at', self::ORDER_DESC, null, null),
                    $requiredFileValidity
                )
            );
        }

        $parts = $this->buildDamQueryParts($filters);
        $sql = "SELECT COUNT(DISTINCT a.id) AS total {$parts['from']} {$parts['where_sql']}";
        $row = $this->fetchOneAssoc($sql, $parts['params']);
        return (int)($row['total'] ?? 0);
    }

    /**
     * Build lightweight faceted counts for the DAM sidebar and quick tabs.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function getDamFacetCounts(array $filters): array
    {
        $baseFilters = $filters;
        unset($baseFilters['tab']);

        return [
            'tabs' => [
                'all' => $this->countDamAssets($baseFilters),
                'images' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'images'])),
                'documents' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'documents'])),
                'accounting' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'accounting'])),
                'metadata_ready' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'metadata_ready'])),
                'errors' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'errors'])),
                'duplicates' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'duplicates'])),
                'missing_files' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'missing_files'])),
                'missing_object_id' => $this->countDamAssets(array_merge($baseFilters, ['tab' => 'missing_object_id'])),
            ],
            'extension' => $this->fetchDamFacet($filters, "LOWER(COALESCE(a.extension, ''))", 25),
            'mime_type' => $this->fetchDamFacet($filters, "LOWER(COALESCE(a.type, ''))", 25),
            'upload_source' => $this->fetchDamFacet($filters, "LOWER(COALESCE(a.upload_source, ''))", 20),
            'source_context' => $this->fetchDamFacet($filters, "LOWER(COALESCE(a.source_context, ''))", 20),
            'object_type' => $this->fetchDamFacet($filters, "LOWER(COALESCE(a.object_type, ''))", 25),
            'metadata_source' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.source, ''))", 20),
            'metadata_type' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.metadata_type, ''))", 20),
            'process_name' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.process_name, ''))", 20),
            'process_version' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.process_version, ''))", 20),
            'document_type' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.document_type, ''))", 20),
            'document_direction' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.document_direction, ''))", 20),
            'metadata_status' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.status, ''))", 20),
            'process_status' => $this->fetchDamFacet($filters, "LOWER(COALESCE(lm.process_status, ''))", 20),
        ];
    }

    /**
     * Load typed asset entities by identifiers while preserving the input order.
     *
     * @param array<int,int|string> $ids
     * @return array<int,Asset>
     */
    public function getAssetsByIds(array $ids, bool $includeDeleted = true): array
    {
        $normalizedIds = $this->normalizeIntegerArray($ids);
        if ($normalizedIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $sql = "SELECT * FROM boostack_asset WHERE id IN ({$placeholders})";
        if (!$includeDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }
        $sql .= " ORDER BY FIELD(id, " . implode(', ', array_fill(0, count($normalizedIds), '?')) . ")";

        $params = [];
        foreach ($normalizedIds as $id) {
            $params[] = [$id, PDO::PARAM_INT];
        }
        foreach ($normalizedIds as $id) {
            $params[] = [$id, PDO::PARAM_INT];
        }

        $rows = $this->fetchAllAssoc($sql, $params);
        $assets = [];
        foreach ($rows as $row) {
            $asset = new Asset();
            $asset->fill($row);
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * Retrieve one DAM asset row enriched with user and latest-metadata data.
     *
     * @return array<string,mixed>|null
     */
    public function getDamAssetById(int $assetId, bool $includeDeleted = true): ?array
    {
        if ($assetId <= 0) {
            return null;
        }

        $filters = ['id' => $assetId];
        if ($includeDeleted) {
            $filters['deleted_mode'] = 'include';
        }

        $result = $this->searchDamAssets($filters, 1, 1, 'id', self::ORDER_ASC);
        return $result['rows'][0] ?? null;
    }

    /**
     * Estimate size and cardinality for an explicit or server-side filtered
     * selection without loading long metadata payloads.
     *
     * @param array<string,mixed> $filters
     * @param array<int,int|string> $ids
     * @return array{count:int,estimated_size:int}
     */
    public function getDamSelectionSummary(array $filters = [], array $ids = [], bool $allFiltered = false): array
    {
        if (!$allFiltered) {
            $normalizedIds = $this->normalizeIntegerArray($ids);
            if ($normalizedIds === []) {
                return ['count' => 0, 'estimated_size' => 0];
            }

            $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
            $sql = "
                SELECT COUNT(*) AS total_count, COALESCE(SUM(size), 0) AS total_size
                FROM boostack_asset
                WHERE id IN ({$placeholders})
            ";

            $params = [];
            foreach ($normalizedIds as $id) {
                $params[] = [$id, PDO::PARAM_INT];
            }

            $row = $this->fetchOneAssoc($sql, $params);
            return [
                'count' => (int)($row['total_count'] ?? 0),
                'estimated_size' => (int)($row['total_size'] ?? 0),
            ];
        }

        $requiredFileValidity = $this->resolveFileValidityFilter($filters);
        if ($requiredFileValidity !== null) {
            $rows = $this->filterRowsByFileValidity(
                $this->getDamAssetsForDownload($this->stripFileValidityFilters($filters), [], true, null),
                $requiredFileValidity
            );

            return [
                'count' => count($rows),
                'estimated_size' => array_sum(array_map(static fn(array $row): int => (int)($row['size'] ?? 0), $rows)),
            ];
        }

        $parts = $this->buildDamQueryParts($filters);
        $sql = "
            SELECT COUNT(*) AS total_count, COALESCE(SUM(filtered_assets.size), 0) AS total_size
            FROM (
                SELECT DISTINCT a.id, a.size
                {$parts['from']}
                {$parts['where_sql']}
            ) filtered_assets
        ";
        $row = $this->fetchOneAssoc($sql, $parts['params']);

        return [
            'count' => (int)($row['total_count'] ?? 0),
            'estimated_size' => (int)($row['total_size'] ?? 0),
        ];
    }

    /**
     * Resolve asset rows for ZIP creation using either explicit ids or the
     * current server-side filtered result set.
     *
     * @param array<string,mixed> $filters
     * @param array<int,int|string> $ids
     * @return array<int,array<string,mixed>>
     */
    public function getDamAssetsForDownload(
        array $filters = [],
        array $ids = [],
        bool $allFiltered = false,
        ?int $limit = null
    ): array {
        if (!$allFiltered) {
            $normalizedIds = $this->normalizeIntegerArray($ids);
            if ($normalizedIds === []) {
                return [];
            }

            $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
            $sql = "
                SELECT
                    id,
                    temp_name,
                    filename,
                    filepath,
                    type,
                    size,
                    extension,
                    file_hash_sha256,
                    created_at,
                    last_update
                FROM boostack_asset
                WHERE id IN ({$placeholders})
                ORDER BY FIELD(id, " . implode(', ', array_fill(0, count($normalizedIds), '?')) . ")
            ";

            $params = [];
            foreach ($normalizedIds as $id) {
                $params[] = [$id, PDO::PARAM_INT];
            }
            foreach ($normalizedIds as $id) {
                $params[] = [$id, PDO::PARAM_INT];
            }

            return $this->fetchAllAssoc($sql, $params);
        }

        $requiredFileValidity = $this->resolveFileValidityFilter($filters);
        if ($requiredFileValidity !== null) {
            $rows = $this->filterRowsByFileValidity(
                $this->getDamAssetsForDownload($this->stripFileValidityFilters($filters), [], true, null),
                $requiredFileValidity
            );
            if ($limit !== null && $limit > 0) {
                $rows = array_slice($rows, 0, $limit);
            }
            return $rows;
        }

        $parts = $this->buildDamQueryParts($filters);
        $sql = "
            SELECT
                filtered_assets.id,
                filtered_assets.temp_name,
                filtered_assets.filename,
                filtered_assets.filepath,
                filtered_assets.type,
                filtered_assets.size,
                filtered_assets.extension,
                filtered_assets.file_hash_sha256,
                filtered_assets.created_at,
                filtered_assets.last_update
            FROM (
                SELECT DISTINCT
                    a.id,
                    a.temp_name,
                    a.filename,
                    a.filepath,
                    a.type,
                    a.size,
                    a.extension,
                    a.file_hash_sha256,
                    a.created_at,
                    a.last_update
                {$parts['from']}
                {$parts['where_sql']}
            ) filtered_assets
            ORDER BY filtered_assets.created_at ASC, filtered_assets.id ASC
        ";

        if ($limit !== null && $limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }

        return $this->fetchAllAssoc($sql, $parts['params']);
    }

    /**
     * Build the SQL fragments shared by search/count/facet DAM queries.
     *
     * @param array<string,mixed> $filters
     * @return array{from:string,where_sql:string,params:array<int,array{0:mixed,1:int}>}
     */
    private function buildDamQueryParts(array $filters): array
    {
        $params = [];
        $where = [];
        $objectIdSql = "CAST(SUBSTRING_INDEX(a.object_name, '_', -1) AS UNSIGNED)";
        $from = "
            FROM boostack_asset a
            LEFT JOIN (
                SELECT latest.*
                FROM boostack_asset_metadata latest
                INNER JOIN (
                    SELECT id_asset, MAX(id) AS max_id
                    FROM boostack_asset_metadata
                    WHERE deleted_at IS NULL
                    GROUP BY id_asset
                ) grouped ON grouped.max_id = latest.id
            ) lm ON lm.id_asset = a.id
            LEFT JOIN boostack_user au ON au.id = a.created_by
            LEFT JOIN boostack_user ru ON ru.id = lm.requested_by
            LEFT JOIN boostack_user pu ON pu.id = lm.processed_by
            LEFT JOIN (
                SELECT file_hash_sha256, COUNT(*) AS duplicate_count
                FROM boostack_asset
                WHERE deleted_at IS NULL
                  AND file_hash_sha256 IS NOT NULL
                  AND TRIM(file_hash_sha256) <> ''
                GROUP BY file_hash_sha256
                HAVING COUNT(*) > 1
            ) dup ON dup.file_hash_sha256 = a.file_hash_sha256
            LEFT JOIN gds_customer cust_direct
                ON a.object_type IN ('gds_customer', 'customer')
               AND cust_direct.id = {$objectIdSql}
               AND cust_direct.deleted_at IS NULL
            LEFT JOIN gds_project_first prj
                ON a.object_type IN ('gds_project_first', 'project_first')
               AND prj.id = {$objectIdSql}
               AND prj.deleted_at IS NULL
            LEFT JOIN gds_customer cust_prj
                ON cust_prj.id = prj.customer_id
               AND cust_prj.deleted_at IS NULL
            LEFT JOIN gds_project_sub ps
                ON a.object_type IN ('gds_project_sub', 'project_sub')
               AND ps.id = {$objectIdSql}
               AND ps.deleted_at IS NULL
            LEFT JOIN gds_project_first ps_prj
                ON ps_prj.id = ps.project_id
               AND ps_prj.deleted_at IS NULL
            LEFT JOIN gds_customer cust_ps
                ON cust_ps.id = ps_prj.customer_id
               AND cust_ps.deleted_at IS NULL
            LEFT JOIN gds_order_received ord_r
                ON a.object_type IN ('gds_order_received', 'order_received')
               AND ord_r.id = {$objectIdSql}
               AND ord_r.deleted_at IS NULL
            LEFT JOIN gds_project_first ord_r_prj
                ON ord_r_prj.id = ord_r.id_project
               AND ord_r_prj.deleted_at IS NULL
            LEFT JOIN gds_customer cust_ord_r
                ON cust_ord_r.id = ord_r.id_customer
               AND cust_ord_r.deleted_at IS NULL
            LEFT JOIN gds_offer_to_issue ord_r_offer
                ON ord_r_offer.id = ord_r.id_offer
               AND ord_r_offer.deleted_at IS NULL
            LEFT JOIN gds_order_to_issue ord_t
                ON a.object_type IN ('gds_order_to_issue', 'order_to_issue')
               AND ord_t.id = {$objectIdSql}
               AND ord_t.deleted_at IS NULL
            LEFT JOIN gds_project_first ord_t_prj
                ON ord_t_prj.id = ord_t.id_project
               AND ord_t_prj.deleted_at IS NULL
            LEFT JOIN gds_offer_received ord_t_offer
                ON ord_t_offer.id = ord_t.id_offer
               AND ord_t_offer.deleted_at IS NULL
            LEFT JOIN gds_offer_to_issue offer_i
                ON a.object_type IN ('gds_offer_to_issue', 'offer_to_issue')
               AND offer_i.id = {$objectIdSql}
               AND offer_i.deleted_at IS NULL
            LEFT JOIN gds_customer cust_offer_i
                ON cust_offer_i.id = offer_i.id_customer
               AND cust_offer_i.deleted_at IS NULL
            LEFT JOIN gds_offer_received offer_r
                ON a.object_type IN ('gds_offer_received', 'offer_received')
               AND offer_r.id = {$objectIdSql}
               AND offer_r.deleted_at IS NULL
            LEFT JOIN gds_invoice_received inv_r
                ON a.object_type IN ('gds_invoice_received', 'invoice_received', 'other_expenses')
               AND inv_r.id = {$objectIdSql}
               AND inv_r.deleted_at IS NULL
            LEFT JOIN gds_order_to_issue inv_r_ord
                ON inv_r_ord.id = inv_r.id_order_to_issue
               AND inv_r_ord.deleted_at IS NULL
            LEFT JOIN gds_offer_received inv_r_offer
                ON inv_r_offer.id = inv_r_ord.id_offer
               AND inv_r_offer.deleted_at IS NULL
            LEFT JOIN gds_invoice_to_issue inv_t
                ON a.object_type IN ('gds_invoice_to_issue', 'invoice_to_issue')
               AND inv_t.id = {$objectIdSql}
               AND inv_t.deleted_at IS NULL
            LEFT JOIN gds_order_received inv_t_ord
                ON inv_t_ord.id = inv_t.id_order_received
               AND inv_t_ord.deleted_at IS NULL
            LEFT JOIN gds_offer_to_issue inv_t_offer
                ON inv_t_offer.id = inv_t_ord.id_offer
               AND inv_t_offer.deleted_at IS NULL
            LEFT JOIN gds_project_sub inv_t_ps
                ON inv_t_ps.id = inv_t.id_project_sub
               AND inv_t_ps.deleted_at IS NULL
            LEFT JOIN gds_project_first inv_t_prj
                ON inv_t_prj.id = inv_t_ps.project_id
               AND inv_t_prj.deleted_at IS NULL
            LEFT JOIN gds_customer cust_inv_t
                ON cust_inv_t.id = inv_t_prj.customer_id
               AND cust_inv_t.deleted_at IS NULL
        ";

        $deletedMode = strtolower(trim((string)($filters['deleted_mode'] ?? ($filters['include_deleted'] ?? 'exclude'))));
        if ($deletedMode === 'only') {
            $where[] = "a.deleted_at IS NOT NULL";
        } elseif ($deletedMode !== 'include' && $deletedMode !== '1' && $deletedMode !== 'true') {
            $where[] = "a.deleted_at IS NULL";
        }

        if (!empty($filters['id'])) {
            $where[] = "a.id = ?";
            $params[] = [(int)$filters['id'], PDO::PARAM_INT];
        }

        $search = trim((string)($filters['q'] ?? ''));
        if ($search !== '') {
            $searchLike = '%' . strtolower($search) . '%';
            $searchFields = [
                "LOWER(COALESCE(a.temp_name, ''))",
                "LOWER(COALESCE(a.filename, ''))",
                "LOWER(COALESCE(a.filepath, ''))",
                "LOWER(COALESCE(a.object_name, ''))",
                "LOWER(COALESCE(a.object_type, ''))",
                "LOWER(COALESCE(a.extension, ''))",
                "LOWER(COALESCE(a.type, ''))",
                "LOWER(COALESCE(a.upload_source, ''))",
                "LOWER(COALESCE(a.source_context, ''))",
                "LOWER(COALESCE(lm.source, ''))",
                "LOWER(COALESCE(lm.metadata_type, ''))",
                "LOWER(COALESCE(lm.process_name, ''))",
                "LOWER(COALESCE(lm.process_version, ''))",
                "LOWER(COALESCE(lm.document_type, ''))",
                "LOWER(COALESCE(lm.document_direction, ''))",
                "LOWER(COALESCE(lm.status, ''))",
                "LOWER(COALESCE(lm.process_status, ''))",
                "LOWER(COALESCE(lm.summary, ''))",
                "LOWER(COALESCE(lm.metadata_json, ''))",
                "LOWER(COALESCE(lm.checks_json, ''))",
                "LOWER(COALESCE(lm.extracted_json, ''))",
                "LOWER(COALESCE(lm.validated_json, ''))",
                "LOWER(COALESCE(lm.prefill_json, ''))",
                "LOWER(COALESCE(cust_direct.name, ''))",
                "LOWER(COALESCE(cust_prj.name, ''))",
                "LOWER(COALESCE(cust_ps.name, ''))",
                "LOWER(COALESCE(cust_ord_r.name, ''))",
                "LOWER(COALESCE(cust_inv_t.name, ''))",
                "LOWER(COALESCE(cust_offer_i.name, ''))",
                "LOWER(COALESCE(prj.code, ''))",
                "LOWER(COALESCE(prj.name, ''))",
                "LOWER(COALESCE(ps_prj.code, ''))",
                "LOWER(COALESCE(ps_prj.name, ''))",
                "LOWER(COALESCE(ord_r.number, ''))",
                "LOWER(COALESCE(ord_t.number, ''))",
                "LOWER(COALESCE(offer_i.protocol, ''))",
                "LOWER(COALESCE(offer_i.name, ''))",
                "LOWER(COALESCE(offer_r.protocol, ''))",
                "LOWER(COALESCE(offer_r.name, ''))",
                "LOWER(COALESCE(inv_r.number, ''))",
                "LOWER(COALESCE(inv_t.number, ''))",
                "LOWER(COALESCE(au.name, ''))",
                "LOWER(COALESCE(au.email, ''))",
            ];

            $searchSql = [];
            foreach ($searchFields as $expression) {
                $searchSql[] = $expression . " LIKE ?";
                $params[] = [$searchLike, PDO::PARAM_STR];
            }
            $where[] = '(' . implode(' OR ', $searchSql) . ')';
        }

        $this->addInCondition($where, $params, "LOWER(COALESCE(a.extension, ''))", $filters['extension'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(a.type, ''))", $filters['mime_type'] ?? ($filters['type'] ?? null));
        $this->addNumericRangeCondition($where, $params, 'a.size', $filters['size_min'] ?? null, $filters['size_max'] ?? null);
        $this->addDateRangeCondition($where, $params, 'a.created_at', $filters['created_from'] ?? null, $filters['created_to'] ?? null);
        $this->addDateRangeCondition($where, $params, 'a.last_update', $filters['updated_from'] ?? null, $filters['updated_to'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(a.upload_source, ''))", $filters['upload_source'] ?? null);
        $this->addLikeCondition($where, $params, "LOWER(COALESCE(a.source_context, ''))", $filters['source_context'] ?? null);
        $this->addLikeCondition($where, $params, "LOWER(COALESCE(a.object_name, ''))", $filters['object_name'] ?? null);
        $this->addLikeCondition($where, $params, "LOWER(COALESCE(a.object_type, ''))", $filters['object_type'] ?? null);

        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.source, ''))", $filters['metadata_source'] ?? ($filters['source'] ?? null));
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.metadata_type, ''))", $filters['metadata_type'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.process_name, ''))", $filters['process_name'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.process_version, ''))", $filters['process_version'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.document_type, ''))", $filters['document_type'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.document_direction, ''))", $filters['document_direction'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.status, ''))", $filters['metadata_status'] ?? ($filters['status'] ?? null));
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.process_status, ''))", $filters['process_status'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.ocr_method, ''))", $filters['ocr_method'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE(lm.ocr_language, ''))", $filters['ocr_language'] ?? null);
        $this->addNumericRangeCondition($where, $params, 'lm.confidence_score', $filters['confidence_min'] ?? null, $filters['confidence_max'] ?? null);
        $this->addNumericRangeCondition($where, $params, 'lm.processing_attempts', $filters['attempts_min'] ?? null, $filters['attempts_max'] ?? null);

        if ($this->normalizeNullableInt($filters['import_id'] ?? null) !== null) {
            $where[] = 'lm.import_id = ?';
            $params[] = [(int)$filters['import_id'], PDO::PARAM_INT];
        }

        $this->addUserFilterCondition($where, $params, 'a.created_by', 'au', $filters['created_by'] ?? null);
        $this->addUserFilterCondition($where, $params, 'lm.requested_by', 'ru', $filters['requested_by'] ?? null);
        $this->addUserFilterCondition($where, $params, 'lm.processed_by', 'pu', $filters['processed_by'] ?? null);

        $hasHash = $filters['has_hash'] ?? null;
        if ($hasHash !== null && $hasHash !== '') {
            $truthy = $this->toBoolean($hasHash);
            $where[] = $truthy
                ? "(a.file_hash_sha256 IS NOT NULL AND TRIM(a.file_hash_sha256) <> '')"
                : "(a.file_hash_sha256 IS NULL OR TRIM(a.file_hash_sha256) = '')";
        }

        if ($this->toBoolean($filters['duplicates_only'] ?? false)) {
            $where[] = 'COALESCE(dup.duplicate_count, 0) > 1';
        }

        if ($this->toBoolean($filters['has_error'] ?? false)) {
            $where[] = "(LOWER(COALESCE(lm.process_status, '')) = 'error' OR LOWER(COALESCE(lm.status, '')) = 'error' OR COALESCE(NULLIF(TRIM(lm.error_message), ''), NULL) IS NOT NULL)";
        }
        if ($this->toBoolean($filters['has_raw_text'] ?? false)) {
            $where[] = "COALESCE(NULLIF(TRIM(lm.raw_text), ''), NULL) IS NOT NULL";
        }
        if ($this->toBoolean($filters['has_extracted_json'] ?? false)) {
            $where[] = "COALESCE(NULLIF(TRIM(lm.extracted_json), ''), NULL) IS NOT NULL";
        }
        if ($this->toBoolean($filters['has_validated_json'] ?? false)) {
            $where[] = "COALESCE(NULLIF(TRIM(lm.validated_json), ''), NULL) IS NOT NULL";
        }
        if ($this->toBoolean($filters['has_prefill_json'] ?? false)) {
            $where[] = "COALESCE(NULLIF(TRIM(lm.prefill_json), ''), NULL) IS NOT NULL";
        }

        $this->addBusinessTextCondition($where, $params, $filters['business_customer'] ?? null, [
            "LOWER(COALESCE(cust_direct.name, ''))",
            "LOWER(COALESCE(cust_direct.code, ''))",
            "LOWER(COALESCE(cust_direct.vat_number, ''))",
            "LOWER(COALESCE(cust_direct.tax_code, ''))",
            "LOWER(COALESCE(cust_prj.name, ''))",
            "LOWER(COALESCE(cust_ps.name, ''))",
            "LOWER(COALESCE(cust_ord_r.name, ''))",
            "LOWER(COALESCE(cust_inv_t.name, ''))",
            "LOWER(COALESCE(cust_offer_i.name, ''))",
            "LOWER(COALESCE(lm.metadata_json, ''))",
            "LOWER(COALESCE(lm.validated_json, ''))",
            "LOWER(COALESCE(lm.extracted_json, ''))",
            "LOWER(COALESCE(lm.prefill_json, ''))",
        ]);
        $this->addBusinessTextCondition($where, $params, $filters['business_project'] ?? null, [
            "LOWER(COALESCE(prj.code, ''))",
            "LOWER(COALESCE(prj.name, ''))",
            "LOWER(COALESCE(ps.code, ''))",
            "LOWER(COALESCE(ps.name, ''))",
            "LOWER(COALESCE(ps_prj.code, ''))",
            "LOWER(COALESCE(ps_prj.name, ''))",
            "LOWER(COALESCE(ord_r_prj.code, ''))",
            "LOWER(COALESCE(ord_r_prj.name, ''))",
            "LOWER(COALESCE(ord_t_prj.code, ''))",
            "LOWER(COALESCE(ord_t_prj.name, ''))",
            "LOWER(COALESCE(inv_t_prj.code, ''))",
            "LOWER(COALESCE(inv_t_prj.name, ''))",
            "LOWER(COALESCE(a.object_name, ''))",
            "LOWER(COALESCE(a.source_context, ''))",
            "LOWER(COALESCE(lm.metadata_json, ''))",
            "LOWER(COALESCE(lm.validated_json, ''))",
        ]);
        $this->addBusinessTextCondition($where, $params, $filters['business_offer'] ?? null, [
            "LOWER(COALESCE(offer_i.protocol, ''))",
            "LOWER(COALESCE(offer_i.name, ''))",
            "LOWER(COALESCE(offer_i.subject, ''))",
            "LOWER(COALESCE(offer_r.protocol, ''))",
            "LOWER(COALESCE(offer_r.name, ''))",
            "LOWER(COALESCE(offer_r.subject, ''))",
            "LOWER(COALESCE(ord_r_offer.protocol, ''))",
            "LOWER(COALESCE(ord_t_offer.protocol, ''))",
            "LOWER(COALESCE(inv_t_offer.protocol, ''))",
            "LOWER(COALESCE(inv_r_offer.protocol, ''))",
            "LOWER(COALESCE(lm.metadata_json, ''))",
            "LOWER(COALESCE(lm.validated_json, ''))",
        ]);
        $this->addBusinessTextCondition($where, $params, $filters['business_order'] ?? null, [
            "LOWER(COALESCE(ord_r.number, ''))",
            "LOWER(COALESCE(ord_r.subject, ''))",
            "LOWER(COALESCE(ord_t.number, ''))",
            "LOWER(COALESCE(ord_t.subject, ''))",
            "LOWER(COALESCE(inv_t_ord.number, ''))",
            "LOWER(COALESCE(inv_r_ord.number, ''))",
            "LOWER(COALESCE(lm.metadata_json, ''))",
            "LOWER(COALESCE(lm.validated_json, ''))",
        ]);
        $this->addBusinessTextCondition($where, $params, $filters['business_invoice'] ?? null, [
            "LOWER(COALESCE(inv_r.number, ''))",
            "LOWER(COALESCE(inv_t.number, ''))",
            "LOWER(COALESCE(lm.metadata_json, ''))",
            "LOWER(COALESCE(lm.validated_json, ''))",
        ]);

        $businessYear = trim((string)($filters['business_year'] ?? ''));
        if ($businessYear !== '') {
            if (preg_match('/^\d{4}$/', $businessYear)) {
                $year = (int)$businessYear;
                $yearChecks = [
                    'prj.year',
                    'ps_prj.year',
                    'ord_r.year',
                    'ord_t.year',
                    'offer_i.year',
                    'offer_r.year',
                    'inv_r.year',
                    'inv_t.year',
                    'YEAR(a.created_at)',
                    'YEAR(COALESCE(lm.process_completed_at, lm.last_update, lm.created_at))',
                ];
                $yearWhere = [];
                foreach ($yearChecks as $check) {
                    $yearWhere[] = $check . ' = ?';
                    $params[] = [$year, PDO::PARAM_INT];
                }
                $yearWhere[] = "LOWER(COALESCE(lm.metadata_json, '')) LIKE ?";
                $params[] = ['%"year":' . $businessYear . '%', PDO::PARAM_STR];
                $yearWhere[] = "LOWER(COALESCE(lm.validated_json, '')) LIKE ?";
                $params[] = ['%"year":' . $businessYear . '%', PDO::PARAM_STR];
                $where[] = '(' . implode(' OR ', $yearWhere) . ')';
            } else {
                $this->addBusinessTextCondition($where, $params, $businessYear, [
                    "LOWER(COALESCE(lm.metadata_json, ''))",
                    "LOWER(COALESCE(lm.validated_json, ''))",
                    "LOWER(COALESCE(lm.extracted_json, ''))",
                ]);
            }
        }

        $businessImportBatch = trim((string)($filters['business_import_batch'] ?? ''));
        if ($businessImportBatch !== '') {
            if (ctype_digit($businessImportBatch)) {
                $where[] = '(lm.import_id = ? OR LOWER(COALESCE(lm.metadata_json, \'\')) LIKE ?)';
                $params[] = [(int)$businessImportBatch, PDO::PARAM_INT];
                $params[] = ['%' . strtolower($businessImportBatch) . '%', PDO::PARAM_STR];
            } else {
                $this->addBusinessTextCondition($where, $params, $businessImportBatch, [
                    "LOWER(COALESCE(lm.metadata_json, ''))",
                    "LOWER(COALESCE(lm.validated_json, ''))",
                    "LOWER(COALESCE(lm.extracted_json, ''))",
                    "LOWER(COALESCE(lm.prefill_json, ''))",
                    "LOWER(COALESCE(a.source_context, ''))",
                ]);
            }
        }

        $hasObjectId = $filters['has_object_id'] ?? null;
        if ($hasObjectId !== null && $hasObjectId !== '') {
            $this->appendObjectIdCondition($where, $this->toBoolean($hasObjectId));
        }

        $tab = strtolower(trim((string)($filters['tab'] ?? '')));
        switch ($tab) {
            case 'images':
                $this->addInCondition($where, $params, "LOWER(COALESCE(a.extension, ''))", self::IMAGE_EXTENSIONS);
                break;
            case 'documents':
                $this->addInCondition($where, $params, "LOWER(COALESCE(a.extension, ''))", self::DOCUMENT_EXTENSIONS);
                break;
            case 'accounting':
                $where[] = "(
                    LOWER(COALESCE(lm.document_type, '')) = 'invoice'
                    OR a.object_type IN ('gds_invoice_received', 'invoice_received', 'gds_invoice_to_issue', 'invoice_to_issue')
                    OR LOWER(COALESCE(a.object_name, '')) LIKE '%invoice%'
                    OR LOWER(COALESCE(a.source_context, '')) LIKE '%invoice%'
                )";
                break;
            case 'metadata_ready':
                $where[] = "LOWER(COALESCE(lm.process_status, '')) IN ('validated', 'ready')";
                break;
            case 'errors':
                $where[] = "(LOWER(COALESCE(lm.process_status, '')) = 'error' OR LOWER(COALESCE(lm.status, '')) = 'error' OR COALESCE(NULLIF(TRIM(lm.error_message), ''), NULL) IS NOT NULL)";
                break;
            case 'duplicates':
                $where[] = 'COALESCE(dup.duplicate_count, 0) > 1';
                break;
            case 'missing_files':
                break;
            case 'missing_object_id':
                $this->appendObjectIdCondition($where, false);
                break;
        }

        return [
            'from' => $from,
            'where_sql' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchDamSearchRows(
        array $filters,
        string $sort,
        string $direction,
        ?int $offset,
        ?int $limit
    ): array {
        $orderColumn = $this->resolveDamSortColumn($sort);
        $orderDirection = strtoupper($direction) === self::ORDER_ASC ? self::ORDER_ASC : self::ORDER_DESC;
        $parts = $this->buildDamQueryParts($filters);

        $sql = "
            SELECT
                a.id,
                a.object_name,
                a.object_type,
                a.temp_name,
                a.filename,
                a.filepath,
                a.type,
                a.size,
                a.extension,
                a.file_hash_sha256,
                a.upload_source,
                a.source_context,
                a.created_by,
                a.created_at,
                a.last_update,
                a.last_access,
                a.deleted_at,
                COALESCE(NULLIF(TRIM(a.temp_name), ''), NULLIF(TRIM(a.filename), ''), CONCAT('asset_', a.id)) AS display_name,
                COALESCE(NULLIF(TRIM(au.name), ''), NULLIF(TRIM(au.email), ''), CASE WHEN a.created_by IS NULL THEN '' ELSE CONCAT('User #', a.created_by) END) AS created_by_label,
                COALESCE(dup.duplicate_count, 0) AS duplicate_count,
                lm.id AS latest_metadata_id,
                lm.source AS metadata_source,
                lm.metadata_type,
                lm.process_name,
                lm.process_version,
                lm.document_type,
                lm.document_direction,
                lm.status AS metadata_status,
                lm.process_status,
                lm.ocr_method,
                lm.ocr_language,
                lm.confidence_score,
                lm.summary,
                lm.error_message,
                lm.import_id,
                lm.requested_by,
                lm.processed_by,
                lm.processing_attempts,
                lm.next_retry_at,
                lm.claimed_at,
                lm.claimed_by,
                lm.process_started_at,
                lm.process_completed_at,
                lm.last_update AS metadata_last_update,
                COALESCE(NULLIF(TRIM(ru.name), ''), NULLIF(TRIM(ru.email), ''), CASE WHEN lm.requested_by IS NULL THEN '' ELSE CONCAT('User #', lm.requested_by) END) AS requested_by_label,
                COALESCE(NULLIF(TRIM(pu.name), ''), NULLIF(TRIM(pu.email), ''), CASE WHEN lm.processed_by IS NULL THEN '' ELSE CONCAT('User #', lm.processed_by) END) AS processed_by_label,
                COALESCE(NULLIF(TRIM(cust_direct.name), ''), NULLIF(TRIM(cust_prj.name), ''), NULLIF(TRIM(cust_ps.name), ''), NULLIF(TRIM(cust_ord_r.name), ''), NULLIF(TRIM(cust_inv_t.name), ''), NULLIF(TRIM(cust_offer_i.name), '')) AS business_customer_name,
                COALESCE(NULLIF(TRIM(prj.code), ''), NULLIF(TRIM(ps_prj.code), ''), NULLIF(TRIM(ord_r_prj.code), ''), NULLIF(TRIM(ord_t_prj.code), ''), NULLIF(TRIM(inv_t_prj.code), '')) AS business_project_code,
                COALESCE(NULLIF(TRIM(prj.name), ''), NULLIF(TRIM(ps_prj.name), ''), NULLIF(TRIM(ord_r_prj.name), ''), NULLIF(TRIM(ord_t_prj.name), ''), NULLIF(TRIM(inv_t_prj.name), '')) AS business_project_name,
                COALESCE(NULLIF(TRIM(offer_i.protocol), ''), NULLIF(TRIM(offer_i.name), ''), NULLIF(TRIM(offer_r.protocol), ''), NULLIF(TRIM(offer_r.name), ''), NULLIF(TRIM(ord_r_offer.protocol), ''), NULLIF(TRIM(ord_t_offer.protocol), ''), NULLIF(TRIM(inv_t_offer.protocol), ''), NULLIF(TRIM(inv_r_offer.protocol), '')) AS business_offer_label,
                COALESCE(NULLIF(TRIM(ord_r.number), ''), NULLIF(TRIM(ord_t.number), ''), NULLIF(TRIM(inv_t_ord.number), ''), NULLIF(TRIM(inv_r_ord.number), '')) AS business_order_label,
                COALESCE(NULLIF(TRIM(inv_r.number), ''), NULLIF(TRIM(inv_t.number), '')) AS business_invoice_label,
                COALESCE(prj.year, ps_prj.year, ord_r.year, ord_t.year, offer_i.year, offer_r.year, inv_r.year, inv_t.year) AS business_year
            {$parts['from']}
            {$parts['where_sql']}
            ORDER BY {$orderColumn} {$orderDirection}, a.id {$orderDirection}
        ";

        $params = $parts['params'];
        if ($offset !== null && $limit !== null) {
            $sql .= " LIMIT ?, ?";
            $params[] = [$offset, PDO::PARAM_INT];
            $params[] = [$limit, PDO::PARAM_INT];
        }

        return $this->fetchAllAssoc($sql, $params);
    }

    private function resolveFileValidityFilter(array $filters): ?bool
    {
        $explicit = $filters['has_valid_file'] ?? null;
        if ($explicit !== null && $explicit !== '') {
            return $this->toBoolean($explicit);
        }

        $tab = strtolower(trim((string)($filters['tab'] ?? '')));
        if ($tab === 'missing_files') {
            return false;
        }

        return null;
    }

    /**
     * @param array<int,string> $where
     */
    private function appendObjectIdCondition(array &$where, bool $required): void
    {
        $expression = "TRIM(COALESCE(a.object_name, '')) REGEXP '(^|_)[0-9]+$'";
        $where[] = $required ? $expression : 'NOT (' . $expression . ')';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function filterRowsByFileValidity(array $rows, bool $requiredValidity): array
    {
        $filtered = [];
        foreach ($rows as $row) {
            $row['has_valid_file'] = $this->hasValidAssetFilePath((string)($row['filepath'] ?? '')) ? 1 : 0;
            if ((bool)$row['has_valid_file'] === $requiredValidity) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string,mixed>
     */
    private function stripFileValidityFilters(array $filters): array
    {
        unset($filters['has_valid_file']);
        if (strtolower(trim((string)($filters['tab'] ?? ''))) === 'missing_files') {
            unset($filters['tab']);
        }

        return $filters;
    }

    private function hasValidAssetFilePath(string $rawPath): bool
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 6);
        $allowedRoots = [];
        $configuredUploadPath = trim((string)Config::get('upload_documents_path'));
        if ($configuredUploadPath !== '') {
            $configuredRoot = realpath($configuredUploadPath);
            if ($configuredRoot !== false) {
                $allowedRoots[] = rtrim($configuredRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }

        $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
        if ($uploadsRoot !== false) {
            $allowedRoots[] = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        $allowedRoots = array_values(array_unique($allowedRoots));
        if ($allowedRoots === []) {
            return false;
        }

        foreach ($this->buildAssetPathCandidates($rawPath, $projectRoot, $allowedRoots) as $candidatePath) {
            $realPath = realpath($candidatePath);
            if ($realPath === false || !is_file($realPath)) {
                continue;
            }

            foreach ($allowedRoots as $allowedRoot) {
                if ($realPath === rtrim($allowedRoot, DIRECTORY_SEPARATOR) || str_starts_with($realPath, $allowedRoot)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $allowedRoots
     * @return array<int,string>
     */
    private function buildAssetPathCandidates(string $rawPath, string $projectRoot, array $allowedRoots): array
    {
        $normalizedRawPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rawPath);
        $projectRoot = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $projectRoot), DIRECTORY_SEPARATOR);
        $candidates = [$normalizedRawPath];

        $uploadsSuffix = $this->extractUploadsSuffix($normalizedRawPath);
        if ($uploadsSuffix !== null) {
            $candidates[] = $projectRoot . $uploadsSuffix;
            foreach ($allowedRoots as $allowedRoot) {
                $allowedRoot = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $allowedRoot), DIRECTORY_SEPARATOR);
                if (str_ends_with($allowedRoot, DIRECTORY_SEPARATOR . 'uploads')) {
                    $candidates[] = $allowedRoot . substr($uploadsSuffix, strlen(DIRECTORY_SEPARATOR . 'uploads'));
                    continue;
                }
                if (str_contains($allowedRoot, DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR)) {
                    $prefix = strstr($allowedRoot, DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR, true);
                    if ($prefix !== false && $prefix !== '') {
                        $candidates[] = rtrim($prefix, DIRECTORY_SEPARATOR) . $uploadsSuffix;
                    }
                }
            }
        }

        $basename = basename($normalizedRawPath);
        if ($basename !== '') {
            foreach ($allowedRoots as $allowedRoot) {
                $candidates[] = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $allowedRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
            }
        }

        $deduplicated = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $deduplicated[$candidate] = $candidate;
            }
        }

        return array_values($deduplicated);
    }

    private function extractUploadsSuffix(string $path): ?string
    {
        $needle = DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        $position = strpos($path, $needle);
        if ($position === false) {
            return null;
        }

        return substr($path, $position);
    }

    /**
     * Query one grouped facet using the shared DAM filters.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array{value:string,count:int}>
     */
    private function fetchDamFacet(array $filters, string $valueExpression, int $limit = 20): array
    {
        $requiredFileValidity = $this->resolveFileValidityFilter($filters);
        if ($requiredFileValidity !== null) {
            $rows = $this->filterRowsByFileValidity(
                $this->fetchDamSearchRows($this->stripFileValidityFilters($filters), 'created_at', self::ORDER_DESC, null, null),
                $requiredFileValidity
            );

            $result = [];
            foreach ($rows as $row) {
                $facetValue = $this->resolveFacetValueFromRow($row, $valueExpression);
                if ($facetValue === '') {
                    continue;
                }
                $result[$facetValue] = ($result[$facetValue] ?? 0) + 1;
            }

            arsort($result);
            $final = [];
            foreach (array_slice($result, 0, $limit, true) as $value => $count) {
                $final[] = [
                    'value' => $value,
                    'count' => (int)$count,
                ];
            }

            return $final;
        }

        $parts = $this->buildDamQueryParts($filters);
        $sql = "
            SELECT {$valueExpression} AS facet_value, COUNT(DISTINCT a.id) AS facet_count
            {$parts['from']}
            {$parts['where_sql']}
            GROUP BY facet_value
            HAVING TRIM(COALESCE(facet_value, '')) <> ''
            ORDER BY facet_count DESC, facet_value ASC
            LIMIT " . (int)$limit;
        $rows = $this->fetchAllAssoc($sql, $parts['params']);

        $result = [];
        foreach ($rows as $row) {
            $value = trim((string)($row['facet_value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $result[] = [
                'value' => $value,
                'count' => (int)($row['facet_count'] ?? 0),
            ];
        }

        return $result;
    }

    private function resolveFacetValueFromRow(array $row, string $valueExpression): string
    {
        return match ($valueExpression) {
            "LOWER(COALESCE(a.extension, ''))" => strtolower(trim((string)($row['extension'] ?? ''))),
            "LOWER(COALESCE(a.type, ''))" => strtolower(trim((string)($row['type'] ?? ''))),
            "LOWER(COALESCE(a.upload_source, ''))" => strtolower(trim((string)($row['upload_source'] ?? ''))),
            "LOWER(COALESCE(a.source_context, ''))" => strtolower(trim((string)($row['source_context'] ?? ''))),
            "LOWER(COALESCE(a.object_type, ''))" => strtolower(trim((string)($row['object_type'] ?? ''))),
            "LOWER(COALESCE(lm.source, ''))" => strtolower(trim((string)($row['metadata_source'] ?? ''))),
            "LOWER(COALESCE(lm.metadata_type, ''))" => strtolower(trim((string)($row['metadata_type'] ?? ''))),
            "LOWER(COALESCE(lm.process_name, ''))" => strtolower(trim((string)($row['process_name'] ?? ''))),
            "LOWER(COALESCE(lm.process_version, ''))" => strtolower(trim((string)($row['process_version'] ?? ''))),
            "LOWER(COALESCE(lm.document_type, ''))" => strtolower(trim((string)($row['document_type'] ?? ''))),
            "LOWER(COALESCE(lm.document_direction, ''))" => strtolower(trim((string)($row['document_direction'] ?? ''))),
            "LOWER(COALESCE(lm.status, ''))" => strtolower(trim((string)($row['metadata_status'] ?? ''))),
            "LOWER(COALESCE(lm.process_status, ''))" => strtolower(trim((string)($row['process_status'] ?? ''))),
            default => '',
        };
    }

    /**
     * Resolve the whitelist-based sort column used by the DAM page.
     */
    private function resolveDamSortColumn(string $sort): string
    {
        return match (strtolower(trim($sort))) {
            'filename', 'display_name' => 'display_name',
            'extension' => "LOWER(COALESCE(a.extension, ''))",
            'mime_type', 'type' => "LOWER(COALESCE(a.type, ''))",
            'size' => 'a.size',
            'object_name' => "LOWER(COALESCE(a.object_name, ''))",
            'object_type' => "LOWER(COALESCE(a.object_type, ''))",
            'upload_source' => "LOWER(COALESCE(a.upload_source, ''))",
            'source_context' => "LOWER(COALESCE(a.source_context, ''))",
            'created_by' => "LOWER(COALESCE(au.name, au.email, ''))",
            'last_update', 'updated_at' => 'a.last_update',
            'metadata_status' => "LOWER(COALESCE(lm.status, ''))",
            'process_status' => "LOWER(COALESCE(lm.process_status, ''))",
            'confidence', 'confidence_score' => 'COALESCE(lm.confidence_score, -1)',
            default => 'a.created_at',
        };
    }

    /**
     * @param array<int,string> $values
     * @param array<int,string> $allowed
     */
    private function normalizeAllowedArray(array $values, array $allowed): array
    {
        $allowedMap = array_fill_keys($allowed, true);
        $normalized = [];
        foreach ($values as $value) {
            $value = strtolower(trim($value));
            if ($value !== '' && isset($allowedMap[$value])) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,mixed>|mixed $value
     * @return array<int,string>
     */
    private function normalizeStringArray($value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            $value = explode(',', (string)$value);
        }

        $normalized = [];
        foreach ($value as $item) {
            $stringValue = strtolower(trim((string)$item));
            if ($stringValue !== '') {
                $normalized[$stringValue] = $stringValue;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,int|string> $value
     * @return array<int,int>
     */
    private function normalizeIntegerArray(array $value): array
    {
        $normalized = [];
        foreach ($value as $item) {
            $intValue = (int)$item;
            if ($intValue > 0) {
                $normalized[$intValue] = $intValue;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,string> $where
     * @param array<int,array{0:mixed,1:int}> $params
     * @param array<int,string>|string|null $value
     */
    private function addInCondition(array &$where, array &$params, string $column, $value): void
    {
        $values = $this->normalizeStringArray($value);
        if ($values === []) {
            return;
        }

        $where[] = $column . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        foreach ($values as $item) {
            $params[] = [$item, PDO::PARAM_STR];
        }
    }

    /**
     * @param array<int,string> $where
     * @param array<int,array{0:mixed,1:int}> $params
     */
    private function addLikeCondition(array &$where, array &$params, string $column, $value): void
    {
        $value = trim((string)$value);
        if ($value === '') {
            return;
        }

        $where[] = $column . ' LIKE ?';
        $params[] = ['%' . strtolower($value) . '%', PDO::PARAM_STR];
    }

    /**
     * @param array<int,string> $where
     * @param array<int,array{0:mixed,1:int}> $params
     */
    private function addNumericRangeCondition(array &$where, array &$params, string $column, $min, $max): void
    {
        if ($min !== null && $min !== '' && is_numeric($min)) {
            $where[] = $column . ' >= ?';
            $params[] = [$min + 0, is_int($min + 0) ? PDO::PARAM_INT : PDO::PARAM_STR];
        }
        if ($max !== null && $max !== '' && is_numeric($max)) {
            $where[] = $column . ' <= ?';
            $params[] = [$max + 0, is_int($max + 0) ? PDO::PARAM_INT : PDO::PARAM_STR];
        }
    }

    /**
     * @param array<int,string> $where
     * @param array<int,array{0:mixed,1:int}> $params
     */
    private function addDateRangeCondition(array &$where, array &$params, string $column, $from, $to): void
    {
        $from = trim((string)$from);
        $to = trim((string)$to);

        if ($from !== '') {
            $where[] = $column . ' >= ?';
            $params[] = [$from . ' 00:00:00', PDO::PARAM_STR];
        }
        if ($to !== '') {
            $where[] = $column . ' <= ?';
            $params[] = [$to . ' 23:59:59', PDO::PARAM_STR];
        }
    }

    /**
     * @param array<int,string> $where
     * @param array<int,array{0:mixed,1:int}> $params
     */
    private function addUserFilterCondition(array &$where, array &$params, string $idColumn, string $userAlias, $value): void
    {
        if ($value === null || trim((string)$value) === '') {
            return;
        }

        $raw = trim((string)$value);
        if (ctype_digit($raw)) {
            $where[] = $idColumn . ' = ?';
            $params[] = [(int)$raw, PDO::PARAM_INT];
            return;
        }

        $like = '%' . strtolower($raw) . '%';
        $where[] = '(LOWER(COALESCE(' . $userAlias . ".name, '')) LIKE ? OR LOWER(COALESCE(" . $userAlias . ".email, '')) LIKE ?)";
        $params[] = [$like, PDO::PARAM_STR];
        $params[] = [$like, PDO::PARAM_STR];
    }

    /**
     * @param array<int,string> $where
     * @param array<int,array{0:mixed,1:int}> $params
     * @param array<int,string> $columns
     */
    private function addBusinessTextCondition(array &$where, array &$params, $value, array $columns): void
    {
        $value = trim((string)$value);
        if ($value === '') {
            return;
        }

        $like = '%' . strtolower($value) . '%';
        $local = [];
        foreach ($columns as $column) {
            $local[] = $column . ' LIKE ?';
            $params[] = [$like, PDO::PARAM_STR];
        }

        if ($local !== []) {
            $where[] = '(' . implode(' OR ', $local) . ')';
        }
    }

    private function normalizeNullableInt($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int,array{0:mixed,1:int}> $params
     * @return array<int,array<string,mixed>>
     */
    private function fetchAllAssoc(string $sql, array $params = []): array
    {
        $stmt = $this->PDO->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int,array{0:mixed,1:int}> $params
     * @return array<string,mixed>|null
     */
    private function fetchOneAssoc(string $sql, array $params = []): ?array
    {
        $stmt = $this->PDO->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int,array{0:mixed,1:int}> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $index => $item) {
            $stmt->bindValue($index + 1, $item[0], $item[1]);
        }
    }
}
