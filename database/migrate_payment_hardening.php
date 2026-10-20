<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();

$duplicates = $pdo->query("SELECT receipt_number, COUNT(*) AS total FROM payments WHERE receipt_number IS NOT NULL GROUP BY receipt_number HAVING total > 1")->fetchAll();
if ($duplicates) {
    $values = implode(', ', array_map(static fn(array $row): string => (string) $row['receipt_number'], $duplicates));
    fwrite(STDERR, "Cannot add the unique receipt index; duplicate receipt numbers exist: $values\n");
    exit(1);
}

if (!$pdo->query("SHOW INDEX FROM payments WHERE Key_name='uq_payments_receipt_number'")->fetch()) {
    $pdo->exec('ALTER TABLE payments ADD UNIQUE INDEX uq_payments_receipt_number (receipt_number)');
}
if (!$pdo->query("SHOW INDEX FROM payments WHERE Key_name='idx_payments_billing_reference'")->fetch()) {
    $pdo->exec('ALTER TABLE payments ADD INDEX idx_payments_billing_reference (billing_id, reference_number)');
}

echo "Payment hardening migration complete.\n";
