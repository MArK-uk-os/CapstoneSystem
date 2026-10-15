<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
require_once __DIR__.'/../config/database.php';
$pdo=getDbConnection();
foreach(['send_time'=>"TIME NOT NULL DEFAULT '08:00:00'",'last_scheduled_date'=>'DATE NULL'] as $name=>$definition){
    if(!$pdo->query("SHOW COLUMNS FROM automation_settings LIKE '$name'")->fetch())$pdo->exec("ALTER TABLE automation_settings ADD `$name` $definition");
}
echo "Daily notification time configured (default 08:00 Asia/Manila).\n";
