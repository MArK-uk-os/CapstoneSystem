<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
function addNotificationColumn(PDO $pdo, string $name, string $definition): void {
    if (!$pdo->query("SHOW COLUMNS FROM notifications LIKE '$name'")->fetch()) {
        $pdo->exec("ALTER TABLE notifications ADD `$name` $definition");
    }
}
addNotificationColumn($pdo, 'request_key', 'VARCHAR(64) NULL');
addNotificationColumn($pdo, 'requested_by', 'INT NULL');
addNotificationColumn($pdo, 'error_message', 'VARCHAR(255) NULL');
$status = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'status'")->fetch();
if ($status && !str_contains($status['Type'], 'processing')) {
    $pdo->exec("ALTER TABLE notifications MODIFY status ENUM('processing','sent','delivered','failed') NOT NULL DEFAULT 'sent'");
}
if (!$pdo->query("SHOW INDEX FROM notifications WHERE Key_name='uq_notifications_request_key'")->fetch()) {
    $pdo->exec('ALTER TABLE notifications ADD UNIQUE KEY uq_notifications_request_key (request_key)');
}
if (!$pdo->query("SHOW INDEX FROM notifications WHERE Key_name='idx_notifications_manual_lookup'")->fetch()) {
    $pdo->exec('ALTER TABLE notifications ADD INDEX idx_notifications_manual_lookup (billing_id,type,channel,status,sent_at)');
}
echo "Notification hardening migration complete.\n";
