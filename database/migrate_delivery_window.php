<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$pdo = getDbConnection();
foreach ([
    'delivery_window_start' => "TIME NOT NULL DEFAULT '08:00:00'",
    'delivery_window_end' => "TIME NOT NULL DEFAULT '20:00:00'",
] as $name => $definition) {
    if (!$pdo->query("SHOW COLUMNS FROM automation_settings LIKE '$name'")->fetch()) {
        $pdo->exec("ALTER TABLE automation_settings ADD `$name` $definition");
    }
}
echo "Delivery window settings ready (default 08:00-20:00 Asia/Manila).\n";
