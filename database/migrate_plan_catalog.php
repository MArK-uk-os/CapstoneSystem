<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
require_once __DIR__.'/../config/database.php';
$pdo=getDbConnection();
if(!$pdo->query("SHOW COLUMNS FROM internet_plans LIKE 'category'")->fetch())$pdo->exec("ALTER TABLE internet_plans ADD category ENUM('Residential','Business','Lease Line') NOT NULL DEFAULT 'Residential'");
$catalog=[
    [1,'Residential',100,999,1],[4,'Residential',150,1299,1],[5,'Residential',200,1499,1],[6,'Residential',300,1899,1],
    [2,'Business',50,2000,1],[7,'Business',100,2499,1],[8,'Business',200,3499,1],
    [3,'Lease Line',200,10000,1],[9,'Lease Line',500,30000,1],[10,'Lease Line',1000,45000,1],
];
$pdo->beginTransaction();
try {
    foreach($catalog as [$id,$category,$speed,$rate,$enabled]) {
        $query=$pdo->prepare('SELECT * FROM internet_plans WHERE id=?');$query->execute([$id]);$existing=$query->fetch();
        $name=$category.' '.($speed===1000?'1 Gbps':$speed.' Mbps');
        if(!$existing)$pdo->prepare('INSERT INTO internet_plans(id,category,name,speed_mbps,monthly_rate,enabled) VALUES (?,?,?,?,?,?)')->execute([$id,$category,$name,$speed,$rate,$enabled]);
        elseif($existing['speed_mbps']==0 && !$existing['enabled'])$pdo->prepare('UPDATE internet_plans SET category=?,name=?,speed_mbps=?,monthly_rate=?,enabled=? WHERE id=?')->execute([$category,$name,$speed,$rate,$enabled,$id]);
    }
    $pdo->commit();
}catch(Throwable $e){$pdo->rollBack();throw $e;}
echo "Catalog saved. Existing configured plans are preserved.\n";
