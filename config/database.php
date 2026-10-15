<?php
// Database connection settings (XAMPP defaults)
define('DB_HOST', 'localhost');
define('DB_PORT', '3307');
define('DB_NAME', 'billing_collection_system');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+08:00'");
    }
    return $pdo;
}

