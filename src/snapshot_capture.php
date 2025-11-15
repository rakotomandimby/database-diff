<?php

declare(strict_types=1);

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
    $pattern = strtr(
        $tableName,
        [
            '\\' => '\\\\',
            '_' => '\\_',
            '%' => '\\%',
        ]
    );
    $escapedPattern = $connection->real_escape_string($pattern);
    $query = sprintf("SHOW TABLE STATUS LIKE '%s'", $escapedPattern);

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

