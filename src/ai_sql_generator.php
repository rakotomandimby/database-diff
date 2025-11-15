<?php

declare(strict_types=1);

const SQL_GENERATOR_MODEL = 'claude-sonnet-4-5';

function generateSqlStatementsForTable(
  string $apiKey,
  string $tableName,
  array $tableDetail,
  string $db1Label,
  string $db2Label,
  string $fullContext
): string {
  $url = 'https://api.anthropic.com/v1/messages';

  $prompt = buildPromptForTable($tableName, $tableDetail, $db1Label, $db2Label, $fullContext);

  $payload = [
    'model' => SQL_GENERATOR_MODEL,
    'max_tokens' => 2048,
    'system' => 'You are a helpful assistant that generates SQL statements for MySQL database schema migration based on provided context and instructions.',
    'messages' => [
      [
        'role' => 'user',
        'content' => $prompt,
      ],
    ],
  ];

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey,
    'anthropic-version: 2023-06-01',
    'anthropic-beta: context-1m-2025-08-07',
  ]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  $response = curl_exec($ch);
  $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = $response === false ? curl_error($ch) : null;
  curl_close($ch);

  if ($response === false) {
    return '-- Error: Unable to generate SQL (cURL error: ' . ($curlError ?: 'unknown') . ')';
  }

  if ($httpCode !== 200) {
    return "-- Error: Unable to generate SQL (HTTP $httpCode)\n-- Response: " . substr($response, 0, 200);
  }

  $data = json_decode($response, true);

  if (!is_array($data)) {
    return '-- Error: Unable to parse API response';
  }

  if (isset($data['content'][0]['text'])) {
    return extractSqlFromResponse($data['content'][0]['text']);
  }

  return '-- Error: Unexpected API response format';
}

function buildPromptForTable(
  string $tableName,
  array $tableDetail,
  string $db1Label,
  string $db2Label,
  string $fullContext
): string {
  $inDb1 = $tableDetail['inDb1'];
  $inDb2 = $tableDetail['inDb2'];

  $prompt = "Generate the exact SQL statements needed to make the table `$tableName` in $db2Label match the structure of the same table in $db1Label.\n\n";
  $prompt .= "# Full Database Context\n\n";
  $prompt .= "$fullContext\n\n";
  $prompt .= "# Specific Table: `$tableName`\n\n";

  if (!$inDb2 && $inDb1) {
    $prompt .= "**Scenario:** Table exists in $db1Label but NOT in $db2Label. You need to CREATE the table.\n\n";
    $prompt .= "## $db1Label Structure:\n";
    $prompt .= formatTableStructure($tableDetail, 'db1');
  } elseif ($inDb2 && !$inDb1) {
    $prompt .= "**Scenario:** Table exists in $db2Label but NOT in $db1Label. You need to DROP the table.\n\n";
    $prompt .= "## $db2Label Structure:\n";
    $prompt .= formatTableStructure($tableDetail, 'db2');
  } else {
    $prompt .= "**Scenario:** Table exists in both databases but may have differences. Generate ALTER statements to sync $db2Label to match $db1Label.\n\n";
    $prompt .= "## $db1Label Structure:\n";
    $prompt .= formatTableStructure($tableDetail, 'db1');
    $prompt .= "\n## $db2Label Structure:\n";
    $prompt .= formatTableStructure($tableDetail, 'db2');

    if ($tableDetail['onlyColumnsDb1'] !== []) {
      $prompt .= "\n**Columns only in $db1Label:** " . implode(', ', $tableDetail['onlyColumnsDb1']) . "\n";
    }

    if ($tableDetail['onlyColumnsDb2'] !== []) {
      $prompt .= "\n**Columns only in $db2Label:** " . implode(', ', $tableDetail['onlyColumnsDb2']) . "\n";
    }

    if ($tableDetail['columnDifferences'] !== []) {
      $prompt .= "\n**Column differences:**\n";
      foreach ($tableDetail['columnDifferences'] as $colName => $diffs) {
        $prompt .= "- `$colName`: " . json_encode($diffs, JSON_PRETTY_PRINT) . "\n";
      }
    }

    if ($tableDetail['tableMetadataDifferences'] !== []) {
      $prompt .= "\n**Table metadata differences:** " . json_encode($tableDetail['tableMetadataDifferences'], JSON_PRETTY_PRINT) . "\n";
    }

    if ($tableDetail['foreignKeysOnlyDb1'] !== []) {
      $prompt .= "\n**Foreign keys only in $db1Label:** " . json_encode($tableDetail['foreignKeysOnlyDb1'], JSON_PRETTY_PRINT) . "\n";
    }

    if ($tableDetail['foreignKeysOnlyDb2'] !== []) {
      $prompt .= "\n**Foreign keys only in $db2Label:** " . json_encode($tableDetail['foreignKeysOnlyDb2'], JSON_PRETTY_PRINT) . "\n";
    }

    if ($tableDetail['foreignKeysModified'] !== []) {
      $prompt .= "\n**Modified foreign keys:** " . json_encode($tableDetail['foreignKeysModified'], JSON_PRETTY_PRINT) . "\n";
    }
  }

  $prompt .= "\n# Instructions\n\n";
  $prompt .= "1. Generate ONLY the SQL statements needed to transform the $db2Label table to match $db1Label\n";
  $prompt .= "2. Handle column additions, deletions, and modifications\n";
  $prompt .= "3. Handle table metadata (ENGINE, COLLATION, etc)\n";
  $prompt .= "4. Handle foreign key constraints (drop and recreate if needed)\n";
  $prompt .= "5. Handle indexes and primary keys\n";
  $prompt .= "6. Be careful with the order: drop foreign keys before altering columns, recreate them after\n";
  $prompt .= "7. Use proper MySQL syntax\n";
  $prompt .= "8. If no changes are needed, return: -- No changes needed\n";
  $prompt .= "9. Return ONLY executable SQL statements, no explanatory text outside of SQL comments\n";
  $prompt .= "10. Each statement should end with a semicolon\n\n";
  $prompt .= "Return the SQL statements now:";

  return $prompt;
}

function formatTableStructure(array $tableDetail, string $dbKey): string
{
  $output = '';

  $columns = $dbKey === 'db1' ? $tableDetail['columnsDb1'] : $tableDetail['columnsDb2'];
  $tableMeta = $dbKey === 'db1' ? $tableDetail['tableMetaDb1'] : $tableDetail['tableMetaDb2'];
  $foreignKeys = $dbKey === 'db1' ? $tableDetail['foreignKeysDb1'] : $tableDetail['foreignKeysDb2'];

  if ($columns !== []) {
    $output .= "### Columns:\n";
    foreach ($columns as $colName => $colDef) {
      $output .= "- `$colName`: " . json_encode($colDef, JSON_PRETTY_PRINT) . "\n";
    }
  }

  if ($tableMeta !== null) {
    $output .= "\n### Table Metadata:\n";
    $output .= json_encode($tableMeta, JSON_PRETTY_PRINT) . "\n";
  }

  if ($foreignKeys !== []) {
    $output .= "\n### Foreign Keys:\n";
    $output .= json_encode($foreignKeys, JSON_PRETTY_PRINT) . "\n";
  }

  return $output;
}

function extractSqlFromResponse(string $response): string
{
  $response = preg_replace('/```sql\s*/i', '', $response);
  $response = preg_replace('/```\s*$/', '', $response);
  $response = trim($response);

  return $response;
}

function buildFullDatabaseContext(array $comparison, mysqli $storageConnection, int $runId): string
{
  $context = "# Database Comparison Overview\n\n";
  $context .= "**" . $comparison['db1Label'] . " Tables:** " . count($comparison['tablesDb1']) . "\n";
  $context .= "**" . $comparison['db2Label'] . " Tables:** " . count($comparison['tablesDb2']) . "\n";
  $context .= "**Tables only in " . $comparison['db1Label'] . ":** " . implode(', ', $comparison['onlyInDb1']) . "\n";
  $context .= "**Tables only in " . $comparison['db2Label'] . ":** " . implode(', ', $comparison['onlyInDb2']) . "\n\n";

  $context .= "# All Tables Structure\n\n";

  $allTables = getAllTablesForRun($storageConnection, $runId);

  foreach ($allTables as $tableName) {
    $context .= "## Table: `$tableName`\n\n";

    $tableDetail = $comparison['tableDetails'][$tableName] ?? buildTableDetailFromStorage(
      $storageConnection,
      $runId,
      $tableName,
      false
    );

    if ($tableDetail['inDb1']) {
      $context .= "### In " . $comparison['db1Label'] . ":\n";
      $context .= formatTableStructureForContext($tableDetail, 'db1');
    } else {
      $context .= "### In " . $comparison['db1Label'] . ":\n";
      $context .= "*Table not present*\n";
    }

    if ($tableDetail['inDb2']) {
      $context .= "\n### In " . $comparison['db2Label'] . ":\n";
      $context .= formatTableStructureForContext($tableDetail, 'db2');
    } else {
      $context .= "\n### In " . $comparison['db2Label'] . ":\n";
      $context .= "*Table not present*\n";
    }

    $context .= "\n---\n\n";
  }

  return $context;
}

function formatTableStructureForContext(array $tableDetail, string $dbKey): string
{
  $output = '';

  $columns = $dbKey === 'db1' ? $tableDetail['columnsDb1'] : $tableDetail['columnsDb2'];
  $tableMeta = $dbKey === 'db1' ? $tableDetail['tableMetaDb1'] : $tableDetail['tableMetaDb2'];
  $foreignKeys = $dbKey === 'db1' ? $tableDetail['foreignKeysDb1'] : $tableDetail['foreignKeysDb2'];

  if ($tableMeta !== null) {
    $output .= "**Engine:** " . ($tableMeta['Engine'] ?? 'N/A') . "\n";
    $output .= "**Collation:** " . ($tableMeta['Collation'] ?? 'N/A') . "\n";
  }

  if ($columns !== []) {
    $output .= "**Columns:**\n";
    foreach ($columns as $colName => $colDef) {
      $output .= "- `$colName`: Type={$colDef['Type']}, Null={$colDef['Null']}, Key={$colDef['Key']}, Default=" . ($colDef['Default'] ?? 'NULL') . ", Extra={$colDef['Extra']}\n";
    }
  }

  if ($foreignKeys !== []) {
    $output .= "**Foreign Keys:** " . count($foreignKeys) . " constraints\n";
  }

  return $output;
}

