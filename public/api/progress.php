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
    
    // Get current progress
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
    
    // Get latest progress message
    $latestMessage = getLatestProgressMessage($storageConnection, $runId);
    
    // Calculate estimated time remaining if running
    $estimatedSecondsRemaining = null;
    $estimatedTimeRemaining = null;
    
    if ($progress['status'] === 'running') {
        $estimatedSecondsRemaining = getEstimatedTimeRemaining($storageConnection, $runId);
        if ($estimatedSecondsRemaining !== null) {
            $estimatedTimeRemaining = formatDuration($estimatedSecondsRemaining);
        }
    }
    
    // Optionally get all progress steps (include if requested)
    $includeSteps = isset($_GET['includeSteps']) && $_GET['includeSteps'] === 'true';
    $steps = null;
    
    if ($includeSteps) {
        $steps = getAllProgressSteps($storageConnection, $runId);
    }
    
    // Return progress information
    $response = [
        'success' => true,
        'runId' => $runId,
        'status' => $progress['status'],
        'currentStep' => $progress['current_step'],
        'progressPercent' => $progress['progress_percent'],
        'latestMessage' => $latestMessage,
        'errorMessage' => $progress['error_message'],
        'startedAt' => $progress['started_at'],
        'completedAt' => $progress['completed_at'],
        'estimatedSecondsRemaining' => $estimatedSecondsRemaining,
        'estimatedTimeRemaining' => $estimatedTimeRemaining,
        'isComplete' => in_array($progress['status'], ['completed', 'failed'], true),
        'isRunning' => $progress['status'] === 'running',
        'isFailed' => $progress['status'] === 'failed'
    ];
    
    if ($steps !== null) {
        $response['steps'] = $steps;
    }
    
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    
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

