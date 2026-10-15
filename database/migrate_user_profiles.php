<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
$stmt->execute(['users', 'profile_photo']);
if (!(bool) $stmt->fetchColumn()) {
    $pdo->exec('ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER full_name');
}

echo "User profile migration completed.\n";