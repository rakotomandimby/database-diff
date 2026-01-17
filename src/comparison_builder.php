<?php

declare(strict_types=1);

function buildTableComparison(
    array $db1Config,
    mysqli $db1Connection,
    array $db2Config,
    mysqli $db2Connection,
    mysqli $storageConnection,
    int $runId
): array {
    $db1Label = $db1Config['label'] ?? 'Database 1';
    $db2Label = $db2Config['label'] ?? 'Database 2';

    reportProgress($storageConnection, $runId, 'init', 'Initializing comparison...', 0);

    reportProgress($storageConnection, $runId, 'snapshot_source', "Loading {$db1Label} schema...", 5);
    captureDatabaseSnapshot(
        $db1Connection,
        $storageConnection,
        $runId,
        'source',
        $db1Config['database'] ?? ''
    );

    reportProgress($storageConnection, $runId, 'snapshot_target', "Loading {$db2Label} schema...", 30);
    captureDatabaseSnapshot(
        $db2Connection,
        $storageConnection,
        $runId,
        'target',
        $db2Config['database'] ?? ''
    );

    reportProgress($storageConnection, $runId, 'load_tables', 'Loading table lists...', 50);
    $tablesDb1 = getTablesFromStorage($storageConnection, $runId, 'source');
    $tablesDb2 = getTablesFromStorage($storageConnection, $runId, 'target');

    $onlyInDb1 = array_values(array_diff($tablesDb1, $tablesDb2));
    $onlyInDb2 = array_values(array_diff($tablesDb2, $tablesDb1));

    $allTables = array_values(array_unique(array_merge($tablesDb1, $tablesDb2)));
    sort($allTables, SORT_NATURAL | SORT_FLAG_CASE);

    reportProgress($storageConnection, $runId, 'compare_schemas', 'Comparing schemas...', 55);
    $tableDetails = [];
    $totalTables = count($allTables);
    $processedTables = 0;

    foreach ($allTables as $tableName) {
        $processedTables++;
        $progressPercent = 55 + (int) round(($processedTables / $totalTables) * 10);
        reportProgress(
            $storageConnection,
            $runId,
            'analyze_table',
            "Analyzing table {$tableName} ({$processedTables}/{$totalTables})...",
            $progressPercent
        );

        $tableDetail = buildTableDetailFromStorage($storageConnection, $runId, $tableName);

        if ($tableDetail['hasDifferences']) {
            $tableDetails[$tableName] = $tableDetail;
            persistTableDifferences($storageConnection, $runId, $tableName, $tableDetail);
        }
    }

    reportProgress($storageConnection, $runId, 'comparison_complete', 'Schema comparison complete', 65);

    return [
        'db1Label' => $db1Label,
        'db2Label' => $db2Label,
        'tablesDb1' => $tablesDb1,
        'tablesDb2' => $tablesDb2,
        'onlyInDb1' => $onlyInDb1,
        'onlyInDb2' => $onlyInDb2,
        'tableDetails' => $tableDetails,
        'runId' => $runId,
    ];
}

function createComparisonRun(mysqli $storageConnection, array $db1Config, array $db2Config): int
{
    $sourceLabel = $db1Config['label'] ?? 'Database 1';
    $targetLabel = $db2Config['label'] ?? 'Database 2';
    $sourceDatabase = $db1Config['database'] ?? '';
    $targetDatabase = $db2Config['database'] ?? '';

    $stmt = $storageConnection->prepare(
        'INSERT INTO comparison_runs (source_label, target_label, source_database, target_database)
         VALUES (?, ?, ?, ?)'
    );

    $stmt->bind_param('ssss', $sourceLabel, $targetLabel, $sourceDatabase, $targetDatabase);
    $stmt->execute();
    $stmt->close();

    return (int) $storageConnection->insert_id;
}

function markComparisonRunCompleted(mysqli $storageConnection, int $runId): void
{
    $stmt = $storageConnection->prepare(
        'UPDATE comparison_runs
         SET status = "completed", completed_at = NOW(), progress_percent = 100
         WHERE id = ?'
    );

    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $stmt->close();
}

function markComparisonRunFailed(mysqli $storageConnection, int $runId, string $errorMessage): void
{
    $stmt = $storageConnection->prepare(
        'UPDATE comparison_runs
         SET status = "failed", completed_at = NOW(), error_message = ?
         WHERE id = ?'
    );

    $stmt->bind_param('si', $errorMessage, $runId);
    $stmt->execute();
    $stmt->close();
}

function storeGeneratedSql(
    mysqli $storageConnection,
    int $runId,
    string $tableName,
    string $modelName,
    string $statements
): void {
    $stmt = $storageConnection->prepare(
        'INSERT INTO generated_sql (run_id, table_name, statements, model_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            statements = VALUES(statements),
            model_name = VALUES(model_name),
            generated_at = CURRENT_TIMESTAMP'
    );

    $stmt->bind_param('isss', $runId, $tableName, $statements, $modelName);
    $stmt->execute();
    $stmt->close();
}

