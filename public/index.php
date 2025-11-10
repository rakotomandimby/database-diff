<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/database_comparison.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$comparison = null;
$error = null;
$db1Connection = null;
$db2Connection = null;

try {
    $db1Connection = createConnection($db1Config);
    $db2Connection = createConnection($db2Config);

    $comparison = buildTableComparison($db1Config, $db1Connection, $db2Config, $db2Connection);
} catch (Throwable $exception) {
    $error = $exception;
    http_response_code(500);
}

require __DIR__ . '/../templates/database_comparison.php';

if ($db1Connection instanceof mysqli) {
    $db1Connection->close();
}

if ($db2Connection instanceof mysqli) {
    $db2Connection->close();
}
