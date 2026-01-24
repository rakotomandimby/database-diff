<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/connection.php';
require_once __DIR__ . '/../../src/progress_tracker.php';
require_once __DIR__ . '/../../src/storage_operations.php';
require_once __DIR__ . '/../../src/table_details.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Get run ID from query parameter
    $runId = $_GET['runId'] ?? null;
    
    if ($runId === null || $runId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameter: runId'
        ]);
        exit;
    }
    
    $runId = (int) $runId;
    
    if ($runId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid runId. Must be a positive integer.'
        ]);
        exit;
    }
    
    // Connect to storage database
    $storageConnection = createConnection($storageDatabase);
    
    // Get current progress to check status
    $progress = getCurrentProgress($storageConnection, $runId);
    
    if ($progress === null) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Comparison run not found'
        ]);
        $storageConnection->close();
        exit;
    }
    
    // Check if comparison is complete
    if ($progress['status'] !== 'completed') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Comparison is not yet complete',
            'status' => $progress['status'],
            'progressPercent' => $progress['progress_percent'],
            'currentStep' => $progress['current_step']
        ]);
        $storageConnection->close();
        exit;
    }
    
    // Get run metadata
    $stmt = $storageConnection->prepare(
        'SELECT source_label, target_label, source_database, target_database
         FROM dbdif_comparison_runs
         WHERE id = ?
         LIMIT 1'
    );
    
    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $stmt->bind_result($sourceLabel, $targetLabel, $sourceDatabase, $targetDatabase);
    
    $runMetadata = null;
    
    if ($stmt->fetch()) {
        $runMetadata = [
            'sourceLabel' => $sourceLabel,
            'targetLabel' => $targetLabel,
            'sourceDatabase' => $sourceDatabase,
            'targetDatabase' => $targetDatabase
        ];
    }
    
    $stmt->close();
    
    if ($runMetadata === null) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to retrieve run metadata'
        ]);
        $storageConnection->close();
        exit;
    }
    
    // Get table lists from storage
    $tablesDb1 = getTablesFromStorage($storageConnection, $runId, 'source');
    $tablesDb2 = getTablesFromStorage($storageConnection, $runId, 'target');
    
    $onlyInDb1 = array_values(array_diff($tablesDb1, $tablesDb2));
    $onlyInDb2 = array_values(array_diff($tablesDb2, $tablesDb1));
    
    $allTables = array_values(array_unique(array_merge($tablesDb1, $tablesDb2)));
    sort($allTables, SORT_NATURAL | SORT_FLAG_CASE);
    
    // Build table details from storage
    $tableDetails = [];
    
    foreach ($allTables as $tableName) {
        $tableDetail = buildTableDetailFromStorage($storageConnection, $runId, $tableName, true);
        
        if ($tableDetail['hasDifferences']) {
            // Retrieve generated SQL from storage
            $sqlStmt = $storageConnection->prepare(
                'SELECT statements, model_name, generated_at
                 FROM dbdif_generated_sql
                 WHERE run_id = ? AND table_name = ?
                 LIMIT 1'
            );
            
            $sqlStmt->bind_param('is', $runId, $tableName);
            $sqlStmt->execute();
            $sqlStmt->bind_result($sqlStatements, $modelName, $generatedAt);
            
            if ($sqlStmt->fetch()) {
                $tableDetail['sqlStatements'] = $sqlStatements;
                $tableDetail['sqlModelName'] = $modelName;
                $tableDetail['sqlGeneratedAt'] = $generatedAt;
            } else {
                $tableDetail['sqlStatements'] = '-- SQL generation not available';
                $tableDetail['sqlModelName'] = null;
                $tableDetail['sqlGeneratedAt'] = null;
            }
            
            $sqlStmt->close();
            
            $tableDetails[$tableName] = $tableDetail;
        }
    }
    
    // Build comparison result
    $comparison = [
        'db1Label' => $runMetadata['sourceLabel'],
        'db2Label' => $runMetadata['targetLabel'],
        'tablesDb1' => $tablesDb1,
        'tablesDb2' => $tablesDb2,
        'onlyInDb1' => $onlyInDb1,
        'onlyInDb2' => $onlyInDb2,
        'tableDetails' => $tableDetails,
        'runId' => $runId,
        'startedAt' => $progress['started_at'],
        'completedAt' => $progress['completed_at']
    ];
    
    echo json_encode([
        'success' => true,
        'comparison' => $comparison
    ]);
    
    $storageConnection->close();
    
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine()
    ]);
}

