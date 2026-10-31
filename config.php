<?php
declare(strict_types=1);

/**
 * Database settings for a standard XAMPP installation.
 * Update these values if your local MySQL credentials are different.
 */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'personal_finance_manager';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $connection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $connection;
}
