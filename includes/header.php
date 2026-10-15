<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : '' ?>Cloud Interweb Billing System</title>
<link rel="shortcut icon" href="/assets/template/assets/images/logo-mini.svg">
<link rel="stylesheet" href="/assets/template/assets/plugins/@mdi/font/css/materialdesignicons.min.css">
<link rel="stylesheet" href="/assets/template/assets/plugins/perfect-scrollbar/perfect-scrollbar.css">
<link rel="stylesheet" href="/assets/template/css/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chosen-js@1.8.7/chosen.min.css">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body data-base-url="/">
<?php
$modulePath = ltrim(str_replace('/modules/', '', $_SERVER['SCRIPT_NAME']), '/');
$moduleLabels = [
    'dashboard/index.php' => 'Dashboard',
    'customers/index.php' => 'Customer Management',
    'customers/add.php' => 'Add Customer',
    'customers/edit.php' => 'Edit Customer',
    'services/index.php' => 'Service Management',
    'services/add.php' => 'Add Service',
    'services/edit.php' => 'Edit Service',
    'billing/index.php' => 'Billing Management',
    'billing/add.php' => 'Generate Billing Record',
    'billing/invoice.php' => 'Invoice',
    'billing/notifications.php' => 'Notification History',
    'billing/settings.php' => 'Notification Settings',
    'payments/index.php' => 'Payment Management',
    'payments/add.php' => 'Record Payment',
    'payments/receipt.php' => 'Payment Receipt',
    'collections/index.php' => 'Collection Monitoring',
    'collections/update.php' => 'Collection Follow-up',
    'reports/index.php' => 'Reports',
    'users/index.php' => 'User Accounts',
    'users/add.php' => 'Add User',
    'users/edit.php' => 'Edit User',
    'auth/change_password.php' => 'Change Password',
];
$moduleLabel = $moduleLabels[$modulePath] ?? ($pageTitle ?? 'Dashboard');
$moduleSection = explode('/', $modulePath)[0] ?? 'dashboard';
$moduleSectionLabels = [
    'dashboard' => 'Dashboard',
    'customers' => 'Customers',
    'services' => 'Services',
    'billing' => 'Billing',
    'payments' => 'Payments',
    'collections' => 'Collections',
    'reports' => 'Reports',
    'users' => 'User Accounts',
    'auth' => 'Account',
];
$moduleSectionLabel = $moduleSectionLabels[$moduleSection] ?? 'System';
?>
<div class="container-scroller">
<nav class="navbar default-layout col-lg-12 col-12 p-0 fixed-top d-flex flex-row">
    <div class="text-center navbar-brand-wrapper d-flex align-items-top justify-content-center">
        <a class="navbar-brand brand-logo vendor-brand" href="/modules/dashboard/index.php">Cloud<span>Interweb</span></a>
        <a class="navbar-brand brand-logo-mini vendor-brand-mini" href="/modules/dashboard/index.php">CI</a>
    </div>
    <?php if (isLoggedIn()): $u = currentUser(); ?>
    <div class="navbar-menu-wrapper d-flex align-items-center justify-content-end">
        <button class="navbar-toggler navbar-toggler align-self-center" type="button" data-toggle="minimize"><span class="mdi mdi-menu"></span></button>
        <ul class="navbar-nav navbar-nav-left header-links"><li class="nav-item d-none d-md-flex"><span class="nav-link"><i class="mdi mdi-view-dashboard"></i> <?= htmlspecialchars($moduleLabel) ?></span></li></ul>
        <ul class="navbar-nav navbar-nav-right"><li class="nav-item dropdown d-none d-md-flex"><a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown"><span class="profile-text"><?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['role']) ?>)</span><img src="<?= htmlspecialchars($u['profile_photo'] ?: '/assets/template/assets/images/faces/face8.jpg') ?>" alt="Profile photo" style="width:32px;height:32px;border-radius:50%;object-fit:cover;margin-left:10px;" onerror="this.onerror=null;this.src='/assets/template/assets/images/faces/face8.jpg';"></a><div class="dropdown-menu dropdown-menu-right navbar-dropdown"><a class="dropdown-item" href="/modules/auth/change_password.php"><i class="mdi mdi-key"></i> Change Password</a><a class="dropdown-item" href="/modules/auth/logout.php"><i class="mdi mdi-logout"></i> Sign Out</a></div></li></ul>
        <a class="d-md-none text-dark me-3" href="/modules/auth/logout.php"><i class="mdi mdi-logout mdi-24px"></i></a>
        <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center" type="button" data-toggle="offcanvas"><span class="mdi mdi-menu icon-menu"></span></button>
    </div>
    <?php endif; ?>
</nav>
<div class="container-fluid page-body-wrapper">
