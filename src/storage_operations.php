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
        'INSERT INTO table_differences (run_id, table_name, difference_type, database_side, payload)
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
         FROM table_snapshots
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
         FROM table_snapshots
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

function resetStorageDatabase(mysqli $storageConnection): void
{
    $storageConnection->query('SET FOREIGN_KEY_CHECKS = 0');

    try {
        $tables = [
            'generated_sql',
            'table_differences',
            'foreign_key_columns',
            'foreign_key_snapshots',
            'column_snapshots',
            'table_snapshots',
            'comparison_runs',
        ];

        foreach ($tables as $table) {
            $quoted = sprintf('`%s`', str_replace('`', '``', $table));
            $storageConnection->query('TRUNCATE TABLE ' . $quoted);
        }
    } finally {
        $storageConnection->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function getGeneratedSqlForTable(mysqli $storageConnection, int $runId, string $tableName): ?string
{
    $stmt = $storageConnection->prepare(
        'SELECT statements FROM generated_sql WHERE run_id = ? AND table_name = ? LIMIT 1'
    );

    $stmt->bind_param('is', $runId, $tableName);
    $stmt->execute();
    $stmt->bind_result($statements);

    $result = null;
    if ($stmt->fetch()) {
        $result = $statements;
    }

    $stmt->close();

    return $result;
}
