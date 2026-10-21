# AGENTS.md

Billing & Collection Management System (Cloud Interweb) — vanilla PHP + MySQL, XAMPP-hosted, Bootstrap admin theme. No PHP framework, no router; each `modules/<area>/*.php` file is a page reached directly by URL.

## Environment gotchas (verify before trusting defaults)
- MySQL port is **3307**, not XAMPP's default 3306 (`config/database.php`). Timezone is forced to `Asia/Manila` (`+08:00`).
- Edit `config/database.php`. `config/database_1.php` is a stale, unreferenced duplicate.
- Code uses root-absolute URLs (`/modules/...`, `/assets/...`), so it must be served with the **project root as the document root**. The README's `http://localhost/CapstoneSystem/` path will 404 those links.
- `config/notifications.local.php` is committed and contains live provider secrets. The README says to copy `config/notifications.local.php.example`, but **no `.example` file exists**. Never commit additional secrets.
- `template/` is the upstream Laravel admin-theme source (own `webpack.mix.js`/`readme.md`) and is **not loaded at runtime**. The running theme is `assets/template/`; app-specific CSS/JS are `assets/css/style.css` and `assets/js/app.js`. Editing `template/` has no effect.
- No build/lint/typecheck/CI. Composer is only used for PHPMailer (`composer install`). There is no autoloader for app code — files are `require_once`'d by relative path.

## Commands (use XAMPP's CLI: `C:\xampp\php\php.exe`)
- Seed default admin (once): `php database\seed.php`
- Migrations (CLI-only, reject web access, preserve data; run on existing DB): `php database\migrate_*.php`. Order matters — `migrate_automation.php` before `migrate_plan_catalog.php`.
- Automation worker: `php scripts\run_billing.php --scheduled` must run **every minute** via Windows Task Scheduler. `install-notification-task.bat` (root) installs/updates the task; keep `scripts/install_notification_task.ps1` alongside it.
  - `--queue-only` generates invoices + queues notifications but does not send — it still **mutates billing records**.
  - Running with no flag is a manual run that bypasses the daily send time.
- Isolated tests (plain PHP, throw on failure, no PHPUnit/network/live data): `php tests\billing_automation.php`, `php tests\notification_time.php`, `php tests\httpsms.php`. `billing_automation.php` shadows live tables with temporary tables on its connection.

## Architecture
- `includes/auth.php` — session bootstrap + guard. Every protected page starts with `require_once includes/auth.php;` then `requireLogin()` or `requireRole('admin')`. Provides `hasRole`, `currentUser`, and CSRF helpers `csrfToken()` / `verifyCsrfToken()`.
- `includes/BillingAutomation.php` — core engine: `synchronize()` recomputes billing/collection status from verified payments, `run()/runScheduled()` generate invoices and drive the notification queue. `includes/notifications/NotificationService.php` — channel sending (email/SMS providers: semaphore, httpsms, infobip).
- `DB` access via `getDbConnection()` (singleton PDO, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, no emulated prepares). Use prepared statements.
- Multi-service model: subscriptions live in `customer_services` (each has its own `service_account_number`); `customers.service_id` is legacy. Billing stores `service_name_snapshot` / `account_number_snapshot`. Plan catalog is `internet_plans` (managed at `modules/services/plans.php`).
- Status conventions: `billing.status` = `unpaid|paid|overdue`; collection status in `collections.collection_status`. Balances count only `payments.verification_status='verified'`.

## Authoritative docs
- `AUTOMATION_SETUP.md` — billing automation, migrations, scheduling, notification rules.
- `modules/dashboard/DISCONNECTION_SPEC.md` — disconnection rules/statuses, fees, message days.
- `README.md` / `PHASE_PLAN.md` — setup and phase breakdown.
