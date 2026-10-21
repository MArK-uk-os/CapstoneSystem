<?php
$u = currentUser();
$activeSection = $moduleSection ?? explode('/', trim(str_replace('/modules/', '', $_SERVER['SCRIPT_NAME']), '/'))[0];
?>
<nav class="sidebar sidebar-offcanvas dynamic-active-class-disabled" id="sidebar">
    <ul class="nav">
        <li class="nav-item nav-profile"><div class="nav-link"><div class="user-wrapper"><div class="profile-image"><img src="<?= htmlspecialchars($u['profile_photo'] ?: '/assets/template/assets/images/faces/face8.jpg') ?>" alt="Profile"></div><div class="text-wrapper"><p class="profile-name"><?= htmlspecialchars($u['full_name']) ?></p><small class="designation text-muted text-capitalize"><?= htmlspecialchars($u['role']) ?></small></div></div></div></li>
        <?php foreach (['dashboard' => ['Dashboard', 'mdi-television'], 'customers' => ['Customers', 'mdi-account-multiple'], 'services' => ['Services', 'mdi-wifi'], 'billing' => ['Billing', 'mdi-receipt'], 'payments' => ['Payments', 'mdi-cash-multiple'], 'collections' => ['Collections', 'mdi-alert-circle-outline'], 'reports' => ['Reports', 'mdi-chart-line']] as $section => [$label, $icon]): $isActive = $section === $activeSection; ?><li class="nav-item<?= $isActive ? ' active' : '' ?>"><a class="nav-link<?= $isActive ? ' active' : '' ?>" href="/modules/<?= $section ?>/index.php"<?= $isActive ? ' aria-current="page"' : '' ?>><i class="menu-icon mdi <?= $icon ?>"></i><span class="menu-title"><?= $label ?></span></a></li><?php endforeach; ?>
        <?php if (hasRole('admin')): $isActive = $activeSection === 'users'; ?><li class="nav-item<?= $isActive ? ' active' : '' ?>"><a class="nav-link<?= $isActive ? ' active' : '' ?>" href="/modules/users/index.php"<?= $isActive ? ' aria-current="page"' : '' ?>><i class="menu-icon mdi mdi-account-multiple"></i><span class="menu-title">User Accounts</span></a></li><?php endif; ?>
        <?php $isActive = $activeSection === 'auth'; ?><li class="nav-item<?= $isActive ? ' active' : '' ?>"><a class="nav-link<?= $isActive ? ' active' : '' ?>" href="/modules/auth/change_password.php"<?= $isActive ? ' aria-current="page"' : '' ?>><i class="menu-icon mdi mdi-key"></i><span class="menu-title">Change Password</span></a></li>
        <?php if (hasRole('admin')): $settingsActive = in_array($modulePath, ['billing/settings.php', 'billing/automation.php'], true); ?><li class="nav-item<?= $settingsActive ? ' active' : '' ?>"><a class="nav-link<?= $settingsActive ? ' active' : '' ?>" href="/modules/billing/settings.php"<?= $settingsActive ? ' aria-current="page"' : '' ?>><i class="menu-icon mdi mdi-settings"></i><span class="menu-title">Settings</span></a></li><?php endif; ?>
    </ul>
</nav>
<div class="main-panel"><div class="content-wrapper">
<?php if ($modulePath !== 'dashboard/index.php'): ?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/modules/dashboard/index.php">Dashboard</a></li><li class="breadcrumb-item<?= $moduleLabel === $moduleSectionLabel ? ' active' : '' ?>"<?= $moduleLabel === $moduleSectionLabel ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($moduleSectionLabel) ?></li><?php if ($moduleLabel !== $moduleSectionLabel): ?><li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($moduleLabel) ?></li><?php endif; ?></ol></nav>
<?php endif; ?>
