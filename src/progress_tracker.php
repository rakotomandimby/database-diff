<?php

declare(strict_types=1);

/**
 * Reports progress for a comparison run.
 * 
 * This function inserts a new progress record and updates the current run status
 * to reflect the latest step and progress percentage.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $runId The comparison run ID
 * @param string $step Short identifier for the current step (e.g., 'snapshot_source')
 * @param string $message Human-readable message describing the current operation
 * @param int $progressPercent Progress percentage (0-100)
 * @return void
 */
function reportProgress(
    mysqli $storageConnection,
    int $runId,
    string $step,
    string $message,
    int $progressPercent
): void {
    // Ensure progress percentage is within valid range
    $progressPercent = max(0, min(100, $progressPercent));

    // Insert progress record
    $stmt = $storageConnection->prepare(
        'INSERT INTO comparison_progress (run_id, step, message, progress_percent)
         VALUES (?, ?, ?, ?)'
    );

    $stmt->bind_param('issi', $runId, $step, $message, $progressPercent);
    $stmt->execute();
    $stmt->close();

    // Update the comparison run with current status
    $updateStmt = $storageConnection->prepare(
        'UPDATE comparison_runs
         SET current_step = ?, progress_percent = ?
         WHERE id = ?'
    );

    $updateStmt->bind_param('sii', $step, $progressPercent, $runId);
    $updateStmt->execute();
    $updateStmt->close();
}

/**
 * Retrieves the current progress status for a comparison run.
 * 
 * This function returns the latest progress information from the comparison_runs table,
 * which is faster than querying all progress records.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $runId The comparison run ID
 * @return array|null Array with keys: status, current_step, progress_percent, error_message, or null if not found
 */
function getCurrentProgress(mysqli $storageConnection, int $runId): ?array
{
    $stmt = $storageConnection->prepare(
        'SELECT status, current_step, progress_percent, error_message, started_at, completed_at
         FROM comparison_runs
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $stmt->bind_result($status, $currentStep, $progressPercent, $errorMessage, $startedAt, $completedAt);

    $progress = null;

    if ($stmt->fetch()) {
        $progress = [
            'status' => $status,
            'current_step' => $currentStep,
            'progress_percent' => (int) $progressPercent,
            'error_message' => $errorMessage,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ];
    }

    $stmt->close();

    return $progress;
}

/**
 * Retrieves all progress steps for a comparison run.
 * 
 * This function returns a chronological list of all progress records for detailed logging
 * or displaying a step-by-step history to the user.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $runId The comparison run ID
 * @return array Array of progress records, each with keys: step, message, progress_percent, created_at
 */
function getAllProgressSteps(mysqli $storageConnection, int $runId): array
{
    $stmt = $storageConnection->prepare(
        'SELECT step, message, progress_percent, created_at
         FROM comparison_progress
         WHERE run_id = ?
         ORDER BY id ASC'
    );

    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $stmt->bind_result($step, $message, $progressPercent, $createdAt);

    $steps = [];

    while ($stmt->fetch()) {
        $steps[] = [
            'step' => $step,
            'message' => $message,
            'progress_percent' => (int) $progressPercent,
            'created_at' => $createdAt,
        ];
    }

    $stmt->close();

    return $steps;
}

/**
 * Retrieves the latest progress message for a comparison run.
 * 
 * This is a convenience function that returns just the most recent progress message
 * without the full progress record.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $runId The comparison run ID
 * @return string|null The latest progress message, or null if none found
 */
function getLatestProgressMessage(mysqli $storageConnection, int $runId): ?string
{
    $stmt = $storageConnection->prepare(
        'SELECT message
         FROM comparison_progress
         WHERE run_id = ?
         ORDER BY id DESC
         LIMIT 1'
    );

    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $stmt->bind_result($message);

    $latestMessage = null;

    if ($stmt->fetch()) {
        $latestMessage = $message;
    }

    $stmt->close();

    return $latestMessage;
}

/**
 * Calculates the estimated time remaining for a comparison run.
 * 
 * This function estimates completion time based on the current progress percentage
 * and elapsed time since the run started.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $runId The comparison run ID
 * @return int|null Estimated seconds remaining, or null if cannot be calculated
 */
function getEstimatedTimeRemaining(mysqli $storageConnection, int $runId): ?int
{
    $progress = getCurrentProgress($storageConnection, $runId);

    if ($progress === null || $progress['status'] !== 'running') {
        return null;
    }

    $progressPercent = $progress['progress_percent'];

    if ($progressPercent <= 0) {
        return null;
    }

    $startedAt = $progress['started_at'];

    if ($startedAt === null) {
        return null;
    }

    $startTime = strtotime($startedAt);
    $currentTime = time();
    $elapsedSeconds = $currentTime - $startTime;

    if ($elapsedSeconds <= 0) {
        return null;
    }

    // Calculate time per percent
    $timePerPercent = $elapsedSeconds / $progressPercent;
    $remainingPercent = 100 - $progressPercent;
    $estimatedRemaining = (int) round($timePerPercent * $remainingPercent);

    return $estimatedRemaining;
}

/**
 * Formats seconds into a human-readable duration string.
 * 
 * @param int $seconds Number of seconds
 * @return string Formatted duration (e.g., "2m 30s", "1h 15m", "45s")
 */
function formatDuration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }

    if ($seconds < 3600) {
        $minutes = (int) floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        return $minutes . 'm ' . $remainingSeconds . 's';
    }

    $hours = (int) floor($seconds / 3600);
    $remainingMinutes = (int) floor(($seconds % 3600) / 60);
    return $hours . 'h ' . $remainingMinutes . 'm';
}

/**
 * Deletes all progress records for a comparison run.
 * 
 * This is useful for cleanup operations. Note that due to the foreign key constraint
 * with CASCADE, deleting the comparison run will automatically delete progress records.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $runId The comparison run ID
 * @return int Number of progress records deleted
 */
function deleteProgressRecords(mysqli $storageConnection, int $runId): int
{
    $stmt = $storageConnection->prepare(
        'DELETE FROM comparison_progress
         WHERE run_id = ?'
    );

    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows;
}

/**
 * Checks if a comparison run is still actively running.
 * 
 * This function checks both the run status and whether it has exceeded a timeout.
 * Runs that have been "running" for longer than the timeout are considered stalled.
 * 
 * @param mysqli $storageConnection The storage database connection
 * @param int $runId The comparison run ID
 * @param int $timeoutMinutes Maximum minutes a run should take (default: 30)
 * @return bool True if the run is actively running, false if completed, failed, or stalled
 */
function isRunActivelyRunning(
    mysqli $storageConnection,
    int $runId,
    int $timeoutMinutes = 30
): bool {
    $progress = getCurrentProgress($storageConnection, $runId);

    if ($progress === null) {
        return false;
    }

    if ($progress['status'] !== 'running') {
        return false;
    }

    // Check if run has exceeded timeout
    $startedAt = $progress['started_at'];

    if ($startedAt === null) {
        return false;
    }

    $startTime = strtotime($startedAt);
    $currentTime = time();
    $elapsedMinutes = ($currentTime - $startTime) / 60;

    if ($elapsedMinutes > $timeoutMinutes) {
        return false;
    }

    return true;
}

