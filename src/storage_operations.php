<?php

declare(strict_types=1);

function persistTableDifferences(
    mysqli $storageConnection,
    int $runId,
    string $tableName,
    array $tableDetail
): void {
    $differences = [];

    if (!$tableDetail['inDb1']) {
        $differences[] = [
            'type' => 'missing_in_source',
            'side' => 'source',
            'payload' => ['message' => 'Table missing in source database'],
        ];
    }

    if (!$tableDetail['inDb2']) {
        $differences[] = [
            'type' => 'missing_in_target',
            'side' => 'target',
            'payload' => ['message' => 'Table missing in target database'],
        ];
    }

    if ($tableDetail['tableMetadataDifferences'] !== []) {
        $differences[] = [
            'type' => 'metadata',
            'side' => 'both',
            'payload' => ['differences' => $tableDetail['tableMetadataDifferences']],
        ];
    }

    if (
        $tableDetail['onlyColumnsDb1'] !== []
        || $tableDetail['onlyColumnsDb2'] !== []
        || $tableDetail['columnDifferences'] !== []
    ) {
        $differences[] = [
            'type' => 'columns',
            'side' => 'both',
            'payload' => [
                'onlyInSource' => $tableDetail['onlyColumnsDb1'],
                'onlyInTarget' => $tableDetail['onlyColumnsDb2'],
                'modified' => $tableDetail['columnDifferences'],
            ],
        ];
    }

    if (
        $tableDetail['foreignKeysOnlyDb1'] !== []
        || $tableDetail['foreignKeysOnlyDb2'] !== []
        || $tableDetail['foreignKeysModified'] !== []
    ) {
        $differences[] = [
            'type' => 'foreign_keys',
            'side' => 'both',
            'payload' => [
                'onlyInSource' => $tableDetail['foreignKeysOnlyDb1'],
                'onlyInTarget' => $tableDetail['foreignKeysOnlyDb2'],
                'modified' => $tableDetail['foreignKeysModified'],
            ],
        ];
    }

    if ($differences === []) {
        return;
    }

    $stmt = $storageConnection->prepare(
        'INSERT INTO dbdif_table_differences (run_id, table_name, difference_type, database_side, payload)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($differences as $difference) {
        $payloadJson = json_encode($difference['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payloadJson === false) {
            $payloadJson = '{}';
        }

        $stmt->bind_param(
            'issss',
            $runId,
            $tableName,
            $difference['type'],
            $difference['side'],
            $payloadJson
        );

        $stmt->execute();
    }

    $stmt->close();
}

function getTablesFromStorage(mysqli $storageConnection, int $runId, string $databaseSide): array
{
    $stmt = $storageConnection->prepare(
        'SELECT table_name
         FROM dbdif_table_snapshots
         WHERE run_id = ? AND database_side = ?'
    );

    $stmt->bind_param('is', $runId, $databaseSide);
    $stmt->execute();
    $stmt->bind_result($tableName);

    $tables = [];

    while ($stmt->fetch()) {
        $tables[] = $tableName;
    }

    $stmt->close();

    sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

    return $tables;
}

function getAllTablesForRun(mysqli $storageConnection, int $runId): array
{
    $stmt = $storageConnection->prepare(
        'SELECT DISTINCT table_name
         FROM dbdif_table_snapshots
         WHERE run_id = ?'
    );

    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $stmt->bind_result($tableName);

    $tables = [];

    while ($stmt->fetch()) {
        $tables[] = $tableName;
    }

    $stmt->close();

    $tables = array_values(array_unique($tables));
    sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

    return $tables;
}

/**
 * Deletes comparison runs older than the specified number of days.
 * This function should be called periodically (e.g., via cron job) to clean up old data.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $daysToKeep Number of days to keep (default: 7)
 * @return int Number of runs deleted
 */
function cleanupOldComparisonRuns(mysqli $storageConnection, int $daysToKeep = 7): int
{
    $stmt = $storageConnection->prepare(
        'DELETE FROM dbdif_comparison_runs
         WHERE completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            OR (status = "failed" AND started_at < DATE_SUB(NOW(), INTERVAL ? DAY))'
    );

    $stmt->bind_param('ii', $daysToKeep, $daysToKeep);
    $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows;
}

/**
 * Deletes comparison runs that have been running for more than the specified timeout.
 * This helps clean up stale runs that may have crashed without proper cleanup.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $timeoutMinutes Timeout in minutes (default: 30)
 * @return int Number of runs deleted
 */
function cleanupStalledComparisonRuns(mysqli $storageConnection, int $timeoutMinutes = 30): int
{
    $stmt = $storageConnection->prepare(
        'DELETE FROM dbdif_comparison_runs
         WHERE status = "running"
           AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );

    $stmt->bind_param('i', $timeoutMinutes);
    $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows;
}

/**
 * Gets statistics about the storage database usage.
 * Useful for monitoring and deciding when to run cleanup operations.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @return array Statistics including total runs, completed runs, failed runs, etc.
 */
function getStorageStatistics(mysqli $storageConnection): array
{
    $stats = [
        'totalRuns' => 0,
        'completedRuns' => 0,
        'failedRuns' => 0,
        'runningRuns' => 0,
        'oldestRun' => null,
        'newestRun' => null,
    ];

    $result = $storageConnection->query(
        'SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = "running" THEN 1 ELSE 0 END) as running,
            MIN(started_at) as oldest,
            MAX(started_at) as newest
         FROM dbdif_comparison_runs'
    );

    if ($result !== false) {
        $row = $result->fetch_assoc();
        if ($row !== null) {
            $stats['totalRuns'] = (int) $row['total'];
            $stats['completedRuns'] = (int) $row['completed'];
            $stats['failedRuns'] = (int) $row['failed'];
            $stats['runningRuns'] = (int) $row['running'];
            $stats['oldestRun'] = $row['oldest'];
            $stats['newestRun'] = $row['newest'];
        }
        $result->free();
    }
    return $stats;
}

