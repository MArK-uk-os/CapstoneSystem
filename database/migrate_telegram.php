<?php
// Run once with PHP CLI when deploying Telegram support.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
if (!$pdo->query("SHOW COLUMNS FROM customers LIKE 'telegram_chat_id'")->fetch()) {
    $pdo->exec('ALTER TABLE customers ADD telegram_chat_id VARCHAR(20) NULL');
}
$pdo->exec("ALTER TABLE notifications MODIFY channel ENUM('sms','email','telegram') NOT NULL");
echo "Telegram migration complete.\n";
