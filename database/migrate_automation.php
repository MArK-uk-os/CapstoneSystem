<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
function addAutomationColumn(PDO $pdo, string $table, string $name, string $definition): void {
    if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$name'")->fetch()) $pdo->exec("ALTER TABLE `$table` ADD `$name` $definition");
}
addAutomationColumn($pdo, 'customer_services', 'service_account_number', 'VARCHAR(30) NULL UNIQUE');
addAutomationColumn($pdo, 'customer_services', 'plan_id', 'INT NULL');
addAutomationColumn($pdo, 'billing', 'service_name_snapshot', 'VARCHAR(100) NULL');
addAutomationColumn($pdo, 'billing', 'account_number_snapshot', 'VARCHAR(30) NULL');
$pdo->exec("UPDATE customer_services SET service_account_number = CONCAT('ACC-', LPAD(id, 6, '0')) WHERE service_account_number IS NULL");
$pdo->exec("UPDATE billing b JOIN customer_services cs ON cs.id=b.customer_service_id JOIN services s ON s.id=cs.service_id SET b.service_name_snapshot=COALESCE(b.service_name_snapshot,s.service_name),b.account_number_snapshot=COALESCE(b.account_number_snapshot,cs.service_account_number)");
$pdo->exec("CREATE TABLE IF NOT EXISTS internet_plans (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, speed_mbps INT NOT NULL DEFAULT 0, monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0, enabled TINYINT NOT NULL DEFAULT 0)");
foreach ([1,2,3] as $slot) $pdo->prepare('INSERT IGNORE INTO internet_plans (id,name) VALUES (?,?)')->execute([$slot, 'Internet Plan ' . $slot]);
if (!$pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_services' AND COLUMN_NAME='plan_id' AND REFERENCED_TABLE_NAME='internet_plans'")->fetch()) $pdo->exec('ALTER TABLE customer_services ADD CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_id) REFERENCES internet_plans(id)');
// A customer can have separate service accounts on the same service/plan.
if ($pdo->query("SHOW INDEX FROM customer_services WHERE Key_name='uq_customer_service'")->fetch()) {
    $pdo->exec('ALTER TABLE customer_services ADD INDEX idx_subscription_customer (customer_id)');
    $pdo->exec('ALTER TABLE customer_services DROP INDEX uq_customer_service');
}
$pdo->exec("CREATE TABLE IF NOT EXISTS automation_settings (id INT PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 0, channels VARCHAR(100) NOT NULL DEFAULT 'telegram,email', pre_due_days INT NOT NULL DEFAULT 3, overdue_repeat_days INT NOT NULL DEFAULT 7, follow_up_days INT NOT NULL DEFAULT 3)");
$pdo->exec('INSERT IGNORE INTO automation_settings (id) VALUES (1)');
$pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (id INT AUTO_INCREMENT PRIMARY KEY, event_key VARCHAR(191) NOT NULL UNIQUE, billing_id INT NOT NULL, kind VARCHAR(30) NOT NULL, channel VARCHAR(20) NOT NULL, due_date DATE NOT NULL, status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_error VARCHAR(255) NULL, FOREIGN KEY (billing_id) REFERENCES billing(id))");
addAutomationColumn($pdo,'notification_queue','payment_id','INT NULL');
$pdo->exec("CREATE TABLE IF NOT EXISTS subscription_changes (id INT AUTO_INCREMENT PRIMARY KEY, customer_service_id INT NOT NULL, old_plan_id INT NULL, new_plan_id INT NULL, old_rate DECIMAL(10,2) NOT NULL, new_rate DECIMAL(10,2) NOT NULL, changed_by INT NOT NULL, changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (customer_service_id) REFERENCES customer_services(id), FOREIGN KEY (changed_by) REFERENCES users(id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS collection_activities (id INT AUTO_INCREMENT PRIMARY KEY, collection_id INT NOT NULL, status VARCHAR(30) NOT NULL, follow_up_date DATE NULL, remarks VARCHAR(255) NULL, staff_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(collection_id) REFERENCES collections(id), FOREIGN KEY(staff_id) REFERENCES users(id))");
$pdo->exec("INSERT INTO collection_activities(collection_id,status,follow_up_date,remarks,staff_id,created_at) SELECT c.id,c.collection_status,c.follow_up_date,c.remarks,c.updated_by,c.updated_at FROM collections c WHERE NOT EXISTS (SELECT 1 FROM collection_activities a WHERE a.collection_id=c.id)");
echo "Automation migration complete.\n";
