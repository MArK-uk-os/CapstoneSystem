-- Billing and Collection Management System
-- Cloud Interweb Network and Data Solution
-- Database Schema (Phase 1)

CREATE DATABASE IF NOT EXISTS billing_collection_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE billing_collection_system;

-- ==========================================================
-- USERS (authorized staff accounts)
-- ==========================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    profile_photo VARCHAR(255) DEFAULT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==========================================================
-- SERVICES (Wi-Fi Installation, Vendo Wi-Fi, CCTV plans)
-- ==========================================================
CREATE TABLE services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(100) NOT NULL,
    monthly_fee DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==========================================================
-- CUSTOMERS
-- ==========================================================
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_number VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    -- Deprecated: legacy single-service link kept only for pre-multi-service
    -- records. New customers are not required to have a service here;
    -- service subscriptions live in customer_services instead.
    service_id INT DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customers_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

-- Customer service subscriptions. Customers may subscribe to multiple services.
CREATE TABLE customer_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    service_id INT NOT NULL,
    billing_amount DECIMAL(10,2) NOT NULL,
    billing_frequency ENUM('monthly','quarterly','annually') NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,
    next_billing_date DATE DEFAULT NULL,
    due_days INT NOT NULL DEFAULT 14,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_service (customer_id, service_id),
    CONSTRAINT fk_customer_services_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_customer_services_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

-- ==========================================================
-- BILLING (Phase 2)
-- ==========================================================
CREATE TABLE billing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    customer_service_id INT DEFAULT NULL,
    billing_period_start DATE NOT NULL,
    billing_period_end DATE NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('unpaid','paid','overdue') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_billing_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ,CONSTRAINT fk_billing_customer_service FOREIGN KEY (customer_service_id) REFERENCES customer_services(id)
) ENGINE=InnoDB;

-- ==========================================================
-- PAYMENTS (Phase 3)
-- ==========================================================
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    billing_id INT NOT NULL,
    payment_date DATE NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    reference_number VARCHAR(50) DEFAULT NULL,
    proof_of_payment VARCHAR(255) DEFAULT NULL,
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    receipt_number VARCHAR(30) DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_billing FOREIGN KEY (billing_id) REFERENCES billing(id),
    CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ==========================================================
-- COLLECTIONS (Phase 3)
-- ==========================================================
CREATE TABLE collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    billing_id INT NOT NULL,
    customer_id INT NOT NULL,
    outstanding_balance DECIMAL(10,2) NOT NULL,
    overdue_days INT NOT NULL DEFAULT 0,
    collection_status ENUM('pending','in_progress','settled') NOT NULL DEFAULT 'pending',
    follow_up_date DATE DEFAULT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_collections_billing FOREIGN KEY (billing_id) REFERENCES billing(id),
    CONSTRAINT fk_collections_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_collections_staff FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ==========================================================
-- NOTIFICATIONS (Phase 2/3)
-- ==========================================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    billing_id INT DEFAULT NULL,
    type ENUM('billing','payment_confirmation','overdue') NOT NULL,
    channel ENUM('sms','email','telegram') NOT NULL,
    status ENUM('processing','sent','delivered','failed') NOT NULL DEFAULT 'sent',
    request_key VARCHAR(64) DEFAULT NULL,
    requested_by INT DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notifications_request_key (request_key),
    KEY idx_notifications_manual_lookup (billing_id, type, channel, status, sent_at),
    CONSTRAINT fk_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_notifications_billing FOREIGN KEY (billing_id) REFERENCES billing(id)
) ENGINE=InnoDB;

-- ==========================================================
-- Seed data
-- ==========================================================
-- NOTE: The default admin account is created by running database/seed.php
-- (it hashes the password securely with PHP's password_hash()).

INSERT INTO services (service_name, monthly_fee, description) VALUES
('Wi-Fi Installation', 799.00, 'Residential/commercial Wi-Fi internet subscription'),
('Vendo Wi-Fi', 0.00, 'Coin/load-operated public Wi-Fi vending service'),
('CCTV Installation', 1500.00, 'CCTV camera installation and monitoring service');
