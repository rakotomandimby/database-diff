<?php

declare(strict_types=1);

function buildTableComparison(array $db1Config, mysqli $db1Connection, array $db2Config, mysqli $db2Connection): array
{
    $db1Label = $db1Config['label'] ?? 'Database 1';
    $db2Label = $db2Config['label'] ?? 'Database 2';

    $tablesDb1 = getTables($db1Connection);
    $tablesDb2 = getTables($db2Connection);

    $columnsDb1 = getColumnsForTables($db1Connection, $tablesDb1);
    $columnsDb2 = getColumnsForTables($db2Connection, $tablesDb2);

    $tableMetadataDb1 = getTableMetadata($db1Connection, $tablesDb1);
    $tableMetadataDb2 = getTableMetadata($db2Connection, $tablesDb2);

    $db1DatabaseName = $db1Config['database'] ?? '';
    $db2DatabaseName = $db2Config['database'] ?? '';

    $foreignKeysDb1 = getForeignKeys($db1Connection, $db1DatabaseName, $tablesDb1);
    $foreignKeysDb2 = getForeignKeys($db2Connection, $db2DatabaseName, $tablesDb2);

    $onlyInDb1 = array_values(array_diff($tablesDb1, $tablesDb2));
    $onlyInDb2 = array_values(array_diff($tablesDb2, $tablesDb1));

    $allTables = array_values(array_unique(array_merge($tablesDb1, $tablesDb2)));
    sort($allTables, SORT_NATURAL | SORT_FLAG_CASE);

    $tableDetails = [];

    foreach ($allTables as $tableName) {
        $columnsInDb1 = $columnsDb1[$tableName] ?? [];
        $columnsInDb2 = $columnsDb2[$tableName] ?? [];

        $onlyColumnsDb1 = array_values(array_diff(array_keys($columnsInDb1), array_keys($columnsInDb2)));
        $onlyColumnsDb2 = array_values(array_diff(array_keys($columnsInDb2), array_keys($columnsInDb1)));

        $sharedColumns = array_intersect(array_keys($columnsInDb1), array_keys($columnsInDb2));
        $columnDifferences = [];

        foreach ($sharedColumns as $columnName) {
            $differences = compareColumnDefinitions($columnsInDb1[$columnName], $columnsInDb2[$columnName]);

            if ($differences !== []) {
                $columnDifferences[$columnName] = $differences;
            }
        }

        $tableMetaDb1 = $tableMetadataDb1[$tableName] ?? null;
        $tableMetaDb2 = $tableMetadataDb2[$tableName] ?? null;
        $tableMetadataDifferences = compareTableMetadata($tableMetaDb1, $tableMetaDb2);

        $foreignKeysForDb1 = $foreignKeysDb1[$tableName] ?? [];
        $foreignKeysForDb2 = $foreignKeysDb2[$tableName] ?? [];
        $foreignKeyComparison = compareForeignKeys($foreignKeysForDb1, $foreignKeysForDb2);
        $foreignKeysOnlyDb1 = $foreignKeyComparison['onlyInDb1'];
        $foreignKeysOnlyDb2 = $foreignKeyComparison['onlyInDb2'];
        $foreignKeysModified = $foreignKeyComparison['modified'];

        $inDb1 = array_key_exists($tableName, $columnsDb1);
        $inDb2 = array_key_exists($tableName, $columnsDb2);
        $hasDifferences = !$inDb1
            || !$inDb2
            || $onlyColumnsDb1 !== []
            || $onlyColumnsDb2 !== []
            || $columnDifferences !== []
            || $tableMetadataDifferences !== []
            || $foreignKeysOnlyDb1 !== []
            || $foreignKeysOnlyDb2 !== []
            || $foreignKeysModified !== [];

        $tableDetails[$tableName] = [
            'inDb1' => $inDb1,
            'inDb2' => $inDb2,
            'columnsDb1' => $columnsInDb1,
            'columnsDb2' => $columnsInDb2,
            'onlyColumnsDb1' => $onlyColumnsDb1,
            'onlyColumnsDb2' => $onlyColumnsDb2,
            'columnDifferences' => $columnDifferences,
            'tableMetaDb1' => $tableMetaDb1,
            'tableMetaDb2' => $tableMetaDb2,
            'tableMetadataDifferences' => $tableMetadataDifferences,
            'foreignKeysDb1' => $foreignKeysForDb1,
            'foreignKeysDb2' => $foreignKeysForDb2,
            'foreignKeysOnlyDb1' => $foreignKeysOnlyDb1,
            'foreignKeysOnlyDb2' => $foreignKeysOnlyDb2,
            'foreignKeysModified' => $foreignKeysModified,
            'hasDifferences' => $hasDifferences,
        ];
    }

    return [
        'db1Label' => $db1Label,
        'db2Label' => $db2Label,
        'tablesDb1' => $tablesDb1,
        'tablesDb2' => $tablesDb2,
        'onlyInDb1' => $onlyInDb1,
        'onlyInDb2' => $onlyInDb2,
        'tableDetails' => $tableDetails,
    ];
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

function getTableMetadata(mysqli $connection, array $tables): array
{
    if ($tables === []) {
        return [];
    }

    $lookup = array_fill_keys($tables, true);
    $metadata = [];
    $result = $connection->query('SHOW TABLE STATUS');

    while ($row = $result->fetch_assoc()) {
        $name = $row['Name'] ?? null;

        if ($name === null || !isset($lookup[$name])) {
            continue;
        }

        $metadata[$name] = [
            'Engine' => $row['Engine'] ?? null,
            'Collation' => $row['Collation'] ?? null,
        ];
    }

    $result->free();

    return $metadata;
}

function getColumnsForTables(mysqli $connection, array $tables): array
{
    if ($tables === []) {
        return [];
    }

    $columnsByTable = [];

    foreach ($tables as $table) {
        $escapedTable = sprintf('`%s`', str_replace('`', '``', $table));
        $result = $connection->query('SHOW FULL COLUMNS FROM ' . $escapedTable);

        $columns = [];

        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = [
                'Type' => $row['Type'],
                'Collation' => $row['Collation'],
                'Null' => $row['Null'],
                'Key' => $row['Key'],
                'Default' => $row['Default'],
                'Extra' => $row['Extra'],
                'Comment' => $row['Comment'],
            ];
        }

        $result->free();
        $columnsByTable[$table] = $columns;
    }

    return $columnsByTable;
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

function getForeignKeys(mysqli $connection, string $database, array $tables): array
{
    if ($database === '' || $tables === []) {
        return [];
    }

    $foreignKeysByTable = [];
    $tableLookup = array_fill_keys($tables, true);

    $stmt = $connection->prepare(
        'SELECT kcu.TABLE_NAME,
                kcu.CONSTRAINT_NAME,
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
           AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION'
    );

    if ($stmt === false) {
        return [];
    }

    $stmt->bind_param('s', $database);
    $stmt->execute();
    $stmt->bind_result(
        $tableName,
        $constraintName,
        $ordinalPosition,
        $columnName,
        $referencedTable,
        $referencedColumn,
        $updateRule,
        $deleteRule
    );

    while ($stmt->fetch()) {
        if (!isset($tableLookup[$tableName])) {
            continue;
        }

        if (!isset($foreignKeysByTable[$tableName][$constraintName])) {
            $foreignKeysByTable[$tableName][$constraintName] = [
                'updateRule' => $updateRule,
                'deleteRule' => $deleteRule,
                'columns' => [],
            ];
        }

        $foreignKeysByTable[$tableName][$constraintName]['columns'][] = [
            'position' => (int) $ordinalPosition,
            'column' => $columnName,
            'referencedTable' => $referencedTable,
            'referencedColumn' => $referencedColumn,
        ];
    }

    $stmt->close();

    foreach ($foreignKeysByTable as $tableName => &$constraints) {
        ksort($constraints, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($constraints as $constraintName => &$definition) {
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
    }
    unset($constraints, $definition);

    return $foreignKeysByTable;
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

