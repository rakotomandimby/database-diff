<?php

// # Summary of the structure
// - `src/database_comparison.php`: only pulls in all of the modular files so existing entry points keep working without touching every require.
// - `src/comparison_builder.php`: contains the orchestration around `buildTableComparison`, run tracking, and storing generated SQL.
// - `src/snapshot_capture.php`: manages capturing live schema snapshots from source/target databases (`SHOW TABLES`, columns, foreign keys, and metadata).
// - `src/table_details.php`: reconstructs a table’s stored detail from the snapshot tables for comparison display.
// - `src/difference_analysis.php`: keeps all comparison logic (columns, metadata, foreign keys) together.
// - `src/storage_operations.php`: handles persisting table differences, querying snapshot metadata, and resetting the cache database.
// - `src/connection.php`: keeps the shared `createConnection()` helper in its own file.
//
declare(strict_types=1);

require_once __DIR__ . '/comparison_builder.php';
require_once __DIR__ . '/snapshot_capture.php';
require_once __DIR__ . '/table_details.php';
require_once __DIR__ . '/difference_analysis.php';
require_once __DIR__ . '/storage_operations.php';
require_once __DIR__ . '/connection.php';

