<?php
require_once __DIR__.'/../../includes/auth.php'; requireRole('admin');
$pdo=getDbConnection(); $notice='';
$catalog=$pdo->query('SELECT * FROM internet_plans ORDER BY category,speed_mbps')->fetchAll();
if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrfToken($_POST['csrf_token']??null)) {
    $plans=[];
    foreach($catalog as $option) {
        $id=$option['id'];
        $name=trim($_POST['name'][$id]??''); $speed=filter_var($_POST['speed'][$id]??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]); $rate=$_POST['rate'][$id]??'';
        if(!$name || strlen($name)>100 || $speed===false || !is_numeric($rate) || (float)$rate<=0 || (float)$rate>99999999) { $notice='All three plans require a name, speed, and positive monthly rate.'; break; }
        $plans[]=[$name,$speed,$rate,isset($_POST['enabled'][$id])?1:0,$id];
    }
    if(!$notice) { $pdo->beginTransaction(); try { foreach($plans as $plan)$pdo->prepare('UPDATE internet_plans SET name=?,speed_mbps=?,monthly_rate=?,enabled=? WHERE id=?')->execute($plan); $pdo->commit(); $notice='Plan options saved. Rates apply to future invoices.'; } catch(Throwable $e){$pdo->rollBack();throw $e;} }
}
$plans=$pdo->query('SELECT * FROM internet_plans ORDER BY category,speed_mbps')->fetchAll();
$pageTitle='Internet Plans'; include __DIR__.'/../../includes/header.php'; include __DIR__.'/../../includes/sidebar.php';
?>
<h3>Three internet plan categories</h3><p>Residential, Business, and Lease Line, each with speed and price options. 1 Gbps is stored as 1,000 Mbps. Disabled options cannot be selected for new subscriptions.</p><?php if($notice): ?><div class="alert alert-info"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><table class="table"><thead><tr><th>Enabled</th><th>Category</th><th>Name</th><th>Speed (Mbps)</th><th>Monthly rate (PHP)</th></tr></thead><tbody><?php foreach($plans as $p): ?><tr><td><input type="checkbox" name="enabled[<?= $p['id'] ?>]" <?= $p['enabled']?'checked':'' ?>></td><td><?= htmlspecialchars($p['category']) ?></td><td><input class="form-control" name="name[<?= $p['id'] ?>]" value="<?= htmlspecialchars($p['name']) ?>" required></td><td><input class="form-control" type="number" min="1" name="speed[<?= $p['id'] ?>]" value="<?= $p['speed_mbps'] ?>" required></td><td><input class="form-control" type="number" min="0.01" step="0.01" name="rate[<?= $p['id'] ?>]" value="<?= $p['monthly_rate'] ?>" required></td></tr><?php endforeach; ?></tbody></table><button class="btn btn-primary">Save plan options</button></form>
<?php include __DIR__.'/../../includes/footer.php'; ?>
