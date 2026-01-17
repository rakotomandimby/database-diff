<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/database_comparison.php';
require_once __DIR__ . '/../../src/ai_sql_generator.php';
require_once __DIR__ . '/../../src/progress_tracker.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Create storage connection first to track the run
    $storageConnection = createConnection($storageDatabase);
    
    // Create a new comparison run
    $runId = createComparisonRun($storageConnection, $db1Config, $db2Config);
    
    reportProgress(
        $storageConnection,
        $runId,
        'init',
        'Comparison job created successfully',
        0
    );
    
    // Return the run ID immediately
    echo json_encode([
        'success' => true,
        'runId' => $runId,
        'message' => 'Comparison started successfully'
    ]);
    
    $storageConnection->close();
    
    // Allow the script to continue running after client disconnects
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Flush all output buffers
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    
    // Ignore user abort so the comparison continues even if client disconnects
    ignore_user_abort(true);
    set_time_limit(0);
    
    // Reconnect to storage for background processing
    $storageConnection = createConnection($storageDatabase);
    $db1Connection = null;
    $db2Connection = null;
    
    try {
        reportProgress(
            $storageConnection,
            $runId,
            'connect_source',
            'Connecting to source database...',
            1
        );
        
        $db1Connection = createConnection($db1Config);
        
        reportProgress(
            $storageConnection,
            $runId,
            'connect_target',
            'Connecting to target database...',
            2
        );
        
        $db2Connection = createConnection($db2Config);
        
        reportProgress(
            $storageConnection,
            $runId,
            'start_comparison',
            'Starting comparison process...',
            3
        );
        
        // Build the comparison (this will report progress internally)
        $comparison = buildTableComparison(
            $db1Config,
            $db1Connection,
            $db2Config,
            $db2Connection,
            $storageConnection,
            $runId
        );
        
        // Generate SQL statements with AI
        $fullContext = buildFullDatabaseContext($comparison, $storageConnection, $runId);
        $modelName = defined('SQL_GENERATOR_MODEL') ? SQL_GENERATOR_MODEL : 'claude-haiku-4-5';
        
        $tablesWithDifferences = array_filter(
            $comparison['tableDetails'],
            static function (array $detail): bool {
                return $detail['hasDifferences'];
            }
        );
        
        $totalTables = count($tablesWithDifferences);
        $currentTableIndex = 0;
        
        foreach ($comparison['tableDetails'] as $tableName => &$tableDetail) {
            if ($tableDetail['hasDifferences']) {
                $currentTableIndex++;
                
                $sqlStatements = generateSqlStatementsForTable(
                    $llmApiKey,
                    $tableName,
                    $tableDetail,
                    $comparison['db1Label'],
                    $comparison['db2Label'],
                    $fullContext,
                    $storageConnection,
                    $runId,
                    $currentTableIndex,
                    $totalTables
                );
                
                $tableDetail['sqlStatements'] = $sqlStatements;
                storeGeneratedSql($storageConnection, $runId, $tableName, $modelName, $sqlStatements);
            } else {
                $tableDetail['sqlStatements'] = '-- No changes needed';
            }
        }
        unset($tableDetail);
        
        reportProgress(
            $storageConnection,
            $runId,
            'finalize',
            'Finalizing comparison results...',
            96
        );
        
        reportProgress(
            $storageConnection,
            $runId,
            'complete',
            'Comparison complete!',
            100
        );
        
        markComparisonRunCompleted($storageConnection, $runId);
        
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
        
        reportProgress(
            $storageConnection,
            $runId,
            'error',
            'Error: ' . $errorMessage,
            0
        );
        
        markComparisonRunFailed($storageConnection, $runId, $errorMessage);
    } finally {
        if ($db1Connection instanceof mysqli) {
            $db1Connection->close();
        }
        
        if ($db2Connection instanceof mysqli) {
            $db2Connection->close();
        }
        
        if ($storageConnection instanceof mysqli) {
            $storageConnection->close();
        }
    }
    
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine()
    ]);
}

