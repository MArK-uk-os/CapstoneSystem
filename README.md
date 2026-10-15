# Billing and Collection Management System
Cloud Interweb Network and Data Solution — Capstone Project

## Setup (XAMPP)
1. Install [XAMPP](https://www.apachefriends.org/) if not already installed.
2. Copy this folder into `C:\xampp\htdocs\CapstoneSystem` (or symlink it).
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Open `http://localhost/phpmyadmin`, then import [database/schema.sql](database/schema.sql) to create the `billing_collection_system` database.
5. Run the seed script once to create the default admin login:
   - Via browser: `http://localhost/CapstoneSystem/database/seed.php`
   - Or via CLI (XAMPP's php.exe): `C:\xampp\php\php.exe database\seed.php`
6. Visit `http://localhost/CapstoneSystem/` and log in with:
   - Username: `admin`
   - Password: `admin123` (change it via the **Change Password** link after first login)

## Billing Notifications
1. Install [Composer](https://getcomposer.org/) and run `composer install` from the project root to install PHPMailer.
2. Copy [config/notifications.local.php.example](config/notifications.local.php.example) to `config/notifications.local.php`, then add your Semaphore API key and SMTP credentials. Alternatively, configure the `SEMAPHORE_API_KEY`, `SEMAPHORE_SENDER_NAME`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, and `SMTP_FROM_NAME` environment variables before starting Apache.
3. As an administrator, open **Billing > Notifications** to confirm SMS and email readiness. Generate a billing record, open its invoice, then use **Send SMS** or **Send Email**. Every attempt appears in the invoice's notification history; failed attempts indicate missing configuration or a provider error.

## Project Structure
```
config/       Database connection config
database/     schema.sql (DDL) + seed.php (default admin)
includes/     Shared auth guard, header/sidebar/footer layout
modules/      One folder per feature area (auth, customers, services, users, billing, payments, collections, reports, dashboard)
assets/       CSS/JS
```

## Build Phases
See [PHASE_PLAN.md](PHASE_PLAN.md) for the full 4-phase breakdown.

- **Phase 1 (done):** project setup, DB schema, authentication with role-based access (admin/staff), user account management, change password, customer management, service management, dashboard shell.
- **Phase 2 (done):** Billing records, printable invoices, SMS/email billing notifications, notification history, billing search and filters.
- **Phase 3 (done):** Payment recording and verification, printable receipts, automatic billing updates, payment confirmations, collection monitoring, follow-up tracking, and overdue notices.
- **Phase 4 (done):** Dashboard statistics, filtered billing/payment/collection/overdue reports, CSV export, and print views.
