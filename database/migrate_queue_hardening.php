<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
function addQueueColumn(PDO $pdo, string $name, string $definition): void {
    if (!$pdo->query("SHOW COLUMNS FROM notification_queue LIKE '$name'")->fetch()) {
        $pdo->exec("ALTER TABLE notification_queue ADD `$name` $definition");
    }
}
addQueueColumn($pdo, 'processing_started_at', 'DATETIME NULL');
addQueueColumn($pdo, 'lease_expires_at', 'DATETIME NULL');
addQueueColumn($pdo, 'failure_class', "ENUM('pre_send','provider_rejected','post_send_unknown','stale') NULL");
addQueueColumn($pdo, 'provider_message_id', 'VARCHAR(128) NULL');
if (!$pdo->query("SHOW INDEX FROM notification_queue WHERE Key_name='idx_queue_status_next'")->fetch()) {
    $pdo->exec('ALTER TABLE notification_queue ADD INDEX idx_queue_status_next (status,next_attempt_at)');
}
$pdo->exec("UPDATE notification_queue SET processing_started_at=COALESCE(processing_started_at,next_attempt_at,NOW()) WHERE status='processing' AND processing_started_at IS NULL");
$pdo->exec("UPDATE notification_queue SET lease_expires_at=DATE_ADD(COALESCE(processing_started_at,next_attempt_at,NOW()),INTERVAL 3 MINUTE) WHERE status='processing' AND lease_expires_at IS NULL");
echo "Queue hardening migration complete.\n";
