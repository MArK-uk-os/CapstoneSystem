<?php
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

if (!tableExists($pdo, 'customer_services')) {
    $pdo->exec("CREATE TABLE customer_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        service_id INT NOT NULL,
        billing_amount DECIMAL(10,2) NOT NULL,
        billing_frequency ENUM('monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
        start_date DATE NOT NULL,
        next_billing_date DATE DEFAULT NULL,
        due_days INT NOT NULL DEFAULT 14,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_customer_service (customer_id, service_id),
        CONSTRAINT fk_customer_services_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
        CONSTRAINT fk_customer_services_service FOREIGN KEY (service_id) REFERENCES services(id)
    ) ENGINE=InnoDB");
}

if (!columnExists($pdo, 'billing', 'customer_service_id')) {
    $pdo->exec('ALTER TABLE billing ADD COLUMN customer_service_id INT DEFAULT NULL AFTER customer_id');
    $pdo->exec('ALTER TABLE billing ADD CONSTRAINT fk_billing_customer_service FOREIGN KEY (customer_service_id) REFERENCES customer_services(id)');
}

if (!columnExists($pdo, 'collections', 'updated_by')) {
    $pdo->exec('ALTER TABLE collections ADD COLUMN updated_by INT DEFAULT NULL AFTER remarks');
    $pdo->exec('ALTER TABLE collections ADD CONSTRAINT fk_collections_staff FOREIGN KEY (updated_by) REFERENCES users(id)');
}

$pdo->exec("INSERT IGNORE INTO customer_services (customer_id, service_id, billing_amount, start_date, next_billing_date, status)
    SELECT c.id, c.service_id, s.monthly_fee, DATE(c.created_at), DATE(c.created_at), c.status
    FROM customers c JOIN services s ON s.id = c.service_id");

$pdo->exec("UPDATE billing b
    JOIN customers c ON c.id = b.customer_id
    JOIN customer_services cs ON cs.customer_id = c.id AND cs.service_id = c.service_id
    SET b.customer_service_id = cs.id
    WHERE b.customer_service_id IS NULL");

// customers.service_id is a deprecated legacy link now that services live in
// customer_services; relax it to nullable so new customers no longer need one.
$column = $pdo->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'service_id'")->fetch();
if ($column && $column['IS_NULLABLE'] === 'NO') {
    $pdo->exec('ALTER TABLE customers MODIFY COLUMN service_id INT DEFAULT NULL');
}

echo "Multi-service customer migration completed.\n";