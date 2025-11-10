<?php

declare(strict_types=1);

/** @var array|null $comparison */
/** @var Throwable|null $error */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Database Comparison</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/style.css">
</head>
<body>
  <main>
    <h1>Database Table Comparison</h1>
    <p class="lead">Visual overview of additions, removals, and structural changes between the two MySQL databases.</p>

    <?php if ($error instanceof Throwable): ?>
      <section class="card alert deletion">
        <h2>Database error</h2>
        <p><?= htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
      </section>
    <?php elseif (is_array($comparison)): ?>
      <?php
        $tablesDb1 = $comparison['tablesDb1'] ?? [];
        $tablesDb2 = $comparison['tablesDb2'] ?? [];
        $onlyInDb1 = $comparison['onlyInDb1'] ?? [];
        $onlyInDb2 = $comparison['onlyInDb2'] ?? [];
        $tableDetails = $comparison['tableDetails'] ?? [];
        $tablesWithDifferences = array_filter(
            $tableDetails,
            static function (array $details): bool {
                return $details['hasDifferences'] ?? false;
            }
        );
        $tablesWithDifferencesCount = count($tablesWithDifferences);
        $db1Label = $comparison['db1Label'] ?? 'Database 1';
        $db2Label = $comparison['db2Label'] ?? 'Database 2';
        $db1LabelEsc = htmlspecialchars($db1Label, ENT_QUOTES, 'UTF-8');
        $db2LabelEsc = htmlspecialchars($db2Label, ENT_QUOTES, 'UTF-8');
        $formatColumnValue = static function ($value): string {
            if ($value === null) {
                return 'NULL';
            }

            if ($value === '') {
                return '""';
            }

            return (string) $value;
        };
      ?>

      <section class="summary-grid">
        <article class="card">
          <div class="table-header">
            <h2>Tables only in <?= $db1LabelEsc ?></h2>
            <span class="badge badge-deletion"><?= count($onlyInDb1) ?> tables</span>
          </div>
          <?php if (!empty($onlyInDb1)): ?>
            <ul class="diff-list">
              <?php foreach ($onlyInDb1 as $table): ?>
                <li class="diff-item deletion">
                  <div class="list-title">
                    <code><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></code>
                  </div>
                  <div class="list-detail">Missing from <?= $db2LabelEsc ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="empty-state">No exclusive tables.</p>
          <?php endif; ?>
        </article>

        <article class="card">
          <div class="table-header">
            <h2>Tables only in <?= $db2LabelEsc ?></h2>
            <span class="badge badge-addition"><?= count($onlyInDb2) ?> tables</span>
          </div>
          <?php if (!empty($onlyInDb2)): ?>
            <ul class="diff-list">
              <?php foreach ($onlyInDb2 as $table): ?>
                <li class="diff-item addition">
                  <div class="list-title">
                    <code><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></code>
                  </div>
                  <div class="list-detail">New in <?= $db2LabelEsc ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="empty-state">No exclusive tables.</p>
          <?php endif; ?>
        </article>

        <article class="card">
          <div class="table-header">
            <h2>Schema coverage</h2>
            <span class="badge badge-neutral"><?= $tablesWithDifferencesCount ?> tables with differences</span>
          </div>
          <p><strong><?= count($tablesDb1) ?></strong> tables detected in <?= $db1LabelEsc ?>.</p>
          <p><strong><?= count($tablesDb2) ?></strong> tables detected in <?= $db2LabelEsc ?>.</p>
          <p class="list-detail">Only tables with structural or relational differences are shown below.</p>
        </article>
      </section>

      <div class="panels-grid">
        <section class="panel">
          <h2><?= $db1LabelEsc ?> tables (<?= count($tablesDb1) ?>)</h2>
          <?php if (!empty($tablesDb1)): ?>
            <ul class="diff-list">
              <?php foreach ($tablesDb1 as $table): ?>
                <?php $isExclusive = in_array($table, $onlyInDb1, true); ?>
                <li class="diff-item <?= $isExclusive ? 'deletion' : '' ?>">
                  <div class="list-title">
                    <code><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></code>
                    <?php if ($isExclusive): ?>
                      <span class="badge badge-deletion">Exclusive</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Shared</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($isExclusive): ?>
                    <div class="list-detail">Not found in <?= $db2LabelEsc ?>.</div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="empty-state">No tables found.</p>
          <?php endif; ?>
        </section>

        <section class="panel">
          <h2><?= $db2LabelEsc ?> tables (<?= count($tablesDb2) ?>)</h2>
          <?php if (!empty($tablesDb2)): ?>
            <ul class="diff-list">
              <?php foreach ($tablesDb2 as $table): ?>
                <?php $isExclusive = in_array($table, $onlyInDb2, true); ?>
                <li class="diff-item <?= $isExclusive ? 'addition' : '' ?>">
                  <div class="list-title">
                    <code><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></code>
                    <?php if ($isExclusive): ?>
                      <span class="badge badge-addition">Exclusive</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Shared</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($isExclusive): ?>
                    <div class="list-detail">New in <?= $db2LabelEsc ?>.</div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="empty-state">No tables found.</p>
          <?php endif; ?>
        </section>
      </div>

      <section>
        <h2>Field comparison by table</h2>
        <?php if (!empty($tablesWithDifferences)): ?>
          <?php foreach ($tablesWithDifferences as $tableName => $tableData): ?>
            <?php
              $inDb1 = $tableData['inDb1'];
              $inDb2 = $tableData['inDb2'];
              $columnsDb1 = $tableData['columnsDb1'] ?? [];
              $columnsDb2 = $tableData['columnsDb2'] ?? [];
              $onlyColumnsDb1 = $tableData['onlyColumnsDb1'] ?? [];
              $onlyColumnsDb2 = $tableData['onlyColumnsDb2'] ?? [];
              $columnDifferences = $tableData['columnDifferences'] ?? [];
              $tableMetadataDifferences = $tableData['tableMetadataDifferences'] ?? [];
              $foreignKeysOnlyDb1 = $tableData['foreignKeysOnlyDb1'] ?? [];
              $foreignKeysOnlyDb2 = $tableData['foreignKeysOnlyDb2'] ?? [];
              $foreignKeysModified = $tableData['foreignKeysModified'] ?? [];
            ?>
            <article class="table-detail">
              <div class="table-header">
                <h3><?= htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if (!$inDb2): ?>
                  <span class="badge badge-deletion">Missing in <?= $db2LabelEsc ?></span>
                <?php elseif (!$inDb1): ?>
                  <span class="badge badge-addition">Missing in <?= $db1LabelEsc ?></span>
                <?php else: ?>
                  <span class="badge badge-modification">Differences detected</span>
                <?php endif; ?>
              </div>

              <?php if (!$inDb2): ?>
                <p class="alert deletion"><strong>Only present in <?= $db1LabelEsc ?>.</strong> All columns below are missing from <?= $db2LabelEsc ?>.</p>
                <?php if (!empty($columnsDb1)): ?>
                  <div class="columns-grid">
                    <div>
                      <h4><?= $db1LabelEsc ?></h4>
                      <ul class="diff-list">
                        <?php foreach ($columnsDb1 as $columnName => $columnData): ?>
                          <li class="diff-item deletion">
                            <div class="list-title">
                              <code><?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?></code>
                            </div>
                            <?php if (!empty($columnData['Type'])): ?>
                              <div class="list-detail">Type: <?= htmlspecialchars($columnData['Type'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  </div>
                <?php else: ?>
                  <p class="empty-state">No columns found in <?= $db1LabelEsc ?>.</p>
                <?php endif; ?>
              <?php elseif (!$inDb1): ?>
                <p class="alert addition"><strong>Only present in <?= $db2LabelEsc ?>.</strong> All columns below are new in <?= $db2LabelEsc ?>.</p>
                <?php if (!empty($columnsDb2)): ?>
                  <div class="columns-grid">
                    <div>
                      <h4><?= $db2LabelEsc ?></h4>
                      <ul class="diff-list">
                        <?php foreach ($columnsDb2 as $columnName => $columnData): ?>
                          <li class="diff-item addition">
                            <div class="list-title">
                              <code><?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?></code>
                            </div>
                            <?php if (!empty($columnData['Type'])): ?>
                              <div class="list-detail">Type: <?= htmlspecialchars($columnData['Type'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  </div>
                <?php else: ?>
                  <p class="empty-state">No columns found in <?= $db2LabelEsc ?>.</p>
                <?php endif; ?>
              <?php else: ?>
                <p class="table-subtitle">Columns compared side by side.</p>
                <div class="columns-grid">
                  <div>
                    <h4><?= $db1LabelEsc ?></h4>
                    <?php if (!empty($columnsDb1)): ?>
                      <ul class="diff-list">
                        <?php foreach ($columnsDb1 as $columnName => $columnData): ?>
                          <?php
                            $isExclusive = in_array($columnName, $onlyColumnsDb1, true);
                            $isModified = array_key_exists($columnName, $columnDifferences);
                            $class = $isExclusive ? 'deletion' : ($isModified ? 'modification' : '');
                          ?>
                          <li class="diff-item <?= $class ?>">
                            <div class="list-title">
                              <code><?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?></code>
                              <?php if ($isExclusive): ?>
                                <span class="badge badge-deletion">Only <?= $db1LabelEsc ?></span>
                              <?php elseif ($isModified): ?>
                                <span class="badge badge-modification">Modified</span>
                              <?php endif; ?>
                            </div>
                            <?php if (!empty($columnData['Type'])): ?>
                              <div class="list-detail">Type: <?= htmlspecialchars($columnData['Type'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?>
                      <p class="empty-state">No columns found in <?= $db1LabelEsc ?>.</p>
                    <?php endif; ?>
                  </div>

                  <div>
                    <h4><?= $db2LabelEsc ?></h4>
                    <?php if (!empty($columnsDb2)): ?>
                      <ul class="diff-list">
                        <?php foreach ($columnsDb2 as $columnName => $columnData): ?>
                          <?php
                            $isExclusive = in_array($columnName, $onlyColumnsDb2, true);
                            $isModified = array_key_exists($columnName, $columnDifferences);
                            $class = $isExclusive ? 'addition' : ($isModified ? 'modification' : '');
                          ?>
                          <li class="diff-item <?= $class ?>">
                            <div class="list-title">
                              <code><?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?></code>
                              <?php if ($isExclusive): ?>
                                <span class="badge badge-addition">Only <?= $db2LabelEsc ?></span>
                              <?php elseif ($isModified): ?>
                                <span class="badge badge-modification">Modified</span>
                              <?php endif; ?>
                            </div>
                            <?php if (!empty($columnData['Type'])): ?>
                              <div class="list-detail">Type: <?= htmlspecialchars($columnData['Type'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?>
                      <p class="empty-state">No columns found in <?= $db2LabelEsc ?>.</p>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (!empty($columnDifferences)): ?>
                  <?php foreach ($columnDifferences as $columnName => $differences): ?>
                    <div class="difference-block">
                      <h5><code><?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?></code> differences</h5>
                      <table class="difference-table">
                        <thead>
                          <tr>
                            <th>Attribute</th>
                            <th><?= $db1LabelEsc ?></th>
                            <th><?= $db2LabelEsc ?></th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($differences as $attribute => $values): ?>
                            <tr>
                              <th><?= htmlspecialchars($attribute, ENT_QUOTES, 'UTF-8') ?></th>
                              <td><?= htmlspecialchars($formatColumnValue($values['db1']), ENT_QUOTES, 'UTF-8') ?></td>
                              <td><?= htmlspecialchars($formatColumnValue($values['db2']), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <p class="empty-state">All shared columns have matching definitions.</p>
                <?php endif; ?>

                <?php if ($tableMetadataDifferences !== []): ?>
                  <div class="difference-block">
                    <h5>Table settings differences</h5>
                    <table class="difference-table">
                      <thead>
                        <tr>
                          <th>Attribute</th>
                          <th><?= $db1LabelEsc ?></th>
                          <th><?= $db2LabelEsc ?></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($tableMetadataDifferences as $attribute => $values): ?>
                          <tr>
                            <th><?= htmlspecialchars($attribute, ENT_QUOTES, 'UTF-8') ?></th>
                            <td><?= htmlspecialchars($formatColumnValue($values['db1']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($formatColumnValue($values['db2']), ENT_QUOTES, 'UTF-8') ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>

                <?php if (!empty($foreignKeysOnlyDb1) || !empty($foreignKeysOnlyDb2) || !empty($foreignKeysModified)): ?>
                  <div class="difference-block">
                    <h5>Foreign key differences</h5>

                    <?php if (!empty($foreignKeysOnlyDb1)): ?>
                      <h6><?= $db1LabelEsc ?> only</h6>
                      <ul class="diff-list">
                        <?php foreach ($foreignKeysOnlyDb1 as $constraintName => $definition): ?>
                          <li class="diff-item deletion">
                            <div class="list-title">
                              <code><?= htmlspecialchars($constraintName, ENT_QUOTES, 'UTF-8') ?></code>
                              <span class="badge badge-deletion">Only <?= $db1LabelEsc ?></span>
                            </div>
                            <?php if (!empty($definition['columns'])): ?>
                              <div class="list-detail">
                                <?php foreach ($definition['columns'] as $column): ?>
                                  <div>
                                    <code><?= htmlspecialchars($column['column'], ENT_QUOTES, 'UTF-8') ?></code>
                                    →
                                    <code><?= htmlspecialchars($column['referencedTable'], ENT_QUOTES, 'UTF-8') ?></code>.
                                    <code><?= htmlspecialchars($column['referencedColumn'], ENT_QUOTES, 'UTF-8') ?></code>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php else: ?>
                              <p class="empty-state">No columns listed.</p>
                            <?php endif; ?>
                            <div class="list-detail">
                              On update:
                              <strong><?= htmlspecialchars($definition['updateRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>,
                              on delete:
                              <strong><?= htmlspecialchars($definition['deleteRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>

                    <?php if (!empty($foreignKeysOnlyDb2)): ?>
                      <h6><?= $db2LabelEsc ?> only</h6>
                      <ul class="diff-list">
                        <?php foreach ($foreignKeysOnlyDb2 as $constraintName => $definition): ?>
                          <li class="diff-item addition">
                            <div class="list-title">
                              <code><?= htmlspecialchars($constraintName, ENT_QUOTES, 'UTF-8') ?></code>
                              <span class="badge badge-addition">Only <?= $db2LabelEsc ?></span>
                            </div>
                            <?php if (!empty($definition['columns'])): ?>
                              <div class="list-detail">
                                <?php foreach ($definition['columns'] as $column): ?>
                                  <div>
                                    <code><?= htmlspecialchars($column['column'], ENT_QUOTES, 'UTF-8') ?></code>
                                    →
                                    <code><?= htmlspecialchars($column['referencedTable'], ENT_QUOTES, 'UTF-8') ?></code>.
                                    <code><?= htmlspecialchars($column['referencedColumn'], ENT_QUOTES, 'UTF-8') ?></code>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php else: ?>
                              <p class="empty-state">No columns listed.</p>
                            <?php endif; ?>
                            <div class="list-detail">
                              On update:
                              <strong><?= htmlspecialchars($definition['updateRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>,
                              on delete:
                              <strong><?= htmlspecialchars($definition['deleteRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>

                    <?php if (!empty($foreignKeysModified)): ?>
                      <h6>Modified definitions</h6>
                      <?php foreach ($foreignKeysModified as $constraintName => $definitions): ?>
                        <div class="diff-item modification">
                          <div class="list-title">
                            <code><?= htmlspecialchars($constraintName, ENT_QUOTES, 'UTF-8') ?></code>
                            <span class="badge badge-modification">Modified</span>
                          </div>
                          <div class="columns-grid">
                            <div>
                              <h6><?= $db1LabelEsc ?></h6>
                              <?php if (!empty($definitions['db1']['columns'])): ?>
                                <div class="list-detail">
                                  <?php foreach ($definitions['db1']['columns'] as $column): ?>
                                    <div>
                                      <code><?= htmlspecialchars($column['column'], ENT_QUOTES, 'UTF-8') ?></code>
                                      →
                                      <code><?= htmlspecialchars($column['referencedTable'], ENT_QUOTES, 'UTF-8') ?></code>.
                                      <code><?= htmlspecialchars($column['referencedColumn'], ENT_QUOTES, 'UTF-8') ?></code>
                                    </div>
                                  <?php endforeach; ?>
                                </div>
                              <?php else: ?>
                                <p class="empty-state">No columns listed.</p>
                              <?php endif; ?>
                              <div class="list-detail">
                                On update:
                                <strong><?= htmlspecialchars($definitions['db1']['updateRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>,
                                on delete:
                                <strong><?= htmlspecialchars($definitions['db1']['deleteRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>
                              </div>
                            </div>
                            <div>
                              <h6><?= $db2LabelEsc ?></h6>
                              <?php if (!empty($definitions['db2']['columns'])): ?>
                                <div class="list-detail">
                                  <?php foreach ($definitions['db2']['columns'] as $column): ?>
                                    <div>
                                      <code><?= htmlspecialchars($column['column'], ENT_QUOTES, 'UTF-8') ?></code>
                                      →
                                      <code><?= htmlspecialchars($column['referencedTable'], ENT_QUOTES, 'UTF-8') ?></code>.
                                      <code><?= htmlspecialchars($column['referencedColumn'], ENT_QUOTES, 'UTF-8') ?></code>
                                    </div>
                                  <?php endforeach; ?>
                                </div>
                              <?php else: ?>
                                <p class="empty-state">No columns listed.</p>
                              <?php endif; ?>
                              <div class="list-detail">
                                On update:
                                <strong><?= htmlspecialchars($definitions['db2']['updateRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>,
                                on delete:
                                <strong><?= htmlspecialchars($definitions['db2']['deleteRule'] ?? 'RESTRICT', ENT_QUOTES, 'UTF-8') ?></strong>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="empty-state">No table differences detected.</p>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="card alert deletion">
        <h2>Comparison unavailable</h2>
        <p>Unable to generate comparison data.</p>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>

