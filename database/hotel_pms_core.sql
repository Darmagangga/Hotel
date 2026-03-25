SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS journal_lines;
DROP TABLE IF EXISTS journals;
DROP TABLE IF EXISTS payment_allocations;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoice_lines;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS room_inventory_issues;
DROP TABLE IF EXISTS inventory_movements;
DROP TABLE IF EXISTS inventory_purchase_lines;
DROP TABLE IF EXISTS inventory_purchases;
DROP TABLE IF EXISTS inventory_items;
DROP TABLE IF EXISTS booking_addons;
DROP TABLE IF EXISTS boat_tickets;
DROP TABLE IF EXISTS island_tours;
DROP TABLE IF EXISTS scooter_catalog;
DROP TABLE IF EXISTS boat_companies;
DROP TABLE IF EXISTS activity_operators;
DROP TABLE IF EXISTS transport_drivers;
DROP TABLE IF EXISTS booking_rooms;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS room_types;
DROP TABLE IF EXISTS guests;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS coa_accounts;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guest_code VARCHAR(50) NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    identity_type VARCHAR(50) NULL,
    identity_number VARCHAR(100) NULL,
    nationality VARCHAR(100) NULL,
    address TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coa_accounts (
    code VARCHAR(20) PRIMARY KEY,
    account_name VARCHAR(150) NOT NULL,
    category ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    normal_balance ENUM('debit', 'credit') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    capacity INT NOT NULL DEFAULT 2,
    base_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
    description TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_type_id BIGINT UNSIGNED NOT NULL,
    room_code VARCHAR(50) NOT NULL UNIQUE,
    room_name VARCHAR(150) NOT NULL,
    floor VARCHAR(20) NULL,
    status ENUM('available', 'occupied', 'dirty', 'cleaning', 'blocked', 'maintenance', 'inactive') NOT NULL DEFAULT 'available',
    coa_receivable_code VARCHAR(20) NULL,
    coa_revenue_code VARCHAR(20) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_rooms_room_type
        FOREIGN KEY (room_type_id) REFERENCES room_types(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_rooms_coa_receivable
        FOREIGN KEY (coa_receivable_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_rooms_coa_revenue
        FOREIGN KEY (coa_revenue_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(50) NOT NULL UNIQUE,
    guest_id BIGINT UNSIGNED NOT NULL,
    source ENUM('direct', 'airbnb', 'booking.com', 'agoda', 'traveloka', 'walk_in', 'other') NOT NULL DEFAULT 'direct',
    status ENUM('draft', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show') NOT NULL DEFAULT 'confirmed',
    check_in_at DATETIME NOT NULL,
    check_out_at DATETIME NOT NULL,
    room_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    addon_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_bookings_guest
        FOREIGN KEY (guest_id) REFERENCES guests(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    adult_count INT NOT NULL DEFAULT 1,
    child_count INT NOT NULL DEFAULT 0,
    rate DECIMAL(14,2) NOT NULL DEFAULT 0,
    check_in_at DATETIME NOT NULL,
    check_out_at DATETIME NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_booking_room (booking_id, room_id),
    CONSTRAINT fk_booking_rooms_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_booking_rooms_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_drivers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_name VARCHAR(150) NOT NULL,
    vehicle_name VARCHAR(100) NULL,
    pickup_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    dropoff_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_operators (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operator_name VARCHAR(150) NOT NULL,
    default_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE boat_companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scooter_catalog (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scooter_type VARCHAR(100) NOT NULL,
    operator_id BIGINT UNSIGNED NULL,
    daily_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_scooter_catalog_operator
        FOREIGN KEY (operator_id) REFERENCES activity_operators(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE island_tours (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    destination VARCHAR(150) NOT NULL,
    driver_id BIGINT UNSIGNED NULL,
    price DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_island_tours_driver
        FOREIGN KEY (driver_id) REFERENCES transport_drivers(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE boat_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    destination VARCHAR(150) NOT NULL,
    ticket_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_boat_tickets_company
        FOREIGN KEY (company_id) REFERENCES boat_companies(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_addons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    addon_type ENUM('transport', 'scooter', 'island_tour', 'boat_ticket') NOT NULL,
    reference_id BIGINT UNSIGNED NULL,
    service_date DATE NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    qty INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('planned', 'confirmed', 'completed', 'cancelled') NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_booking_addons_booking (booking_id),
    KEY idx_booking_addons_type_ref (addon_type, reference_id),
    CONSTRAINT fk_booking_addons_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    invoice_date DATE NOT NULL,
    due_date DATE NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('draft', 'unpaid', 'partial', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_invoices_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    line_type ENUM('room', 'addon', 'other') NOT NULL DEFAULT 'other',
    reference_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    service_date DATE NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    coa_revenue_code VARCHAR(20) NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_invoice_lines_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_invoice_lines_coa_revenue
        FOREIGN KEY (coa_revenue_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number VARCHAR(50) NOT NULL UNIQUE,
    guest_id BIGINT UNSIGNED NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'credit_card', 'debit_card', 'qris', 'other') NOT NULL DEFAULT 'cash',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    cash_bank_coa_code VARCHAR(20) NULL,
    reference_number VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_payments_guest
        FOREIGN KEY (guest_id) REFERENCES guests(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_payments_cash_bank_coa
        FOREIGN KEY (cash_bank_coa_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    allocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_payment_allocations_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_payment_allocations_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(100) NOT NULL UNIQUE,
    item_name VARCHAR(150) NOT NULL,
    category ENUM('consumable', 'linen', 'cleaning_supply', 'maintenance', 'other') NOT NULL DEFAULT 'other',
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    standard_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    min_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
    inventory_coa_code VARCHAR(20) NULL,
    expense_coa_code VARCHAR(20) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_inventory_items_inventory_coa
        FOREIGN KEY (inventory_coa_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_items_expense_coa
        FOREIGN KEY (expense_coa_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_number VARCHAR(50) NOT NULL UNIQUE,
    purchase_date DATE NOT NULL,
    supplier_name VARCHAR(150) NULL,
    payment_coa_code VARCHAR(20) NULL,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_inventory_purchases_payment_coa
        FOREIGN KEY (payment_coa_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_purchases_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_purchase_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_inventory_purchase_lines_purchase
        FOREIGN KEY (purchase_id) REFERENCES inventory_purchases(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_purchase_lines_item
        FOREIGN KEY (item_id) REFERENCES inventory_items(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    movement_date DATETIME NOT NULL,
    movement_type ENUM('purchase', 'issue_room', 'adjustment_in', 'adjustment_out', 'return') NOT NULL,
    qty_in DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_out DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_inventory_movements_item_date (item_id, movement_date),
    CONSTRAINT fk_inventory_movements_item
        FOREIGN KEY (item_id) REFERENCES inventory_items(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_inventory_issues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    issue_date DATE NOT NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    expense_coa_code VARCHAR(20) NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_room_inventory_issues_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_room_inventory_issues_item
        FOREIGN KEY (item_id) REFERENCES inventory_items(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_room_inventory_issues_expense_coa
        FOREIGN KEY (expense_coa_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_room_inventory_issues_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_number VARCHAR(50) NOT NULL UNIQUE,
    journal_date DATE NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    source ENUM('manual', 'invoice', 'payment', 'inventory_purchase', 'inventory_issue', 'system') NOT NULL DEFAULT 'manual',
    posted_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_journals_posted_by
        FOREIGN KEY (posted_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_id BIGINT UNSIGNED NOT NULL,
    coa_code VARCHAR(20) NOT NULL,
    line_description VARCHAR(255) NULL,
    debit DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_journal_lines_journal
        FOREIGN KEY (journal_id) REFERENCES journals(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_journal_lines_coa
        FOREIGN KEY (coa_code) REFERENCES coa_accounts(code)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
