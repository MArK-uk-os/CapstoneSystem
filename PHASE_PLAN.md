# Billing and Collection Management System — 4-Phase Build Plan
Cloud Interweb Network and Data Solution | Stack: HTML/CSS/JS/Bootstrap, PHP, MySQL, XAMPP, Semaphore (SMS), PHPMailer (Email)

Derived from the capstone's Modified Waterfall Model (Requirements → Design → Implementation → Testing → Deployment → Maintenance), condensed into 4 practical build phases.

## Phase 1 — Foundation, Authentication & Records Management
Folder: `modules/auth`, `modules/customers`, `modules/services`
- Project setup (XAMPP, folder structure, DB connection)
- Database schema: `users`, `customers`, `services`, `billing`, `payments`, `collections`, `notifications`
- User Authentication (login, logout, session, role-based access)
- Customer Management (add, update, view, search, delete)
- Service/Plan Management (Wi-Fi Installation, Vendo Wi-Fi, CCTV plans — fee, description, status)
- Base UI layout (navbar, sidebar, dashboard shell) with Bootstrap

## Phase 2 — Billing & Notification Management (Complete)
Folder: `modules/billing`, `includes/notifications`
- Billing record generation (invoice no., billing period, due date, amount) linked to customer + service
- Electronic invoice generation (printable/PDF view)
- Billing notifications via SMS (Semaphore API) and Email (PHPMailer)
- Notification history logging (Sent / Delivered / Failed, channel, date)
- Billing list, search, and filter by status/period

## Phase 3 — Payment & Collection Management (Complete)
Folder: `modules/payments`, `modules/collections`
- Payment recording (amount, method, reference number, proof of payment)
- Payment verification workflow by staff
- Auto account/billing status update after verified payment
- Electronic receipt generation + payment confirmation notification (SMS/email)
- Collection monitoring: overdue detection, days-past-due calculation, collection status, follow-up remarks/schedule
- Overdue notice dispatch (SMS/email)

## Phase 4 — Reporting, Dashboard, Testing & Deployment (Complete)
Folder: `modules/reports`, `modules/dashboard`
- Dashboard statistics: total customers, active accounts, paid/unpaid/overdue counts, monthly collections, revenue
- Reports: billing, payment, collection, and overdue account reports (export/print)
- Cross-module record search & retrieval
- Functional/UAT testing against SRS functional & non-functional requirements
- Deployment prep (XAMPP → live server), backup/recovery routine, user manual, maintenance plan

## Database Entities (from ERD)
`users`, `customers`, `services`, `billing`, `payments`, `collections`, `notifications`
