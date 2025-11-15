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

    captureDatabaseSnapshot(
        $db1Connection,
        $storageConnection,
        $runId,
        'source',
        $db1Config['database'] ?? ''
    );

    captureDatabaseSnapshot(
        $db2Connection,
        $storageConnection,
        $runId,
        'target',
        $db2Config['database'] ?? ''
    );

    $tablesDb1 = getTablesFromStorage($storageConnection, $runId, 'source');
    $tablesDb2 = getTablesFromStorage($storageConnection, $runId, 'target');

    $onlyInDb1 = array_values(array_diff($tablesDb1, $tablesDb2));
    $onlyInDb2 = array_values(array_diff($tablesDb2, $tablesDb1));

    $allTables = array_values(array_unique(array_merge($tablesDb1, $tablesDb2)));
    sort($allTables, SORT_NATURAL | SORT_FLAG_CASE);

    $tableDetails = [];

    foreach ($allTables as $tableName) {
        $tableDetail = buildTableDetailFromStorage($storageConnection, $runId, $tableName);

        if ($tableDetail['hasDifferences']) {
            $tableDetails[$tableName] = $tableDetail;
            persistTableDifferences($storageConnection, $runId, $tableName, $tableDetail);
        }
    }

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

function captureDatabaseSnapshot(
    mysqli $dbConnection,
    mysqli $storageConnection,
    int $runId,
    string $databaseSide,
    string $databaseName
): void {
    $tables = getTables($dbConnection);

    if ($tables === []) {
        return;
    }

    foreach ($tables as $tableName) {
        $tableStatus = fetchTableStatus($dbConnection, $tableName);
        $metadataJson = null;

        if ($tableStatus !== []) {
            $metadataJson = json_encode($tableStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($metadataJson === false) {
                $metadataJson = null;
            }
        }

        $checksum = $metadataJson !== null ? hash('sha256', $metadataJson) : null;

        $tableSnapshotId = insertTableSnapshot(
            $storageConnection,
            $runId,
            $databaseSide,
            $tableName,
            $tableStatus['Engine'] ?? null,
            $tableStatus['Collation'] ?? null,
            $checksum,
            $metadataJson
        );

        storeColumnSnapshots($dbConnection, $storageConnection, $tableSnapshotId, $tableName);
        storeForeignKeySnapshots($dbConnection, $storageConnection, $tableSnapshotId, $databaseName, $tableName);
    }
}

function getTables(mysqli $connection): array
{
    $result = $connection->query('SHOW TABLES');
    $tables = [];

    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        $tables[] = $row[0];
    }

    $result->free();

    sort($tables, SORT_NATURAL | SORT_FLAG_CASE);

    return $tables;
}

function fetchTableStatus(mysqli $connection, string $tableName): array
{
    $escapedName = $connection->real_escape_string(addcslashes($tableName, '_%'));
    $query = sprintf("SHOW TABLE STATUS LIKE '%s' ESCAPE '\\\\'", $escapedName);
    $result = $connection->query($query);

    $row = $result->fetch_assoc() ?: [];
    $result->free();

    return $row;
}

function insertTableSnapshot(
    mysqli $storageConnection,
    int $runId,
    string $databaseSide,
    string $tableName,
    ?string $engine,
    ?string $collation,
    ?string $checksum,
    ?string $metadataJson
): int {
    $stmt = $storageConnection->prepare(
        'INSERT INTO table_snapshots (run_id, database_side, table_name, engine, collation, checksum, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->bind_param(
        'issssss',
        $runId,
        $databaseSide,
        $tableName,
        $engine,
        $collation,
        $checksum,
        $metadataJson
    );

    $stmt->execute();
    $stmt->close();

    return (int) $storageConnection->insert_id;
}

function storeColumnSnapshots(
    mysqli $dbConnection,
    mysqli $storageConnection,
    int $tableSnapshotId,
    string $tableName
): void {
    $escapedTable = escapeIdentifier($tableName);
    $result = $dbConnection->query('SHOW FULL COLUMNS FROM ' . $escapedTable);

    $stmt = $storageConnection->prepare(
        'INSERT INTO column_snapshots (
            table_snapshot_id,
            column_name,
            ordinal_position,
            column_type,
            data_type,
            is_nullable,
            column_key,
            column_default,
            extra,
            collation,
            comment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $position = 1;

    while ($row = $result->fetch_assoc()) {
        $dataType = strtolower((string) preg_replace('/\(.*/', '', $row['Type']));
        $isNullable = $row['Null'] ?? 'YES';
        $columnKey = $row['Key'] ?? '';
        $columnDefault = array_key_exists('Default', $row) ? $row['Default'] : null;
        $extra = $row['Extra'] ?? '';
        $collation = $row['Collation'] ?? null;
        $comment = $row['Comment'] ?? null;

        $stmt->bind_param(
            'isissssssss',
            $tableSnapshotId,
            $row['Field'],
            $position,
            $row['Type'],
            $dataType,
            $isNullable,
            $columnKey,
            $columnDefault,
            $extra,
            $collation,
            $comment
        );

        $stmt->execute();
        $position++;
    }

    $stmt->close();
    $result->free();
}

function storeForeignKeySnapshots(
    mysqli $dbConnection,
    mysqli $storageConnection,
    int $tableSnapshotId,
    string $databaseName,
    string $tableName
): void {
    if ($databaseName === '') {
        return;
    }

    $stmt = $dbConnection->prepare(
        'SELECT kcu.CONSTRAINT_NAME,
                kcu.ORDINAL_POSITION,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE AS kcu
         INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
           ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
          AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.TABLE_NAME = kcu.TABLE_NAME
         WHERE kcu.TABLE_SCHEMA = ?
           AND kcu.TABLE_NAME = ?
           AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION'
    );

    if ($stmt === false) {
        return;
    }

    $stmt->bind_param('ss', $databaseName, $tableName);
    $stmt->execute();
    $stmt->bind_result(
        $constraintName,
        $ordinalPosition,
        $columnName,
        $referencedTable,
        $referencedColumn,
        $updateRule,
        $deleteRule
    );

    $foreignKeys = [];

    while ($stmt->fetch()) {
        if (!isset($foreignKeys[$constraintName])) {
            $foreignKeys[$constraintName] = [
                'updateRule' => $updateRule,
                'deleteRule' => $deleteRule,
                'columns' => [],
            ];
        }

        $foreignKeys[$constraintName]['columns'][] = [
            'position' => (int) $ordinalPosition,
            'column' => $columnName,
            'referencedTable' => $referencedTable,
            'referencedColumn' => $referencedColumn,
        ];
    }

    $stmt->close();

    if ($foreignKeys === []) {
        return;
    }

    ksort($foreignKeys, SORT_NATURAL | SORT_FLAG_CASE);

    $fkStmt = $storageConnection->prepare(
        'INSERT INTO foreign_key_snapshots (table_snapshot_id, constraint_name, update_rule, delete_rule)
         VALUES (?, ?, ?, ?)'
    );

    $fkColumnStmt = $storageConnection->prepare(
        'INSERT INTO foreign_key_columns (foreign_key_id, position, column_name, referenced_table, referenced_column)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($foreignKeys as $name => $definition) {
        $fkStmt->bind_param(
            'isss',
            $tableSnapshotId,
            $name,
            $definition['updateRule'],
            $definition['deleteRule']
        );
        $fkStmt->execute();

        $foreignKeyId = (int) $storageConnection->insert_id;

        foreach ($definition['columns'] as $column) {
            $fkColumnStmt->bind_param(
                'iisss',
                $foreignKeyId,
                $column['position'],
                $column['column'],
                $column['referencedTable'],
                $column['referencedColumn']
            );
            $fkColumnStmt->execute();
        }
    }

    $fkStmt->close();
    $fkColumnStmt->close();
}

function escapeIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function buildTableDetailFromStorage(
    mysqli $storageConnection,
    int $runId,
    string $tableName,
    bool $computeDifferences = true
): array {
    $snapshotDb1 = fetchTableSnapshot($storageConnection, $runId, 'source', $tableName);
    $snapshotDb2 = fetchTableSnapshot($storageConnection, $runId, 'target', $tableName);

    $inDb1 = $snapshotDb1 !== null;
    $inDb2 = $snapshotDb2 !== null;

    $columnsDb1 = $inDb1 ? fetchColumnsFromStorage($storageConnection, (int) $snapshotDb1['id']) : [];
    $columnsDb2 = $inDb2 ? fetchColumnsFromStorage($storageConnection, (int) $snapshotDb2['id']) : [];

    $tableMetaDb1 = $inDb1 ? ['Engine' => $snapshotDb1['engine'], 'Collation' => $snapshotDb1['collation']] : null;
    $tableMetaDb2 = $inDb2 ? ['Engine' => $snapshotDb2['engine'], 'Collation' => $snapshotDb2['collation']] : null;

    $foreignKeysDb1 = $inDb1 ? fetchForeignKeysFromStorage($storageConnection, (int) $snapshotDb1['id']) : [];
    $foreignKeysDb2 = $inDb2 ? fetchForeignKeysFromStorage($storageConnection, (int) $snapshotDb2['id']) : [];

    $onlyColumnsDb1 = [];
    $onlyColumnsDb2 = [];
    $columnDifferences = [];
    $tableMetadataDifferences = [];
    $foreignKeysOnlyDb1 = [];
    $foreignKeysOnlyDb2 = [];
    $foreignKeysModified = [];

    $hasDifferences = !$inDb1 || !$inDb2;

    if ($computeDifferences) {
        $onlyColumnsDb1 = array_values(array_diff(array_keys($columnsDb1), array_keys($columnsDb2)));
        $onlyColumnsDb2 = array_values(array_diff(array_keys($columnsDb2), array_keys($columnsDb1)));

        $sharedColumns = array_intersect(array_keys($columnsDb1), array_keys($columnsDb2));

        foreach ($sharedColumns as $columnName) {
            $differences = compareColumnDefinitions($columnsDb1[$columnName], $columnsDb2[$columnName]);

            if ($differences !== []) {
                $columnDifferences[$columnName] = $differences;
            }
        }

        $tableMetadataDifferences = compareTableMetadata($tableMetaDb1, $tableMetaDb2);

        $foreignKeyComparison = compareForeignKeys($foreignKeysDb1, $foreignKeysDb2);
        $foreignKeysOnlyDb1 = $foreignKeyComparison['onlyInDb1'];
        $foreignKeysOnlyDb2 = $foreignKeyComparison['onlyInDb2'];
        $foreignKeysModified = $foreignKeyComparison['modified'];

        $hasDifferences = $hasDifferences
            || $onlyColumnsDb1 !== []
            || $onlyColumnsDb2 !== []
            || $columnDifferences !== []
            || $tableMetadataDifferences !== []
            || $foreignKeysOnlyDb1 !== []
            || $foreignKeysOnlyDb2 !== []
            || $foreignKeysModified !== [];
    }

    return [
        'inDb1' => $inDb1,
        'inDb2' => $inDb2,
        'columnsDb1' => $columnsDb1,
        'columnsDb2' => $columnsDb2,
        'onlyColumnsDb1' => $onlyColumnsDb1,
        'onlyColumnsDb2' => $onlyColumnsDb2,
        'columnDifferences' => $columnDifferences,
        'tableMetaDb1' => $tableMetaDb1,
        'tableMetaDb2' => $tableMetaDb2,
        'tableMetadataDifferences' => $tableMetadataDifferences,
        'foreignKeysDb1' => $foreignKeysDb1,
        'foreignKeysDb2' => $foreignKeysDb2,
        'foreignKeysOnlyDb1' => $foreignKeysOnlyDb1,
        'foreignKeysOnlyDb2' => $foreignKeysOnlyDb2,
        'foreignKeysModified' => $foreignKeysModified,
        'hasDifferences' => $hasDifferences,
    ];
}

function fetchTableSnapshot(
    mysqli $storageConnection,
    int $runId,
    string $databaseSide,
    string $tableName
): ?array {
    $stmt = $storageConnection->prepare(
        'SELECT id, engine, collation
         FROM table_snapshots
         WHERE run_id = ? AND database_side = ? AND table_name = ?
         LIMIT 1'
    );

    $stmt->bind_param('iss', $runId, $databaseSide, $tableName);
    $stmt->execute();
    $stmt->bind_result($id, $engine, $collation);

    $snapshot = null;

    if ($stmt->fetch()) {
        $snapshot = [
            'id' => (int) $id,
            'engine' => $engine,
            'collation' => $collation,
        ];
    }

    $stmt->close();

    return $snapshot;
}

function fetchColumnsFromStorage(mysqli $storageConnection, int $tableSnapshotId): array
{
    $stmt = $storageConnection->prepare(
        'SELECT column_name, column_type, is_nullable, column_key, column_default, extra, collation, comment
         FROM column_snapshots
         WHERE table_snapshot_id = ?
         ORDER BY ordinal_position'
    );

    $stmt->bind_param('i', $tableSnapshotId);
    $stmt->execute();
    $stmt->bind_result(
        $columnName,
        $columnType,
        $isNullable,
        $columnKey,
        $columnDefault,
        $extra,
        $collation,
        $comment
    );

    $columns = [];

    while ($stmt->fetch()) {
        $columns[$columnName] = [
            'Type' => $columnType,
            'Collation' => $collation,
            'Null' => $isNullable,
            'Key' => $columnKey,
            'Default' => $columnDefault,
            'Extra' => $extra,
            'Comment' => $comment,
        ];
    }

    $stmt->close();

    return $columns;
}

function fetchForeignKeysFromStorage(mysqli $storageConnection, int $tableSnapshotId): array
{
    $stmt = $storageConnection->prepare(
        'SELECT fks.constraint_name,
                fks.update_rule,
                fks.delete_rule,
                fkc.position,
                fkc.column_name,
                fkc.referenced_table,
                fkc.referenced_column
         FROM foreign_key_snapshots AS fks
         LEFT JOIN foreign_key_columns AS fkc
           ON fkc.foreign_key_id = fks.id
         WHERE fks.table_snapshot_id = ?
         ORDER BY fks.constraint_name, fkc.position'
    );

    $stmt->bind_param('i', $tableSnapshotId);
    $stmt->execute();
    $stmt->bind_result(
        $constraintName,
        $updateRule,
        $deleteRule,
        $position,
        $columnName,
        $referencedTable,
        $referencedColumn
    );

    $foreignKeys = [];

    while ($stmt->fetch()) {
        if (!isset($foreignKeys[$constraintName])) {
            $foreignKeys[$constraintName] = [
                'updateRule' => $updateRule,
                'deleteRule' => $deleteRule,
                'columns' => [],
            ];
        }

        if ($columnName !== null) {
            $foreignKeys[$constraintName]['columns'][] = [
                'column' => $columnName,
                'referencedTable' => $referencedTable,
                'referencedColumn' => $referencedColumn,
                'position' => (int) $position,
            ];
        }
    }

    $stmt->close();

    foreach ($foreignKeys as &$definition) {
        usort(
            $definition['columns'],
            static function (array $a, array $b): int {
                return $a['position'] <=> $b['position'];
            }
        );

        $definition['columns'] = array_map(
            static function (array $column): array {
                return [
                    'column' => $column['column'],
                    'referencedTable' => $column['referencedTable'],
                    'referencedColumn' => $column['referencedColumn'],
                ];
            },
            $definition['columns']
        );
    }
    unset($definition);

    ksort($foreignKeys, SORT_NATURAL | SORT_FLAG_CASE);

    return $foreignKeys;
}

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
         SET status = "completed", completed_at = NOW()
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

function compareColumnDefinitions(array $columnDb1, array $columnDb2): array
{
    $attributes = ['Type', 'Collation', 'Null', 'Key', 'Default', 'Extra', 'Comment'];
    $differences = [];

    foreach ($attributes as $attribute) {
        $valueDb1 = $columnDb1[$attribute] ?? null;
        $valueDb2 = $columnDb2[$attribute] ?? null;

        if ($valueDb1 !== $valueDb2) {
            $differences[$attribute] = [
                'db1' => $valueDb1,
                'db2' => $valueDb2,
            ];
        }
    }

    return $differences;
}

function compareTableMetadata(?array $tableDb1, ?array $tableDb2): array
{
    $attributes = ['Engine', 'Collation'];
    $differences = [];

    foreach ($attributes as $attribute) {
        $valueDb1 = $tableDb1[$attribute] ?? null;
        $valueDb2 = $tableDb2[$attribute] ?? null;

        if ($valueDb1 !== $valueDb2) {
            $differences[$attribute] = [
                'db1' => $valueDb1,
                'db2' => $valueDb2,
            ];
        }
    }

    return $differences;
}

function normalizeForeignKeyDefinition(array $foreignKey): array
{
    $columns = array_map(
        static function (array $column): array {
            return [
                'column' => $column['column'] ?? '',
                'referencedTable' => $column['referencedTable'] ?? '',
                'referencedColumn' => $column['referencedColumn'] ?? '',
            ];
        },
        $foreignKey['columns'] ?? []
    );

    return [
        'columns' => array_values($columns),
        'updateRule' => $foreignKey['updateRule'] ?? '',
        'deleteRule' => $foreignKey['deleteRule'] ?? '',
    ];
}

function compareForeignKeys(array $foreignKeysDb1, array $foreignKeysDb2): array
{
    $onlyInDb1 = [];
    foreach (array_diff_key($foreignKeysDb1, $foreignKeysDb2) as $name => $definition) {
        $onlyInDb1[$name] = $definition;
    }

    $onlyInDb2 = [];
    foreach (array_diff_key($foreignKeysDb2, $foreignKeysDb1) as $name => $definition) {
        $onlyInDb2[$name] = $definition;
    }

    $modified = [];
    $sharedNames = array_intersect(array_keys($foreignKeysDb1), array_keys($foreignKeysDb2));

    foreach ($sharedNames as $name) {
        $normalizedDb1 = normalizeForeignKeyDefinition($foreignKeysDb1[$name]);
        $normalizedDb2 = normalizeForeignKeyDefinition($foreignKeysDb2[$name]);

        if ($normalizedDb1 !== $normalizedDb2) {
            $modified[$name] = [
                'db1' => $foreignKeysDb1[$name],
                'db2' => $foreignKeysDb2[$name],
            ];
        }
    }

    ksort($onlyInDb1, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($onlyInDb2, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($modified, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'onlyInDb1' => $onlyInDb1,
        'onlyInDb2' => $onlyInDb2,
        'modified' => $modified,
    ];
}

function createConnection(array $config): mysqli
{
    $connection = new mysqli(
        $config['host'],
        $config['username'],
        $config['password'],
        $config['database']
    );

    $connection->set_charset('utf8mb4');

    return $connection;
}

