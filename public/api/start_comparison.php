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
        'Comparison job created successfully.',
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
    $storageConnection = null;
    $db1Connection = null;
    $db2Connection = null;

    try {
        $storageConnection = createConnection($storageDatabase);

        $db1Label = $db1Config['label'] ?? 'Database 1';
        $db2Label = $db2Config['label'] ?? 'Database 2';

        reportProgress(
            $storageConnection,
            $runId,
            'connect_source',
            "Connecting to source database \"{$db1Label}\"...",
            1
        );

        $db1Connection = createConnection($db1Config);

        reportProgress(
            $storageConnection,
            $runId,
            'connect_source_ok',
            "Connected to source database \"{$db1Label}\" successfully.",
            2
        );

        reportProgress(
            $storageConnection,
            $runId,
            'connect_target',
            "Connecting to target database \"{$db2Label}\"...",
            3
        );

        $db2Connection = createConnection($db2Config);

        reportProgress(
            $storageConnection,
            $runId,
            'connect_target_ok',
            "Connected to target database \"{$db2Label}\" successfully.",
            4
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

        // Determine settings from config
        $provider = isset($llmProvider) ? $llmProvider : 'anthropic';
        $model = isset($llmModel) && $llmModel !== '' ? $llmModel : '';

        // Determine model name for logging
        if ($model === '') {
            $modelName = $provider === 'google' ? DEFAULT_GOOGLE_MODEL : DEFAULT_ANTHROPIC_MODEL;
        } else {
            $modelName = $model;
        }

        $providerLabel = $provider === 'google' ? 'Google Gemini' : 'Anthropic';

        // Generate SQL statements with AI
        reportProgress(
            $storageConnection,
            $runId,
            'build_context',
            "Preparing context for {$providerLabel} ({$modelName})...",
            65
        );

        $fullContext = buildFullDatabaseContext($comparison, $storageConnection, $runId);

        $tablesWithDifferences = array_filter(
            $comparison['tableDetails'],
            static function (array $detail): bool {
                return $detail['hasDifferences'];
            }
        );

        $totalTables = count($tablesWithDifferences);

        if ($totalTables === 0) {
            reportProgress(
                $storageConnection,
                $runId,
                'no_differences',
                'No differences found between the two databases. No SQL generation needed.',
                95
            );
        } else {
            reportProgress(
                $storageConnection,
                $runId,
                'start_sql_generation',
                "Starting SQL generation for {$totalTables} table(s) with differences via {$providerLabel}...",
                66
            );
        }

        $currentTableIndex = 0;

        foreach ($comparison['tableDetails'] as $tableName => &$tableDetail) {
            if ($tableDetail['hasDifferences']) {
                $currentTableIndex++;

                $sqlStatements = generateSqlStatementsForTable(
                    $provider,
                    $model,
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
            'Analysis complete! Displaying results...',
            100
        );

        markComparisonRunCompleted($storageConnection, $runId);

    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
        $file = $exception->getFile();
        $line = $exception->getLine();

        $detailedError = "Error: {$errorMessage}\nLocation: {$file}:{$line}";

        // Ensure we have a storage connection to report the error
        $connected = false;
        if ($storageConnection instanceof mysqli) {
            try {
                if ($storageConnection->ping()) {
                    $connected = true;
                }
            } catch (Throwable $e) {
                // Ping failed
            }
        }

        if (!$connected) {
            try {
                $storageConnection = createConnection($storageDatabase);
            } catch (Throwable $e) {
                // If we can't connect to storage, we can't report the error to the DB.
                // Log to system error log as a fallback.
                error_log("Critical failure in background comparison (Run $runId): " . $detailedError);
                exit;
            }
        }

        try {
            reportProgress(
                $storageConnection,
                $runId,
                'error',
                'Error: ' . $errorMessage,
                0
            );

            markComparisonRunFailed($storageConnection, $runId, $detailedError);
        } catch (Throwable $e) {
            error_log("Failed to report error to DB (Run $runId): " . $e->getMessage());
        }
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

