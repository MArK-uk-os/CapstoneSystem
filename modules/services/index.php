<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$plans=getDbConnection()->query('SELECT * FROM internet_plans ORDER BY category,speed_mbps')->fetchAll();
$pageTitle='Internet Plans';
include __DIR__.'/../../includes/header.php';include __DIR__.'/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h3 class="mb-0">Internet Plans</h3><p class="text-muted mb-0">Residential, Business, and Lease Line internet connections.</p></div><?php if(hasRole('admin')): ?><a class="btn btn-primary" href="plans.php">Manage Internet Plans</a><?php endif; ?></div>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Category</th><th>Plan</th><th>Speed</th><th>Monthly Rate</th><th>Status</th></tr></thead><tbody><?php foreach($plans as $plan): ?><tr><td><?= htmlspecialchars($plan['category']) ?></td><td><?= htmlspecialchars($plan['name']) ?></td><td>Up to <?= (int)$plan['speed_mbps'] ?> Mbps</td><td>PHP <?= number_format((float)$plan['monthly_rate'],2) ?></td><td><?= $plan['enabled']?'Available':'Disabled' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
