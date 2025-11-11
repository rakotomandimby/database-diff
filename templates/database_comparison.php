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

