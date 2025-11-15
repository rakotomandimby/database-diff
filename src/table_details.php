<?php

declare(strict_types=1);

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

