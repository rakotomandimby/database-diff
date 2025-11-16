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
$storageConnection = null;
$runId = null;

try {
    $db1Connection = createConnection($db1Config);
    $db2Connection = createConnection($db2Config);
    $storageConnection = createConnection($storageDatabase);

    resetStorageDatabase($storageConnection);
    $runId = createComparisonRun($storageConnection, $db1Config, $db2Config);

    $comparison = buildTableComparison(
        $db1Config,
        $db1Connection,
        $db2Config,
        $db2Connection,
        $storageConnection,
        $runId
    );

    $fullContext = buildFullDatabaseContext($storageConnection, $runId);
    $modelName = defined('SQL_GENERATOR_MODEL') ? SQL_GENERATOR_MODEL : 'claude-sonnet-4-5';

    // Process only tables with differences
    $tablesToProcess = getTableDifferencesForRun($storageConnection, $runId);
    
    foreach ($tablesToProcess as $tableName) {
        // Fetch table detail on-demand (will be discarded after processing)
        $tableDetail = buildTableDetailFromStorage($storageConnection, $runId, $tableName);
        
        if ($tableDetail['hasDifferences']) {
            $sqlStatements = generateSqlStatementsForTable(
                $llmApiKey,
                $tableName,
                $tableDetail,
                $comparison['db1Label'],
                $comparison['db2Label'],
                $fullContext
            );
            storeGeneratedSql($storageConnection, $runId, $tableName, $modelName, $sqlStatements);
        }
        
        // Discard table detail immediately after processing to free memory
        unset($tableDetail);
    }

    markComparisonRunCompleted($storageConnection, $runId);
} catch (Throwable $exception) {
    $error = $exception;

    if ($storageConnection instanceof mysqli && $runId !== null) {
        markComparisonRunFailed($storageConnection, $runId, $exception->getMessage());
    }

    http_response_code(500);
}

require __DIR__ . '/../templates/database_comparison.php';

if ($db1Connection instanceof mysqli) {
    $db1Connection->close();
}

if ($db2Connection instanceof mysqli) {
    $db2Connection->close();
}

if ($storageConnection instanceof mysqli) {
    $storageConnection->close();
}

