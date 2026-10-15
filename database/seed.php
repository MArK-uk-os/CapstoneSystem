<?php
// Run this once from the CLI or browser to create the default admin account.
// Usage: php database/seed.php
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();

$username = 'admin';
$plainPassword = 'admin123'; // change immediately after first login
$fullName = 'System Administrator';
$role = 'admin';

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);

if ($stmt->fetch()) {
    echo "Admin user already exists. Nothing to do.\n";
    exit;
}

$hashed = password_hash($plainPassword, PASSWORD_BCRYPT);

$insert = $pdo->prepare(
    'INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)'
);
$insert->execute([$username, $hashed, $fullName, $role]);

echo "Default admin created. Username: {$username} | Password: {$plainPassword}\n";
echo "Please log in and change this password immediately.\n";
