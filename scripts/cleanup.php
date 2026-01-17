<?php

declare(strict_types=1);

/**
 * Cleanup script for removing old comparison runs from the storage database.
 * 
 * Usage:
 *   php scripts/cleanup.php [--days=7] [--timeout=30] [--stats]
 * 
 * Options:
 *   --days=N       Delete completed/failed runs older than N days (default: 7)
 *   --timeout=N    Delete stalled running runs older than N minutes (default: 30)
 *   --stats        Display storage statistics without performing cleanup
 * 
 * Example cron entry (run daily at 2 AM):
 *   0 2 * * * /usr/bin/php /path/to/scripts/cleanup.php --days=7 --timeout=30
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/database_comparison.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Parse command-line arguments
$options = getopt('', ['days:', 'timeout:', 'stats']);

$daysToKeep = isset($options['days']) ? (int) $options['days'] : 7;
$timeoutMinutes = isset($options['timeout']) ? (int) $options['timeout'] : 30;
$showStatsOnly = isset($options['stats']);

try {
    $storageConnection = createConnection($storageDatabase);

    echo "=== Database Comparison Storage Cleanup ===\n\n";

    // Display statistics
    $stats = getStorageStatistics($storageConnection);
    echo "Current Statistics:\n";
    echo "  Total runs:     {$stats['totalRuns']}\n";
    echo "  Completed:      {$stats['completedRuns']}\n";
    echo "  Failed:         {$stats['failedRuns']}\n";
    echo "  Running:        {$stats['runningRuns']}\n";
    echo "  Oldest run:     " . ($stats['oldestRun'] ?? 'N/A') . "\n";
    echo "  Newest run:     " . ($stats['newestRun'] ?? 'N/A') . "\n";
    echo "\n";

    if ($showStatsOnly) {
        echo "Statistics displayed. No cleanup performed.\n";
        $storageConnection->close();
        exit(0);
    }

    // Perform cleanup
    echo "Performing cleanup...\n";
    echo "  Keeping runs from the last {$daysToKeep} days\n";
    echo "  Cleaning stalled runs older than {$timeoutMinutes} minutes\n\n";

    $deletedOld = cleanupOldComparisonRuns($storageConnection, $daysToKeep);
    echo "Deleted {$deletedOld} old comparison run(s)\n";

    $deletedStalled = cleanupStalledComparisonRuns($storageConnection, $timeoutMinutes);
    echo "Deleted {$deletedStalled} stalled comparison run(s)\n";

    echo "\nCleanup completed successfully.\n";

    // Display updated statistics
    $statsAfter = getStorageStatistics($storageConnection);
    echo "\nStatistics after cleanup:\n";
    echo "  Total runs:     {$statsAfter['totalRuns']}\n";
    echo "  Completed:      {$statsAfter['completedRuns']}\n";
    echo "  Failed:         {$statsAfter['failedRuns']}\n";
    echo "  Running:        {$statsAfter['runningRuns']}\n";

    $storageConnection->close();
} catch (Throwable $exception) {
    echo "ERROR: " . $exception->getMessage() . "\n";
    echo "File: " . $exception->getFile() . "\n";
    echo "Line: " . $exception->getLine() . "\n";
    exit(1);
}

