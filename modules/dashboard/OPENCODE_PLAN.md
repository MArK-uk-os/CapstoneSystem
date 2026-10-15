# OpenCode Implementation Plan: Disconnection Feature

## Task 1: Database, account connection status + plan flag
Files: new `database/migrate_disconnection.php` (copy the CLI-only + "add column only if missing" style from `database/migrate_automation.php:2-7`).
- Add to `customer_services`: `connection_status` ENUM('current','overdue','for_disconnection','disconnected','reconnected') NOT NULL DEFAULT 'current', `flagged_at` DATE NULL, `disconnected_at` DATE NULL, `reconnected_at` DATE NULL.
- Add `internet_plans.disconnection_applies` TINYINT NOT NULL DEFAULT 1.
- Test: `C:\xampp\php\php.exe database\migrate_disconnection.php`, then `DESCRIBE customer_services;` and `DESCRIBE internet_plans;` in phpMyAdmin. Run it twice to confirm it does not error (idempotent). Commit.

## Task 2: Database, fee support + notification types
Files: new `database/migrate_reconnection_fee.php`.
- Add `billing.charge_type` ENUM('subscription','reconnection_fee') NOT NULL DEFAULT 'subscription' and `automation_settings.reconnection_fee` DECIMAL(10,2) NOT NULL DEFAULT 100.00.
- Change `notifications.type` ENUM to add 'warning','disconnection' (keep existing values).
- Test: `php database\migrate_reconnection_fee.php`; `DESCRIBE billing;`, `DESCRIBE automation_settings;`, `SHOW COLUMNS FROM notifications LIKE 'type';`. Confirm existing billing rows show subscription. Commit.

## Task 3: Backend, synchronize() sets account status and protects the fee
Files: `includes/BillingAutomation.php`, method `synchronize()` (lines 37-42).
1. In the billing-status UPDATE (line 39), add a branch: fee rows are paid if fully paid, otherwise stay unpaid (never overdue).
2. In the collections insert (line 40), add `AND b.charge_type='subscription'` so fees never create follow-ups.
3. Add one new set-based UPDATE on `customer_services` that computes status from the worst unpaid subscription bill: days late 1-2 -> overdue, >=3 -> for_disconnection, none -> current. Never overwrite disconnected / reconnected. Set `flagged_at` only on the day it first becomes for_disconnection.
- Test: `php tests\billing_automation.php` must still pass (it uses temporary tables, so it never touches live data). Then, on live data, set one bill's due_date to 3 days ago, reload the dashboard, and check `customer_services.connection_status` changed to for_disconnection. Commit.

## Task 4: Backend, queue warning/notice and reset after new cycle
Files: `includes/BillingAutomation.php`, method `run()` (lines 57-121).
1. In the reminder loop (lines 92-97), join `customer_services` + `internet_plans` and only process `charge_type='subscription'`. For applicable plans: queue kind `warning` exactly on day 1 and kind `disconnection` exactly on day 3, and skip the old generic overdue reminder for those plans. For non-applicable plans, keep the current behaviour.
2. In the invoice-generation part (around lines 79-86), when a new subscription invoice is created for a service whose status is reconnected, set it back to current (the "next billing cycle returns to Current" rule).
- Test: add `tests\disconnection.php` (same temporary-table pattern as `tests\billing_automation.php:8-11`) proving: day 2 not flagged, day 3 flagged, warning queued on day 1, disconnection queued on day 3, generic overdue not queued for applicable plans, fee never queued. Run with `php tests\disconnection.php`. Commit.

## Task 5: Notifications, new message text
Files: `includes/notifications/NotificationService.php`.
- Add `sendWarning()` (day-1 text from spec) and `sendDisconnectionNotice()` (day-3 text from spec), both logging type warning / disconnection and using the same `send()` helper. In `run()`'s delivery switch (line 113), route kinds warning / disconnection to these methods, and update the "expired" check (line 106) so a warning only sends on day 1 and a notice only on day >= 3.
- Test: `php tests\httpsms.php` still passes. Manually queue one and confirm wording/amount use the outstanding balance. Commit.

## Task 6: Staff actions, disconnect / create fee / reconnect
Files: new `modules/customers/disconnect.php` and `modules/customers/reconnect.php` (follow the CSRF + requireRole style of `modules/payments/verify.php`). Possibly small edits to `includes/header.php` labels.
- Disconnect: only allowed when status is for_disconnection; set disconnected, `disconnected_at`.
- Reconnect: first create the PHP 100 fee row (charge_type='reconnection_fee', amount from `automation_settings.reconnection_fee`, due_date = today, invoice_number like FEE-...). After the fee and all subscription bills are verified paid, set reconnected, `reconnected_at`.
- Test: manually, on a for_disconnection account: click Disconnect, confirm DB values; create fee, verify a payment on the fee, verify balance payments, click Reconnect, confirm status. Commit.

## Task 7: UI, collections and customer pages
Files: `modules/collections/index.php`, `modules/customers/view.php` (and `edit.php` if needed).
- Show the new connection_status badge and add the Disconnect / Reconnect buttons. Keep existing filters working.
- Test: load pages in the browser; confirm badge and buttons appear only for the right statuses. Commit.

## Task 8: Dashboard metrics
Files: `modules/dashboard/index.php`.
- Add counters for accounts for_disconnection, disconnected, and reconnected using `customer_services.connection_status` (next to the existing cards around lines 44-52).
- Test: create accounts in each state, load the dashboard, confirm the numbers. Commit.

## Task 9: Docs + full test pass
Files: `AUTOMATION_SETUP.md`, `AGENTS.md`, `modules/dashboard/DISCONNECTION_SPEC.md` (remove the duplicate "Rule 4" and mark the confirmed items).
- Test: run all three existing tests plus `tests\disconnection.php`, and one manual `php scripts\run_billing.php --queue-only` on a safe copy. Commit.

## Risks (especially for existing automation)
- `synchronize()` runs on every dashboard/billing/collections page load. Keep the new account-status update a single set-based query, or pages get slow.
- Editing the status UPDATE (`BillingAutomation.php:39`) is the riskiest line. A mistake can stop overdue detection or mark paid bills wrong. `tests\billing_automation.php` covers this; run it after every change.
- The fee exclusion must be in all three spots (status rule, collections insert, reminder loop). Miss one and the PHP 100 fee will spam overdue notices.
- Replacing the generic overdue reminder with warning/notice changes current behaviour for every plan, because all plans apply now. Customers stop getting the old repeating overdue message. This is the intended spec change, but confirm with the client.
- Do NOT set `customer_services.status='inactive'` to mean disconnected. That column stops invoice generation (`BillingAutomation.php:65`). Use the new `connection_status`.
- "reconnected" reset timing: if reset too early it hides an unpaid account; if never, it stays forever. The plan resets it when the next cycle's invoice is generated.
- Plan flag only covers plan-based services. Services with plan_id = NULL use billing_amount (`BillingAutomation.php:81`). We treat those as "applies" for now.
- Run migrations while the scheduler is paused. Stop the Task Scheduler job first, take a DB backup, then migrate.
- `notifications.channel` ENUM is only sms,email (`schema.sql:141`) but the code already logs telegram. This is a pre-existing bug. Optional fix: add telegram to that ENUM in Task 2.
- `verify.php` assumes one invoice per payment and rejects overpayment (`verify.php:26`). Recording balance + fee as separate invoices avoids that limit.