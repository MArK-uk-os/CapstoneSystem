<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__.'/../config/database.php';
$pdo=getDbConnection();
foreach (['disconnection_days'=>'INT NOT NULL DEFAULT 2','disconnection_fee'=>'DECIMAL(10,2) NOT NULL DEFAULT 100.00'] as $name=>$definition) {
    if (!$pdo->query("SHOW COLUMNS FROM automation_settings LIKE '$name'")->fetch()) $pdo->exec("ALTER TABLE automation_settings ADD $name $definition");
}
$pdo->exec("ALTER TABLE customer_services MODIFY status ENUM('active','inactive','disconnected') NOT NULL DEFAULT 'active'");
if (!$pdo->query("SHOW COLUMNS FROM billing LIKE 'disconnection_charge'")->fetch()) $pdo->exec('ALTER TABLE billing ADD disconnection_charge DECIMAL(10,2) NOT NULL DEFAULT 0');
$pdo->exec("CREATE TABLE IF NOT EXISTS service_disconnections (
 id INT AUTO_INCREMENT PRIMARY KEY,
 customer_service_id INT NOT NULL,
 overdue_billing_id INT NOT NULL,
 charge DECIMAL(10,2) NOT NULL,
 field_worker VARCHAR(100) NOT NULL,
 remarks VARCHAR(1000) NULL,
 recorded_by INT NOT NULL,
 disconnected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reconnected_at DATETIME NULL,
 reconnected_by INT NULL,
 billed_invoice_id INT NULL,
 INDEX pending_charge(customer_service_id,billed_invoice_id),
 FOREIGN KEY(customer_service_id) REFERENCES customer_services(id),
 FOREIGN KEY(overdue_billing_id) REFERENCES billing(id),
 FOREIGN KEY(recorded_by) REFERENCES users(id),
 FOREIGN KEY(billed_invoice_id) REFERENCES billing(id)
) ENGINE=InnoDB");
echo "Disconnection settings, history, and invoice charges ready.\n";
