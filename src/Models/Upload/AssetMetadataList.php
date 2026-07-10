<?php

namespace Boostack\Models\Upload;

use Boostack\Models\BaseList;
use PDO;

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
     * Default monitored process used by the enrichment dashboard.
     */
    private const DEFAULT_MONITOR_PROCESS = 'asset_metadata_enrichment';

    /**
     * Claims older than this threshold are considered stale in the monitor.
     */
    private const STALE_CLAIM_MINUTES = 30;

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

    /**
     * Load the latest metadata row for each asset identifier.
     *
     * @param array<int,int|string> $assetIds
     * @return array<int,array<string,mixed>>
     */
    public function getLatestByAssetIds(array $assetIds): array
    {
        $normalizedIds = $this->normalizeIntegerArray($assetIds);
        if ($normalizedIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedIds), '?'));
        $sql = "
            SELECT latest.*
            FROM boostack_asset_metadata latest
            INNER JOIN (
                SELECT id_asset, MAX(id) AS max_id
                FROM boostack_asset_metadata
                WHERE deleted_at IS NULL
                  AND id_asset IN ({$placeholders})
                GROUP BY id_asset
            ) grouped ON grouped.max_id = latest.id
            ORDER BY latest.id_asset ASC, latest.id DESC
        ";

        $params = [];
        foreach ($normalizedIds as $id) {
            $params[] = [$id, PDO::PARAM_INT];
        }

        return $this->fetchAllAssoc($sql, $params);
    }

    /**
     * Load all metadata versions for one asset, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getByAssetId(int $assetId): array
    {
        if ($assetId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                m.*,
                COALESCE(NULLIF(TRIM(cu.name), ''), NULLIF(TRIM(cu.email), ''), CASE WHEN m.created_by IS NULL THEN '' ELSE CONCAT('User #', m.created_by) END) AS created_by_label,
                COALESCE(NULLIF(TRIM(ru.name), ''), NULLIF(TRIM(ru.email), ''), CASE WHEN m.requested_by IS NULL THEN '' ELSE CONCAT('User #', m.requested_by) END) AS requested_by_label,
                COALESCE(NULLIF(TRIM(pu.name), ''), NULLIF(TRIM(pu.email), ''), CASE WHEN m.processed_by IS NULL THEN '' ELSE CONCAT('User #', m.processed_by) END) AS processed_by_label
            FROM boostack_asset_metadata m
            LEFT JOIN boostack_user cu ON cu.id = m.created_by
            LEFT JOIN boostack_user ru ON ru.id = m.requested_by
            LEFT JOIN boostack_user pu ON pu.id = m.processed_by
            WHERE m.id_asset = ?
              AND m.deleted_at IS NULL
            ORDER BY m.id DESC
        ";

        return $this->fetchAllAssoc($sql, [
            [$assetId, PDO::PARAM_INT],
        ]);
    }

    /**
     * Aggregate operational metrics for the enrichment dashboard.
     *
     * The dashboard focuses on the latest metadata row per asset/process so the
     * queue reflects the current state rather than historical versions.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function getEnrichmentStats(array $filters = []): array
    {
        $parts = $this->buildMonitorQueryParts($filters);
        $latestFrom = $parts['from'];
        $latestWhereSql = $parts['where_sql'];
        $latestParams = $parts['params'];

        $metadataTotalRow = $this->fetchOneAssoc(
            "SELECT COUNT(*) AS total_rows {$latestFrom} {$latestWhereSql}",
            $latestParams
        ) ?? [];

        $assetsWithMetadataRow = $this->fetchOneAssoc(
            "SELECT COUNT(DISTINCT m.id_asset) AS total_assets {$latestFrom} {$latestWhereSql}",
            $latestParams
        ) ?? [];

        $totalAssetsRow = $this->fetchOneAssoc(
            "SELECT COUNT(*) AS total_assets FROM boostack_asset a WHERE a.deleted_at IS NULL"
        ) ?? [];

        $processStatusRows = $this->fetchAllAssoc(
            "
            SELECT
                COALESCE(NULLIF(TRIM(m.process_status), ''), 'unassigned') AS process_status,
                COUNT(*) AS total_count,
                MIN(m.created_at) AS oldest_created_at,
                MAX(m.last_update) AS newest_last_update
            {$latestFrom}
            {$latestWhereSql}
            GROUP BY process_status
            ORDER BY total_count DESC, process_status ASC
            ",
            $latestParams
        );

        $statusRows = $this->fetchAllAssoc(
            "
            SELECT
                COALESCE(NULLIF(TRIM(m.status), ''), 'unassigned') AS status,
                COUNT(*) AS total_count
            {$latestFrom}
            {$latestWhereSql}
            GROUP BY status
            ORDER BY total_count DESC, status ASC
            ",
            $latestParams
        );

        $processRows = $this->fetchAllAssoc(
            "
            SELECT
                COALESCE(NULLIF(TRIM(m.process_name), ''), 'n.d.') AS process_name,
                COALESCE(NULLIF(TRIM(m.process_version), ''), 'n.d.') AS process_version,
                COUNT(*) AS total_count,
                SUM(CASE WHEN LOWER(COALESCE(m.process_status, '')) = 'ready' THEN 1 ELSE 0 END) AS ready_count,
                SUM(CASE WHEN LOWER(COALESCE(m.process_status, '')) = 'error' THEN 1 ELSE 0 END) AS error_count,
                SUM(CASE WHEN LOWER(COALESCE(m.process_status, '')) = 'retry' THEN 1 ELSE 0 END) AS retry_count,
                SUM(CASE WHEN LOWER(COALESCE(m.process_status, '')) = 'skipped' THEN 1 ELSE 0 END) AS skipped_count,
                AVG(m.confidence_score) AS avg_confidence,
                AVG(
                    CASE
                        WHEN m.process_started_at IS NOT NULL
                         AND m.process_completed_at IS NOT NULL
                        THEN TIMESTAMPDIFF(SECOND, m.process_started_at, m.process_completed_at)
                        ELSE NULL
                    END
                ) AS avg_duration_seconds
            {$latestFrom}
            {$latestWhereSql}
            GROUP BY process_name, process_version
            ORDER BY total_count DESC, process_name ASC, process_version ASC
            ",
            $latestParams
        );

        $latestErrors = $this->fetchAllAssoc(
            "
            SELECT
                m.id,
                m.id_asset,
                a.temp_name,
                a.filename,
                m.process_name,
                m.process_version,
                m.process_status,
                m.status,
                m.error_message,
                m.processing_attempts,
                m.next_retry_at,
                m.last_update
            {$latestFrom}
            {$latestWhereSql}
              " . ($latestWhereSql === '' ? ' WHERE ' : ' AND ') . "
              (
                LOWER(COALESCE(m.process_status, '')) = 'error'
                OR LOWER(COALESCE(m.status, '')) = 'error'
                OR COALESCE(NULLIF(TRIM(m.error_message), ''), NULL) IS NOT NULL
              )
            ORDER BY m.last_update DESC, m.id DESC
            LIMIT 20
            ",
            $latestParams
        );

        $upcomingRetries = $this->fetchAllAssoc(
            "
            SELECT
                m.id,
                m.id_asset,
                a.temp_name,
                a.filename,
                m.process_name,
                m.process_version,
                m.processing_attempts,
                m.next_retry_at,
                m.last_update
            {$latestFrom}
            {$latestWhereSql}
              " . ($latestWhereSql === '' ? ' WHERE ' : ' AND ') . "
              LOWER(COALESCE(m.process_status, '')) = 'retry'
              AND m.next_retry_at IS NOT NULL
            ORDER BY m.next_retry_at ASC, m.id ASC
            LIMIT 20
            ",
            $latestParams
        );

        $claimsRows = $this->fetchAllAssoc(
            "
            SELECT
                COALESCE(NULLIF(TRIM(m.claimed_by), ''), 'n.d.') AS claimed_by,
                m.claimed_at,
                COUNT(*) AS total_count,
                CASE
                    WHEN m.claimed_at IS NOT NULL
                     AND m.claimed_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_CLAIM_MINUTES . " MINUTE)
                    THEN 1
                    ELSE 0
                END AS is_stale
            {$latestFrom}
            {$latestWhereSql}
              " . ($latestWhereSql === '' ? ' WHERE ' : ' AND ') . "
              m.claimed_at IS NOT NULL
            GROUP BY claimed_by, m.claimed_at, is_stale
            ORDER BY m.claimed_at DESC, claimed_by ASC
            LIMIT 50
            ",
            $latestParams
        );

        $backlogRow = $this->fetchBacklogRow($parts);
        $throughput = $this->fetchThroughputRows($parts);
        $latestProcessedRow = $this->fetchOneAssoc(
            "
            SELECT
                m.id,
                m.id_asset,
                a.temp_name,
                a.filename,
                m.process_name,
                m.process_status,
                m.process_completed_at,
                m.last_update
            {$latestFrom}
            {$latestWhereSql}
              " . ($latestWhereSql === '' ? ' WHERE ' : ' AND ') . "
              LOWER(COALESCE(m.process_status, '')) IN ('validated', 'ready')
              AND m.process_completed_at IS NOT NULL
            ORDER BY m.process_completed_at DESC, m.id DESC
            LIMIT 1
            ",
            $latestParams
        );
        $latestErrorRow = $latestErrors[0] ?? null;

        $metadataTotal = (int)($metadataTotalRow['total_rows'] ?? 0);
        $assetsWithMetadata = (int)($assetsWithMetadataRow['total_assets'] ?? 0);
        $activeAssets = (int)($totalAssetsRow['total_assets'] ?? 0);
        $assetsWithoutMetadata = max(0, $activeAssets - $assetsWithMetadata);
        $processStatusMap = $this->indexCounterRows($processStatusRows, 'process_status');
        $statusMap = $this->indexCounterRows($statusRows, 'status');
        $avgRow = $this->fetchOneAssoc(
            "
            SELECT
                AVG(m.confidence_score) AS avg_confidence,
                AVG(m.processing_attempts) AS avg_processing_attempts
            {$latestFrom}
            {$latestWhereSql}
            ",
            $latestParams
        ) ?? [];

        $processedToday = $this->fetchWindowCompletionCount($parts, 0, 'day');
        $processed24h = $throughput['completed_24h'];
        $errors24h = $this->fetchWindowErrorCount($parts, 24);
        $recommendedThroughput = $throughput['throughput_per_hour'];
        $eta = $this->buildEtaPayload(
            (int)($backlogRow['backlog_count'] ?? 0),
            $throughput['completed_1h'],
            $throughput['completed_6h'],
            $throughput['completed_24h'],
            $recommendedThroughput
        );

        $byProcessStatus = [];
        foreach ($processStatusRows as $row) {
            $count = (int)($row['total_count'] ?? 0);
            $byProcessStatus[] = [
                'process_status' => (string)($row['process_status'] ?? 'unassigned'),
                'count' => $count,
                'percentage' => $metadataTotal > 0 ? round(($count / $metadataTotal) * 100, 2) : 0.0,
                'oldest_created_at' => $row['oldest_created_at'] ?? null,
                'newest_last_update' => $row['newest_last_update'] ?? null,
            ];
        }

        $byStatus = [];
        foreach ($statusRows as $row) {
            $count = (int)($row['total_count'] ?? 0);
            $byStatus[] = [
                'status' => (string)($row['status'] ?? 'unassigned'),
                'count' => $count,
                'percentage' => $metadataTotal > 0 ? round(($count / $metadataTotal) * 100, 2) : 0.0,
            ];
        }

        $byProcess = [];
        foreach ($processRows as $row) {
            $byProcess[] = [
                'process_name' => (string)($row['process_name'] ?? 'n.d.'),
                'process_version' => (string)($row['process_version'] ?? 'n.d.'),
                'total' => (int)($row['total_count'] ?? 0),
                'ready' => (int)($row['ready_count'] ?? 0),
                'error' => (int)($row['error_count'] ?? 0),
                'retry' => (int)($row['retry_count'] ?? 0),
                'skipped' => (int)($row['skipped_count'] ?? 0),
                'avg_confidence' => $this->normalizeFloatOrNull($row['avg_confidence'] ?? null),
                'avg_duration_seconds' => $this->normalizeFloatOrNull($row['avg_duration_seconds'] ?? null),
            ];
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'kpi' => [
                'total_assets' => $activeAssets,
                'active_assets' => $activeAssets,
                'assets_with_metadata' => $assetsWithMetadata,
                'assets_without_metadata' => $assetsWithoutMetadata,
                'metadata_total' => $metadataTotal,
                'queued' => $processStatusMap['queued'] ?? 0,
                'processing' => $processStatusMap['processing'] ?? 0,
                'uploaded' => $processStatusMap['uploaded'] ?? 0,
                'extracting' => $processStatusMap['extracting'] ?? 0,
                'text_extracted' => $processStatusMap['text_extracted'] ?? 0,
                'ocr_done' => $processStatusMap['ocr_done'] ?? 0,
                'llm_done' => $processStatusMap['llm_done'] ?? 0,
                'validated' => $processStatusMap['validated'] ?? 0,
                'ready' => $processStatusMap['ready'] ?? 0,
                'retry' => $processStatusMap['retry'] ?? 0,
                'skipped' => $processStatusMap['skipped'] ?? 0,
                'error' => $processStatusMap['error'] ?? 0,
                'warning' => $statusMap['warning'] ?? 0,
                'captured' => $statusMap['captured'] ?? 0,
                'processed' => $statusMap['processed'] ?? 0,
                'superseded' => $statusMap['superseded'] ?? 0,
                'confidence_avg' => $this->normalizeFloatOrNull($avgRow['avg_confidence'] ?? null),
                'processing_attempts_avg' => $this->normalizeFloatOrNull($avgRow['avg_processing_attempts'] ?? null),
                'processed_today' => $processedToday,
                'processed_24h' => $processed24h,
                'errors_24h' => $errors24h,
                'latest_processed' => $latestProcessedRow,
                'latest_error' => $latestErrorRow,
            ],
            'eta' => $eta,
            'by_process_status' => $byProcessStatus,
            'by_status' => $byStatus,
            'by_process' => $byProcess,
            'latest_errors' => $latestErrors,
            'upcoming_retries' => $upcomingRetries,
            'claims' => $claimsRows,
        ];
    }

    /**
     * Build the latest-state monitor query filtered by process and metadata
     * dimensions relevant to the enrichment dashboard.
     *
     * @param array<string,mixed> $filters
     * @return array{
     *     from:string,
     *     where_sql:string,
     *     params:array<int,array{0:mixed,1:int}>,
     *     process_names:array<int,string>,
     *     filters:array<string,mixed>
     * }
     */
    private function buildMonitorQueryParts(array $filters): array
    {
        $params = [];
        $normalizedFilters = $this->normalizeMonitorFilters($filters);
        $where = [];
        $processNames = $this->normalizeStringArray($normalizedFilters['process_name'] ?? self::DEFAULT_MONITOR_PROCESS);
        if ($processNames === []) {
            $processNames = [self::DEFAULT_MONITOR_PROCESS];
        }

        $from = "
            FROM boostack_asset_metadata m
            INNER JOIN (
                SELECT id_asset, process_name, MAX(id) AS max_id
                FROM boostack_asset_metadata
                WHERE deleted_at IS NULL
                GROUP BY id_asset, process_name
            ) latest ON latest.max_id = m.id
            INNER JOIN boostack_asset a
                ON a.id = m.id_asset
               AND a.deleted_at IS NULL
        ";

        $this->addMonitorFilterConditions($where, $params, 'm', $normalizedFilters);

        return [
            'from' => $from,
            'where_sql' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
            'process_names' => $processNames,
            'filters' => $normalizedFilters,
        ];
    }

    /**
     * Count backlog assets for the monitored process set.
     *
     * @param array{
     *     from:string,
     *     where_sql:string,
     *     params:array<int,array{0:mixed,1:int}>,
     *     process_names:array<int,string>,
     *     filters:array<string,mixed>
     * } $parts
     * @return array<string,mixed>
     */
    private function fetchBacklogRow(array $parts): array
    {
        $params = [];
        $filters = is_array($parts['filters'] ?? null) ? $parts['filters'] : [];
        $metadataWhere = [];
        $this->addMonitorFilterConditions($metadataWhere, $params, 'monitor_metadata', $filters);
        $hasMetadataScopedFilters = $this->hasMonitorScopedMetadataFilters($filters);
        $backlogStatuses = "
            LOWER(COALESCE(monitor_metadata.process_status, '')) IN (
                'queued',
                'retry',
                'error',
                'processing',
                'extracting',
                'text_extracted',
                'ocr_done',
                'llm_done',
                'uploaded'
            )
            OR LOWER(COALESCE(monitor_metadata.process_status, '')) = ''
        ";
        $metadataFilterSql = $metadataWhere === [] ? '1=1' : implode(' AND ', $metadataWhere);

        $sql = "
            SELECT COUNT(DISTINCT a.id) AS backlog_count
            FROM boostack_asset a
            LEFT JOIN (
                SELECT current_state.*
                FROM boostack_asset_metadata current_state
                INNER JOIN (
                    SELECT id_asset, process_name, MAX(id) AS max_id
                    FROM boostack_asset_metadata
                    WHERE deleted_at IS NULL
                    GROUP BY id_asset, process_name
                ) grouped ON grouped.max_id = current_state.id
            ) monitor_metadata ON monitor_metadata.id_asset = a.id
            WHERE a.deleted_at IS NULL
              AND (
                (
                    monitor_metadata.id IS NULL
                    AND " . ($hasMetadataScopedFilters ? '0=1' : '1=1') . "
                )
                OR (
                    monitor_metadata.id IS NOT NULL
                    AND {$metadataFilterSql}
                    AND ({$backlogStatuses})
                )
              )
        ";

        return $this->fetchOneAssoc($sql, $params) ?? ['backlog_count' => 0];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function normalizeMonitorFilters(array $filters): array
    {
        return [
            'process_name' => $filters['process_name'] ?? self::DEFAULT_MONITOR_PROCESS,
            'process_version' => $filters['process_version'] ?? null,
            'source' => $filters['source'] ?? null,
            'document_type' => $filters['document_type'] ?? null,
            'status' => $filters['status'] ?? null,
            'process_status' => $filters['process_status'] ?? null,
            'date_from' => $filters['date_from'] ?? ($filters['updated_from'] ?? null),
            'date_to' => $filters['date_to'] ?? ($filters['updated_to'] ?? null),
        ];
    }

    /**
     * @param array<int,string> $where
     * @param array<int,array{0:mixed,1:int}> $params
     * @param array<string,mixed> $filters
     */
    private function addMonitorFilterConditions(array &$where, array &$params, string $alias, array $filters): void
    {
        $processNames = $this->normalizeStringArray($filters['process_name'] ?? self::DEFAULT_MONITOR_PROCESS);
        if ($processNames === []) {
            $processNames = [self::DEFAULT_MONITOR_PROCESS];
        }

        $where[] = "LOWER(COALESCE({$alias}.process_name, '')) IN (" . implode(', ', array_fill(0, count($processNames), '?')) . ')';
        foreach ($processNames as $processName) {
            $params[] = [$processName, PDO::PARAM_STR];
        }

        $this->addInCondition($where, $params, "LOWER(COALESCE({$alias}.process_version, ''))", $filters['process_version'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE({$alias}.source, ''))", $filters['source'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE({$alias}.document_type, ''))", $filters['document_type'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE({$alias}.status, ''))", $filters['status'] ?? null);
        $this->addInCondition($where, $params, "LOWER(COALESCE({$alias}.process_status, ''))", $filters['process_status'] ?? null);
        $this->addDateRangeCondition(
            $where,
            $params,
            "COALESCE({$alias}.process_completed_at, {$alias}.last_update, {$alias}.created_at)",
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null
        );
    }

    /**
     * Missing metadata can only be part of filtered backlog when the user is
     * not narrowing the result set by metadata-specific dimensions.
     *
     * @param array<string,mixed> $filters
     */
    private function hasMonitorScopedMetadataFilters(array $filters): bool
    {
        foreach (['process_version', 'source', 'document_type', 'status', 'process_status', 'date_from', 'date_to'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_array($value)) {
                if ($value !== []) {
                    return true;
                }
                continue;
            }

            if (trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     from:string,
     *     where_sql:string,
     *     params:array<int,array{0:mixed,1:int}>,
     *     process_names:array<int,string>,
     *     filters:array<string,mixed>
     * } $parts
     * @return array{completed_1h:int,completed_6h:int,completed_24h:int,throughput_per_hour:?float}
     */
    private function fetchThroughputRows(array $parts): array
    {
        $completed1h = $this->fetchWindowCompletionCount($parts, 1);
        $completed6h = $this->fetchWindowCompletionCount($parts, 6);
        $completed24h = $this->fetchWindowCompletionCount($parts, 24);

        if ($completed6h > 0) {
            $throughput = round($completed6h / 6, 2);
        } elseif ($completed24h > 0) {
            $throughput = round($completed24h / 24, 2);
        } else {
            $throughput = null;
        }

        return [
            'completed_1h' => $completed1h,
            'completed_6h' => $completed6h,
            'completed_24h' => $completed24h,
            'throughput_per_hour' => $throughput,
        ];
    }

    /**
     * @param array{
     *     from:string,
     *     where_sql:string,
     *     params:array<int,array{0:mixed,1:int}>,
     *     process_names:array<int,string>,
     *     filters:array<string,mixed>
     * } $parts
     */
    private function fetchWindowCompletionCount(array $parts, int $hours, string $mode = 'hours'): int
    {
        $suffix = $mode === 'day'
            ? 'DATE(COALESCE(m.process_completed_at, m.last_update)) = CURRENT_DATE'
            : 'm.process_completed_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, $hours) . ' HOUR)';

        $sql = "
            SELECT COUNT(*) AS total_count
            {$parts['from']}
            {$parts['where_sql']}
            " . ($parts['where_sql'] === '' ? ' WHERE ' : ' AND ') . "
            LOWER(COALESCE(m.process_status, '')) IN ('validated', 'ready')
            AND m.process_completed_at IS NOT NULL
            AND {$suffix}
        ";

        $row = $this->fetchOneAssoc($sql, $parts['params']) ?? [];
        return (int)($row['total_count'] ?? 0);
    }

    /**
     * @param array{
     *     from:string,
     *     where_sql:string,
     *     params:array<int,array{0:mixed,1:int}>,
     *     process_names:array<int,string>,
     *     filters:array<string,mixed>
     * } $parts
     */
    private function fetchWindowErrorCount(array $parts, int $hours): int
    {
        $sql = "
            SELECT COUNT(*) AS total_count
            {$parts['from']}
            {$parts['where_sql']}
            " . ($parts['where_sql'] === '' ? ' WHERE ' : ' AND ') . "
            (
                LOWER(COALESCE(m.process_status, '')) = 'error'
                OR LOWER(COALESCE(m.status, '')) = 'error'
                OR COALESCE(NULLIF(TRIM(m.error_message), ''), NULL) IS NOT NULL
            )
            AND m.last_update >= DATE_SUB(NOW(), INTERVAL " . max(1, $hours) . " HOUR)
        ";

        $row = $this->fetchOneAssoc($sql, $parts['params']) ?? [];
        return (int)($row['total_count'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEtaPayload(
        int $backlog,
        int $completed1h,
        int $completed6h,
        int $completed24h,
        ?float $throughputPerHour
    ): array {
        if ($throughputPerHour === null || $throughputPerHour <= 0) {
            return [
                'backlog' => $backlog,
                'completed_1h' => $completed1h,
                'completed_6h' => $completed6h,
                'completed_24h' => $completed24h,
                'throughput_per_hour' => null,
                'eta_hours' => null,
                'eta_label' => 'n.d.',
                'message' => 'ETA non disponibile: throughput nullo.',
            ];
        }

        $etaHours = $backlog > 0 ? round($backlog / $throughputPerHour, 2) : 0.0;
        return [
            'backlog' => $backlog,
            'completed_1h' => $completed1h,
            'completed_6h' => $completed6h,
            'completed_24h' => $completed24h,
            'throughput_per_hour' => $throughputPerHour,
            'eta_hours' => $etaHours,
            'eta_label' => $this->formatEtaHours($etaHours),
            'message' => null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function indexCounterRows(array $rows, string $key): array
    {
        $result = [];
        foreach ($rows as $row) {
            $label = strtolower(trim((string)($row[$key] ?? '')));
            if ($label === '') {
                continue;
            }
            $result[$label] = (int)($row['total_count'] ?? 0);
        }

        return $result;
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

    private function normalizeFloatOrNull($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return round((float)$value, 4);
    }

    private function formatEtaHours(float $hours): string
    {
        if ($hours <= 0) {
            return '0h';
        }

        $days = (int)floor($hours / 24);
        $remainingHours = (int)floor($hours - ($days * 24));
        $minutes = (int)round(($hours - floor($hours)) * 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'g';
        }
        if ($remainingHours > 0) {
            $parts[] = $remainingHours . 'h';
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes . 'm';
        }

        return implode(' ', $parts !== [] ? $parts : ['0h']);
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
