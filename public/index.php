<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/database_comparison.php';
require_once __DIR__ . '/../src/progress_tracker.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$comparison = null;
$error = null;
$storageConnection = null;
$runId = isset($_GET['runId']) ? (int) $_GET['runId'] : null;

try {
    $storageConnection = createConnection($storageDatabase);

    if ($runId !== null) {
        // Load existing comparison from storage
        $runInfo = null;
        $stmt = $storageConnection->prepare(
            'SELECT source_label, target_label, status, error_message FROM comparison_runs WHERE id = ?'
        );
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $stmt->bind_result($sourceLabel, $targetLabel, $status, $errorMessage);
        
        if ($stmt->fetch()) {
            $runInfo = [
                'source_label' => $sourceLabel,
                'target_label' => $targetLabel,
                'status' => $status,
                'error_message' => $errorMessage
            ];
        }
        $stmt->close();

        if (!$runInfo) {
            throw new Exception("Comparison run with ID $runId not found.");
        }

        if ($runInfo['status'] === 'failed') {
            throw new Exception("This comparison run failed: " . $runInfo['error_message']);
        }

        if ($runInfo['status'] === 'running') {
            // If it's still running, we'll let the template handle the loading state
            $comparison = null;
        } else {
            // Reconstruct the comparison object
            $tablesDb1 = getTablesFromStorage($storageConnection, $runId, 'source');
            $tablesDb2 = getTablesFromStorage($storageConnection, $runId, 'target');
            
            $onlyInDb1 = array_values(array_diff($tablesDb1, $tablesDb2));
            $onlyInDb2 = array_values(array_diff($tablesDb2, $tablesDb1));
            
            $allTables = getAllTablesForRun($storageConnection, $runId);
            $tableDetails = [];

            // Fetch all generated SQL statements at once for efficiency
            $sqlStatements = [];
            $sqlResult = $storageConnection->query("SELECT table_name, statements FROM generated_sql WHERE run_id = $runId");
            while ($row = $sqlResult->fetch_assoc()) {
                $sqlStatements[$row['table_name']] = $row['statements'];
            }

            foreach ($allTables as $tableName) {
                $tableDetail = buildTableDetailFromStorage($storageConnection, $runId, $tableName);
                
                if ($tableDetail['hasDifferences']) {
                    $tableDetail['sqlStatements'] = $sqlStatements[$tableName] ?? '-- SQL not generated for this table';
                    $tableDetails[$tableName] = $tableDetail;
                }
            }

            $comparison = [
                'db1Label' => $runInfo['source_label'],
                'db2Label' => $runInfo['target_label'],
                'tablesDb1' => $tablesDb1,
                'tablesDb2' => $tablesDb2,
                'onlyInDb1' => $onlyInDb1,
                'onlyInDb2' => $onlyInDb2,
                'tableDetails' => $tableDetails,
                'runId' => $runId,
            ];
        }
    }
} catch (Throwable $exception) {
    $error = $exception;
    http_response_code(500);
}

// Render the template
require __DIR__ . '/../templates/database_comparison.php';

if ($storageConnection instanceof mysqli) {
    $storageConnection->close();
}

