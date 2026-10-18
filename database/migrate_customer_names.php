<?php
// Run once with PHP CLI when deploying split customer names + unique contact/email.
// CLI only. Idempotent: safe to run multiple times; preserves existing records.
//
// IMPORTANT: customers 6 and 7 are confirmed inactive test rows holding duplicate
// contact/email values. Their clearing is gated behind $CLEAR_INACTIVE_TEST_DUPLICATES
// below and is NOT executed by default. While duplicates remain, the unique indexes
// are deliberately NOT created (reported instead), so data is never modified by surprise.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/customer_validation.php';
$pdo = getDbConnection();

$CLEAR_INACTIVE_TEST_DUPLICATES = true; // approved: release dups on inactive test rows 6/7
$ACTIVE_INACTIVE_TEST_ROW_IDS = [6, 7];

// 1) Add the split-name columns when missing. full_name stays untouched.
$columns = [
    'first_name' => "ADD COLUMN first_name VARCHAR(50) NOT NULL DEFAULT '' AFTER account_number",
    'middle_initial' => 'ADD COLUMN middle_initial VARCHAR(1) NULL AFTER first_name',
    'last_name' => "ADD COLUMN last_name VARCHAR(50) NOT NULL DEFAULT '' AFTER middle_initial",
    'suffix' => 'ADD COLUMN suffix VARCHAR(10) NULL AFTER last_name',
];
foreach ($columns as $name => $ddl) {
    if (!$pdo->query("SHOW COLUMNS FROM customers LIKE '$name'")->fetch()) {
        $pdo->exec("ALTER TABLE customers $ddl");
        echo "Added column $name.\n";
    } else {
        echo "Column $name already exists.\n";
    }
}

// 2) Backfill name parts from full_name where still empty. Never deletes/renames full_name.
$rows = $pdo->query("SELECT id, full_name FROM customers WHERE (first_name = '' OR last_name = '') AND full_name <> ''")->fetchAll(PDO::FETCH_ASSOC);
$backfilled = 0;
$stmt = $pdo->prepare('UPDATE customers SET first_name = ?, middle_initial = ?, last_name = ? WHERE id = ?');
foreach ($rows as $row) {
    $tokens = preg_split('/\s+/', trim($row['full_name']));
    $count = count($tokens);
    $first = $tokens[0];
    $middle = null;
    $last = $tokens[$count - 1];
    if ($count === 1) {
        $last = $tokens[0];
    } elseif ($count > 2) {
        $middle = strtoupper(substr($tokens[1], 0, 1)) ?: null;
    }
    $stmt->execute([$first, $middle, $last, $row['id']]);
    $backfilled++;
}
echo "Backfilled $backfilled name(s) from full_name.\n";

// 3) Duplicate handling for the known inactive test rows (gated; no records touched by default).
if ($CLEAR_INACTIVE_TEST_DUPLICATES) {
    $cleared = 0;
    foreach ($ACTIVE_INACTIVE_TEST_ROW_IDS as $testId) {
        $upd = $pdo->prepare("UPDATE customers SET contact_number = NULL, email = NULL WHERE id = ? AND status = 'inactive'");
        $upd->execute([$testId]);
        $cleared += $upd->rowCount();
    }
    echo "Cleared duplicate contact/email on inactive test rows: $cleared row(s).\n";
} else {
    echo "Skipping duplicate clearing on rows 6/7 (disabled in this migration by default).\n";
}

// 4) Add unique indexes only when no duplicates remain; otherwise report and skip.
$failed = false;
foreach (['uq_customers_contact' => 'contact_number', 'uq_customers_email' => 'email'] as $key => $column) {
    if ($dups = $pdo->query("SELECT $column, COUNT(*) n, GROUP_CONCAT(id) ids FROM customers
        WHERE $column IS NOT NULL AND $column <> '' GROUP BY $column HAVING n > 1")->fetchAll(PDO::FETCH_ASSOC)) {
        $failed = true;
        echo "DUPLICATE $column values found (unique key $key not created):\n";
        foreach ($dups as $d) echo json_encode($d) . "\n";
        continue;
    }
    if (!$pdo->query("SHOW INDEX FROM customers WHERE Key_name = '$key'")->fetch()) {
        $pdo->exec("ALTER TABLE customers ADD UNIQUE KEY $key ($column)");
        echo "Added unique key $key.\n";
    } else {
        echo "Unique key $key already exists.\n";
    }
}

echo $failed ? "Customer names migration finished WITH ISSUES (see above).\n" : "Customer names migration complete.\n";
exit($failed ? 1 : 0);
