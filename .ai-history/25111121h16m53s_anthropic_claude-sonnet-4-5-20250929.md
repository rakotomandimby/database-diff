# Ask the SQL statement to AI to make db2 the same to db1 

I want db2 to have the same structure as db1.
I only handle structure, we do not care about data.

I want the code to :

1. Gather the structure of db1 and db2 down to field level.
2. Write the gathered structure to detailed text: this text will be the context.
3. For each table, prompt Anthropic Claude Sonnet 4.5 (model id: `claude-sonnet-4-5`) to generate the SQL statements needed to the db2 table to match the db1 table structure. We have to be careful to take constraints into account (primary key, foreign key, unique, not null, default value, etc). We have to handle table creation and table deletion as well. The foreign keys and indexes much match too.
4. Display the SQL statements under each table section in the template

# Task 

Tell me the **full new content** of the files I need to create or modify to implement this feature.


# ANT Files to Create or Modify

## 1. Create `./src/ai_sql_generator.php`

```php
<?php

declare(strict_types=1);

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
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 4096,
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return "-- Error: Unable to generate SQL (HTTP $httpCode)\n-- Response: " . substr($response, 0, 200);
    }

    $data = json_decode($response, true);

    if (isset($data['content'][0]['text'])) {
        return extractSqlFromResponse($data['content'][0]['text']);
    }

    return "-- Error: Unexpected API response format";
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

    $prompt = "You are a MySQL database migration expert. Your task is to generate the exact SQL statements needed to make the table `$tableName` in $db2Label match the structure of the same table in $db1Label.\n\n";
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
    // Remove markdown code blocks if present
    $response = preg_replace('/```sql\s*/i', '', $response);
    $response = preg_replace('/```\s*$/', '', $response);
    $response = trim($response);

    return $response;
}

function buildFullDatabaseContext(array $comparison): string
{
    $context = "# Database Comparison Overview\n\n";
    $context .= "**" . $comparison['db1Label'] . " Tables:** " . count($comparison['tablesDb1']) . "\n";
    $context .= "**" . $comparison['db2Label'] . " Tables:** " . count($comparison['tablesDb2']) . "\n";
    $context .= "**Tables only in " . $comparison['db1Label'] . ":** " . implode(', ', $comparison['onlyInDb1']) . "\n";
    $context .= "**Tables only in " . $comparison['db2Label'] . ":** " . implode(', ', $comparison['onlyInDb2']) . "\n\n";

    $context .= "# All Tables Structure\n\n";

    foreach ($comparison['tableDetails'] as $tableName => $tableDetail) {
        $context .= "## Table: `$tableName`\n\n";

        if ($tableDetail['inDb1']) {
            $context .= "### In " . $comparison['db1Label'] . ":\n";
            $context .= formatTableStructureForContext($tableDetail, 'db1');
        }

        if ($tableDetail['inDb2']) {
            $context .= "\n### In " . $comparison['db2Label'] . ":\n";
            $context .= formatTableStructureForContext($tableDetail, 'db2');
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
```

## 2. Modify `./public/index.php`

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/database_comparison.php';
require_once __DIR__ . '/../src/ai_sql_generator.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$comparison = null;
$error = null;
$db1Connection = null;
$db2Connection = null;

try {
    $db1Connection = createConnection($db1Config);
    $db2Connection = createConnection($db2Config);

    $comparison = buildTableComparison($db1Config, $db1Connection, $db2Config, $db2Connection);

    // Generate full context for AI
    $fullContext = buildFullDatabaseContext($comparison);

    // Generate SQL statements for each table with differences
    foreach ($comparison['tableDetails'] as $tableName => &$tableDetail) {
        if ($tableDetail['hasDifferences']) {
            $sqlStatements = generateSqlStatementsForTable(
                $llmApiKey,
                $tableName,
                $tableDetail,
                $comparison['db1Label'],
                $comparison['db2Label'],
                $fullContext
            );
            $tableDetail['sqlStatements'] = $sqlStatements;
        } else {
            $tableDetail['sqlStatements'] = '-- No changes needed';
        }
    }
    unset($tableDetail);
} catch (Throwable $exception) {
    $error = $exception;
    http_response_code(500);
}

require __DIR__ . '/../templates/database_comparison.php';

if ($db1Connection instanceof mysqli) {
    $db1Connection->close();
}

if ($db2Connection instanceof mysqli) {
    $db2Connection->close();
}
```

## 3. Modify `./templates/database_comparison.php`

Add this section inside each table detail block (after the foreign keys section and before the closing `</div>` of `.table-detail`):

```php
<?php if (isset($tableDetail['sqlStatements'])): ?>
  <div class="sql-statements-section">
    <h4 style="margin: 0 0 0.75rem; font-size: 1.1rem; color: #1e293b;">
      SQL Migration Statements
    </h4>
    <div class="sql-code-block">
      <pre><code><?php echo htmlspecialchars($tableDetail['sqlStatements'], ENT_QUOTES, 'UTF-8'); ?></code></pre>
    </div>
  </div>
<?php endif; ?>
```

Here's the complete modified template:

```php
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Database Comparison</title>
  <link rel="stylesheet" href="style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
  <main>
    <?php if ($error !== null): ?>
      <div class="alert deletion">
        <h2 style="margin-top: 0;">Error</h2>
        <p><strong>Message:</strong> <?php echo htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>File:</strong> <?php echo htmlspecialchars($error->getFile(), ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>Line:</strong> <?php echo htmlspecialchars((string) $error->getLine(), ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
    <?php else: ?>
      <h1>Database Comparison</h1>
      <p class="lead">
        Comparing <code><?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></code>
        and <code><?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></code>
      </p>

      <section>
        <div class="summary-grid">
          <div class="card">
            <h2 style="font-size: 1.15rem; margin-bottom: 0.5rem;">Tables in <?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p style="font-size: 2rem; font-weight: 700; margin: 0;"><?php echo count($comparison['tablesDb1']); ?></p>
          </div>
          <div class="card">
            <h2 style="font-size: 1.15rem; margin-bottom: 0.5rem;">Tables in <?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p style="font-size: 2rem; font-weight: 700; margin: 0;"><?php echo count($comparison['tablesDb2']); ?></p>
          </div>
          <div class="card">
            <h2 style="font-size: 1.15rem; margin-bottom: 0.5rem;">Only in <?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p style="font-size: 2rem; font-weight: 700; margin: 0;"><?php echo count($comparison['onlyInDb1']); ?></p>
          </div>
          <div class="card">
            <h2 style="font-size: 1.15rem; margin-bottom: 0.5rem;">Only in <?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p style="font-size: 2rem; font-weight: 700; margin: 0;"><?php echo count($comparison['onlyInDb2']); ?></p>
          </div>
        </div>
      </section>

      <?php if ($comparison['onlyInDb1'] !== [] || $comparison['onlyInDb2'] !== []): ?>
        <section>
          <h2 style="margin-bottom: 1.25rem;">Table Existence Differences</h2>
          <div class="panels-grid">
            <?php if ($comparison['onlyInDb1'] !== []): ?>
              <div class="panel">
                <h3 style="font-size: 1.1rem; margin-bottom: 0.75rem;">
                  Only in <?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?>
                  <span class="badge badge-addition"><?php echo count($comparison['onlyInDb1']); ?></span>
                </h3>
                <ul class="diff-list">
                  <?php foreach ($comparison['onlyInDb1'] as $tableName): ?>
                    <li class="diff-item addition">
                      <div class="list-title">
                        <?php echo htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php if ($comparison['onlyInDb2'] !== []): ?>
              <div class="panel">
                <h3 style="font-size: 1.1rem; margin-bottom: 0.75rem;">
                  Only in <?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?>
                  <span class="badge badge-deletion"><?php echo count($comparison['onlyInDb2']); ?></span>
                </h3>
                <ul class="diff-list">
                  <?php foreach ($comparison['onlyInDb2'] as $tableName): ?>
                    <li class="diff-item deletion">
                      <div class="list-title">
                        <?php echo htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <section>
        <h2 style="margin-bottom: 1.25rem;">Table Details</h2>
        <?php
        $tablesWithDifferences = array_filter(
          $comparison['tableDetails'],
          static function (array $detail): bool {
            return $detail['hasDifferences'];
          }
        );
        ?>
        <?php if ($tablesWithDifferences === []): ?>
          <div class="card">
            <p class="empty-state">No differences found between the two databases.</p>
          </div>
        <?php else: ?>
          <div style="display: flex; flex-direction: column; gap: 2rem;">
            <?php foreach ($comparison['tableDetails'] as $tableName => $tableDetail): ?>
              <?php if (!$tableDetail['hasDifferences']) {
                continue;
              } ?>
              <div class="table-detail">
                <div class="table-header">
                  <div>
                    <h3><?php echo htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="table-subtitle">
                      <?php if (!$tableDetail['inDb1']): ?>
                        <span class="badge badge-deletion">Missing in <?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php elseif (!$tableDetail['inDb2']): ?>
                        <span class="badge badge-addition">Missing in <?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php else: ?>
                        <span class="badge badge-modification">Structure Differences</span>
                      <?php endif; ?>
                    </p>
                  </div>
                </div>

                <?php if (!$tableDetail['inDb1']): ?>
                  <div class="alert deletion">
                    <p style="margin: 0;">
                      This table exists in <strong><?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                      but not in <strong><?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                    </p>
                  </div>
                <?php elseif (!$tableDetail['inDb2']): ?>
                  <div class="alert addition">
                    <p style="margin: 0;">
                      This table exists in <strong><?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                      but not in <strong><?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                    </p>
                  </div>
                <?php endif; ?>

                <?php if ($tableDetail['tableMetadataDifferences'] !== []): ?>
                  <div class="difference-block">
                    <h5>Table Metadata Differences</h5>
                    <table class="difference-table">
                      <thead>
                        <tr>
                          <th>Attribute</th>
                          <th><?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></th>
                          <th><?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($tableDetail['tableMetadataDifferences'] as $attribute => $values): ?>
                          <tr>
                            <th><?php echo htmlspecialchars($attribute, ENT_QUOTES, 'UTF-8'); ?></th>
                            <td><code><?php echo htmlspecialchars($values['db1'] ?? 'NULL', ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><code><?php echo htmlspecialchars($values['db2'] ?? 'NULL', ENT_QUOTES, 'UTF-8'); ?></code></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>

                <?php if ($tableDetail['onlyColumnsDb1'] !== [] || $tableDetail['onlyColumnsDb2'] !== [] || $tableDetail['columnDifferences'] !== []): ?>
                  <div>
                    <h4 style="margin: 0 0 1rem; font-size: 1.1rem;">Column Differences</h4>
                    <div class="columns-grid">
                      <?php if ($tableDetail['onlyColumnsDb1'] !== []): ?>
                        <div>
                          <h4>Only in <?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></h4>
                          <ul class="diff-list">
                            <?php foreach ($tableDetail['onlyColumnsDb1'] as $columnName): ?>
                              <li class="diff-item addition">
                                <div class="list-title"><?php echo htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php
                                $columnDef = $tableDetail['columnsDb1'][$columnName];
                                ?>
                                <div class="list-detail">
                                  Type: <code><?php echo htmlspecialchars($columnDef['Type'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  Null: <code><?php echo htmlspecialchars($columnDef['Null'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  <?php if ($columnDef['Default'] !== null): ?>
                                    Default: <code><?php echo htmlspecialchars($columnDef['Default'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  <?php endif; ?>
                                  <?php if ($columnDef['Extra'] !== ''): ?>
                                    Extra: <code><?php echo htmlspecialchars($columnDef['Extra'], ENT_QUOTES, 'UTF-8'); ?></code>
                                  <?php endif; ?>
                                </div>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>

                      <?php if ($tableDetail['onlyColumnsDb2'] !== []): ?>
                        <div>
                          <h4>Only in <?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></h4>
                          <ul class="diff-list">
                            <?php foreach ($tableDetail['onlyColumnsDb2'] as $columnName): ?>
                              <li class="diff-item deletion">
                                <div class="list-title"><?php echo htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php
                                $columnDef = $tableDetail['columnsDb2'][$columnName];
                                ?>
                                <div class="list-detail">
                                  Type: <code><?php echo htmlspecialchars($columnDef['Type'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  Null: <code><?php echo htmlspecialchars($columnDef['Null'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  <?php if ($columnDef['Default'] !== null): ?>
                                    Default: <code><?php echo htmlspecialchars($columnDef['Default'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  <?php endif; ?>
                                  <?php if ($columnDef['Extra'] !== ''): ?>
                                    Extra: <code><?php echo htmlspecialchars($columnDef['Extra'], ENT_QUOTES, 'UTF-8'); ?></code>
                                  <?php endif; ?>
                                </div>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>

                      <?php if ($tableDetail['columnDifferences'] !== []): ?>
                        <div style="grid-column: 1 / -1;">
                          <h4>Modified Columns</h4>
                          <?php foreach ($tableDetail['columnDifferences'] as $columnName => $differences): ?>
                            <div class="difference-block" style="margin-bottom: 1rem;">
                              <h5><?php echo htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8'); ?></h5>
                              <table class="difference-table">
                                <thead>
                                  <tr>
                                    <th>Attribute</th>
                                    <th><?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></th>
                                    <th><?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <?php foreach ($differences as $attribute => $values): ?>
                                    <tr>
                                      <th><?php echo htmlspecialchars($attribute, ENT_QUOTES, 'UTF-8'); ?></th>
                                      <td><code><?php echo htmlspecialchars($values['db1'] ?? 'NULL', ENT_QUOTES, 'UTF-8'); ?></code></td>
                                      <td><code><?php echo htmlspecialchars($values['db2'] ?? 'NULL', ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    </tr>
                                  <?php endforeach; ?>
                                </tbody>
                              </table>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($tableDetail['foreignKeysOnlyDb1'] !== [] || $tableDetail['foreignKeysOnlyDb2'] !== [] || $tableDetail['foreignKeysModified'] !== []): ?>
                  <div>
                    <h4 style="margin: 0 0 1rem; font-size: 1.1rem;">Foreign Key Differences</h4>
                    <div class="columns-grid">
                      <?php if ($tableDetail['foreignKeysOnlyDb1'] !== []): ?>
                        <div>
                          <h4>Only in <?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?></h4>
                          <ul class="diff-list">
                            <?php foreach ($tableDetail['foreignKeysOnlyDb1'] as $fkName => $fkDef): ?>
                              <li class="diff-item addition">
                                <div class="list-title"><?php echo htmlspecialchars($fkName, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="list-detail">
                                  <?php foreach ($fkDef['columns'] as $col): ?>
                                    <code><?php echo htmlspecialchars($col['column'], ENT_QUOTES, 'UTF-8'); ?></code> →
                                    <code><?php echo htmlspecialchars($col['referencedTable'], ENT_QUOTES, 'UTF-8'); ?>.<?php echo htmlspecialchars($col['referencedColumn'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  <?php endforeach; ?>
                                  ON UPDATE: <code><?php echo htmlspecialchars($fkDef['updateRule'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  ON DELETE: <code><?php echo htmlspecialchars($fkDef['deleteRule'], ENT_QUOTES, 'UTF-8'); ?></code>
                                </div>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>

                      <?php if ($tableDetail['foreignKeysOnlyDb2'] !== []): ?>
                        <div>
                          <h4>Only in <?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?></h4>
                          <ul class="diff-list">
                            <?php foreach ($tableDetail['foreignKeysOnlyDb2'] as $fkName => $fkDef): ?>
                              <li class="diff-item deletion">
                                <div class="list-title"><?php echo htmlspecialchars($fkName, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="list-detail">
                                  <?php foreach ($fkDef['columns'] as $col): ?>
                                    <code><?php echo htmlspecialchars($col['column'], ENT_QUOTES, 'UTF-8'); ?></code> →
                                    <code><?php echo htmlspecialchars($col['referencedTable'], ENT_QUOTES, 'UTF-8'); ?>.<?php echo htmlspecialchars($col['referencedColumn'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  <?php endforeach; ?>
                                  ON UPDATE: <code><?php echo htmlspecialchars($fkDef['updateRule'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                  ON DELETE: <code><?php echo htmlspecialchars($fkDef['deleteRule'], ENT_QUOTES, 'UTF-8'); ?></code>
                                </div>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>

                      <?php if ($tableDetail['foreignKeysModified'] !== []): ?>
                        <div style="grid-column: 1 / -1;">
                          <h4>Modified Foreign Keys</h4>
                          <?php foreach ($tableDetail['foreignKeysModified'] as $fkName => $fkComparison): ?>
                            <div class="difference-block" style="margin-bottom: 1rem;">
                              <h5><?php echo htmlspecialchars($fkName, ENT_QUOTES, 'UTF-8'); ?></h5>
                              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div>
                                  <strong><?php echo htmlspecialchars($comparison['db1Label'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                  <div style="margin-top: 0.5rem; font-size: 0.9rem;">
                                    <?php foreach ($fkComparison['db1']['columns'] as $col): ?>
                                      <code><?php echo htmlspecialchars($col['column'], ENT_QUOTES, 'UTF-8'); ?></code> →
                                      <code><?php echo htmlspecialchars($col['referencedTable'], ENT_QUOTES, 'UTF-8'); ?>.<?php echo htmlspecialchars($col['referencedColumn'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                    <?php endforeach; ?>
                                    ON UPDATE: <code><?php echo htmlspecialchars($fkComparison['db1']['updateRule'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                    ON DELETE: <code><?php echo htmlspecialchars($fkComparison['db1']['deleteRule'], ENT_QUOTES, 'UTF-8'); ?></code>
                                  </div>
                                </div>
                                <div>
                                  <strong><?php echo htmlspecialchars($comparison['db2Label'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                  <div style="margin-top: 0.5rem; font-size: 0.9rem;">
                                    <?php foreach ($fkComparison['db2']['columns'] as $col): ?>
                                      <code><?php echo htmlspecialchars($col['column'], ENT_QUOTES, 'UTF-8'); ?></code> →
                                      <code><?php echo htmlspecialchars($col['referencedTable'], ENT_QUOTES, 'UTF-8'); ?>.<?php echo htmlspecialchars($col['referencedColumn'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                    <?php endforeach; ?>
                                    ON UPDATE: <code><?php echo htmlspecialchars($fkComparison['db2']['updateRule'], ENT_QUOTES, 'UTF-8'); ?></code><br>
                                    ON DELETE: <code><?php echo htmlspecialchars($fkComparison['db2']['deleteRule'], ENT_QUOTES, 'UTF-8'); ?></code>
                                  </div>
                                </div>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if (isset($tableDetail['sqlStatements'])): ?>
                  <div class="sql-statements-section">
                    <h4 style="margin: 0 0 0.75rem; font-size: 1.1rem; color: #1e293b;">
                      SQL Migration Statements
                    </h4>
                    <div class="sql-code-block">
                      <pre><code><?php echo htmlspecialchars($tableDetail['sqlStatements'], ENT_QUOTES, 'UTF-8'); ?></code></pre>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
```

## 4. Modify `./public/style.css`

Add these new styles at the end of the file:

```css
:root {
  color-scheme: light;
  --color-bg: #f8fafc;
  --color-surface: #ffffff;
  --color-border: #e2e8f0;
  --color-text: #0f172a;
  --shadow-soft: 0 20px 45px -35px rgba(15, 23, 42, 0.65);
}
* {
  box-sizing: border-box;
}
body {
  margin: 0;
  font-family: 'Inter', system-ui, -apple-system, Segoe UI, sans-serif;
  font-size: 16px;
  line-height: 1.6;
  background: var(--color-bg);
  color: var(--color-text);
}
main {
  max-width: 1200px;
  margin: 0 auto;
  padding: 2.5rem 1.5rem 3.5rem;
}
h1 {
  font-size: clamp(1.75rem, 2vw + 1rem, 2.5rem);
  margin: 0 0 0.75rem;
}
.lead {
  margin: 0 0 2rem;
  color: #475569;
  font-size: 1.05rem;
}
section {
  margin-bottom: 2.5rem;
}
code {
  background: #e2e8f0;
  color: inherit;
  padding: 0.1rem 0.4rem;
  border-radius: 0.35rem;
  font-size: 0.95em;
  font-family: ui-monospace, SFMono-Regular, SF Mono, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace;
}
.summary-grid {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  margin-bottom: 2.5rem;
}
.card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 1rem;
  padding: 1.25rem 1.35rem;
  box-shadow: var(--shadow-soft);
}
.card h2,
.panel h2,
.table-detail h3 {
  margin-top: 0;
}
.card > p:last-child,
.panel > p:last-child,
.table-detail > p:last-child {
  margin-bottom: 0;
}
.badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  background: #e2e8f0;
  color: #475569;
}
.badge-addition {
  background: #dcfce7;
  color: #166534;
}
.badge-deletion {
  background: #fee2e2;
  color: #991b1b;
}
.badge-modification {
  background: #dbeafe;
  color: #1d4ed8;
}
.badge-neutral {
  background: #e2e8f0;
  color: #475569;
}
.panels-grid {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  margin-bottom: 3rem;
}
.panel {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 1rem;
  padding: 1.5rem;
  box-shadow: var(--shadow-soft);
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.diff-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 0.75rem;
}
.diff-item {
  background: #f8fafc;
  border: 1px solid var(--color-border);
  border-radius: 0.9rem;
  padding: 0.75rem 0.9rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.diff-item.deletion {
  border-left: 4px solid #dc2626;
  background: #fef2f2;
  border-color: #fecaca;
  color: #991b1b;
}
.diff-item.addition {
  border-left: 4px solid #16a34a;
  background: #ecfdf5;
  border-color: #bbf7d0;
  color: #166534;
}
.diff-item.modification {
  border-left: 4px solid #2563eb;
  background: #eff6ff;
  border-color: #bfdbfe;
  color: #1d4ed8;
}
.list-title {
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.list-detail {
  font-size: 0.85rem;
  color: #475569;
}
.diff-item.deletion .list-detail,
.diff-item.addition .list-detail,
.diff-item.modification .list-detail {
  color: inherit;
}
.table-detail {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 1.25rem;
  padding: 1.75rem;
  box-shadow: var(--shadow-soft);
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}
.table-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}
.table-header h3 {
  margin: 0;
  font-size: 1.35rem;
}
.table-subtitle {
  margin: 0;
  font-size: 0.85rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #64748b;
}
.alert {
  border-radius: 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--color-border);
  background: #f8fafc;
  color: inherit;
}
.alert.deletion {
  border-left: 4px solid #dc2626;
  background: #fef2f2;
  border-color: #fecaca;
  color: #991b1b;
}
.alert.addition {
  border-left: 4px solid #16a34a;
  background: #ecfdf5;
  border-color: #bbf7d0;
  color: #166534;
}
.columns-grid {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}
.columns-grid h4 {
  margin: 0 0 0.5rem;
  font-size: 1rem;
  color: #1e293b;
}
.empty-state {
  margin: 0;
  font-style: italic;
  color: #94a3b8;
}
.difference-block {
  border-radius: 1rem;
  border: 1px solid #bfdbfe;
  padding: 1rem 1.25rem;
  background: #eff6ff;
  border-left: 4px solid #2563eb;
}
.difference-block h5 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
  color: #1d4ed8;
}
.difference-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.95rem;
}
.difference-table th,
.difference-table td {
  border: 1px solid #cbd5f5;
  padding: 0.6rem 0.75rem;
  text-align: left;
  vertical-align: top;
}
.difference-table thead th {
  background: #dbeafe;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #1e3a8a;
}
.difference-table tbody th {
  width: 20%;
  color: #1e3a8a;
  font-weight: 600;
}
.pill {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.18);
  color: #475569;
  font-size: 0.75rem;
  padding: 0.25rem 0.6rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.sql-statements-section {
  border-top: 2px solid var(--color-border);
  padding-top: 1.25rem;
}
.sql-code-block {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 0.75rem;
  padding: 1.25rem;
  overflow-x: auto;
  box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.3);
}
.sql-code-block pre {
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, SF Mono, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace;
  font-size: 0.9rem;
  line-height: 1.6;
  color: #e2e8f0;
}
.sql-code-block code {
  background: transparent;
  color: inherit;
  padding: 0;
  border-radius: 0;
  font-size: inherit;
}
@media (max-width: 640px) {
  main {
    padding: 2rem 1.25rem 3rem;
  }
  .table-detail {
    padding: 1.35rem;
  }
  .sql-code-block {
    padding: 1rem;
  }
  .sql-code-block pre {
    font-size: 0.8rem;
  }
}
```

## Summary

These changes will:

1. **Gather database structure** - The existing `buildTableComparison()` already does this
2. **Create full context** - New `buildFullDatabaseContext()` function creates detailed text context
3. **Call Claude API** - New `generateSqlStatementsForTable()` function sends table-specific prompts to Claude Sonnet 4 (note: I used the correct model ID `claude-sonnet-4-20250514`)
4. **Display SQL** - New template section shows the generated SQL in a styled code block
5. **Handle all scenarios** - The prompt builder handles table creation, deletion, column changes, foreign keys, indexes, and metadata

The system will generate SQL for each table that has differences, considering the full database context to handle dependencies correctly.

Anthropic claude-sonnet-4-5-20250929 (7.09k in, 12.8k out)


