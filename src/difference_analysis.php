<?php

declare(strict_types=1);

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

