<?php

declare(strict_types=1);

function getDbConnection(): PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';

    if ($name === '' || $user === '') {
        throw new RuntimeException('DB_NAME and DB_USER environment variables are required.');
    }

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
}
