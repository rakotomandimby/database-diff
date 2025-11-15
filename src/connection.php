<?php

declare(strict_types=1);

function createConnection(array $config): mysqli
{
    $connection = new mysqli(
        $config['host'],
        $config['username'],
        $config['password'],
        $config['database']
    );

    $connection->set_charset('utf8mb4');

    return $connection;
}

