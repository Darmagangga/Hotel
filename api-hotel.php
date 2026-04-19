<?php
declare(strict_types=1);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-PMS-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
date_default_timezone_set('Asia/Makassar');
if (is_file(__DIR__ . '/pdf-runtime/vendor/autoload.php')) {
    require_once __DIR__ . '/pdf-runtime/vendor/autoload.php';
} elseif (is_file(__DIR__ . '/backend/vendor/autoload.php') && PHP_VERSION_ID >= 80200) {
    require_once __DIR__ . '/backend/vendor/autoload.php';
}

function env_map(string $path): array {
    $env = [];
    if (!is_file($path)) return $env;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $env[trim(substr($line, 0, $pos))] = trim(trim(substr($line, $pos + 1)), "\"'");
    }
    return $env;
}
function now_ts(): string { return date('Y-m-d H:i:s'); }
function today_date(): string { return date('Y-m-d'); }
function as_float(mixed $value): float { $n = (float) ($value ?? 0); return is_finite($n) ? $n : 0.0; }
function as_int(mixed $value): int { return (int) round(as_float($value)); }
function money(float $amount): string { return 'IDR ' . number_format($amount, 0, ',', '.'); }
function json_input(): array { static $data = null; if ($data !== null) return $data; $raw = file_get_contents('php://input') ?: ''; $decoded = json_decode($raw, true); $data = is_array($decoded) ? $decoded : []; return $data; }
function q(string $key, mixed $default = null): mixed { return $_GET[$key] ?? $default; }
function respond(mixed $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $message, int $status = 422, array $errors = []): never { $payload = ['message' => $message]; if ($errors !== []) $payload['errors'] = $errors; respond($payload, $status); }
function require_fields(array $payload, array $fields): void {
    $errors = [];
    foreach ($fields as $field) { $v = $payload[$field] ?? null; if ($v === null || (is_string($v) && trim($v) === '')) $errors[$field] = ["Field {$field} wajib diisi."]; }
    if ($errors !== []) fail('Data yang dikirim belum lengkap.', 422, $errors);
}
function addon_quantity(array $payload): int {
    $defaultQuantity = max(1, as_int($payload['quantity'] ?? 1));
    return $defaultQuantity;
}
function route_path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/'; $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if (str_contains($path, 'api-hotel.php')) { $pos = strpos($path, 'api-hotel.php'); $path = substr($path, $pos + strlen('api-hotel.php')); }
    $path = trim($path, '/'); if ($path === '' && isset($_GET['path'])) $path = trim((string) $_GET['path'], '/'); return $path;
}
function match_route(string $pattern, string $path): ?array {
    $a = $pattern === '' ? [] : explode('/', trim($pattern, '/')); $b = $path === '' ? [] : explode('/', trim($path, '/'));
    if (count($a) !== count($b)) return null; $params = [];
    foreach ($a as $i => $part) { $value = $b[$i]; if (preg_match('/^\{(.+)\}$/', $part, $m)) { $params[$m[1]] = $value; continue; } if ($part !== $value) return null; }
    return $params;
}
function env_secret(array $env): string {
    $key = (string) ($env['APP_KEY'] ?? 'hotel-book-secret');
    if (str_starts_with($key, 'base64:')) { $decoded = base64_decode(substr($key, 7), true); if ($decoded !== false) return $decoded; }
    return $key;
}
function issue_token(array $user, string $secret): string {
    $payload = ['id' => $user['id'] ?? null, 'name' => $user['name'] ?? '', 'email' => $user['email'] ?? '', 'role' => $user['role'] ?? '', 'permissions' => array_values($user['permissions'] ?? []), 'issued_at' => time()];
    $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    return $encoded . '.' . hash_hmac('sha256', $encoded, $secret);
}
function resolve_auth_user(string $secret): ?array {
    $rawToken = $_SERVER['HTTP_X_PMS_TOKEN'] ?? $_SERVER['REDIRECT_HTTP_X_PMS_TOKEN'] ?? '';
    $rawToken = $rawToken ?: trim((string) ($_GET['token'] ?? $_GET['pms_token'] ?? ''));
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $rawToken = $rawToken ?: ($headers['X-PMS-Token'] ?? $headers['x-pms-token'] ?? '');
    }
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $rawToken = $rawToken ?: ($headers['X-PMS-Token'] ?? $headers['x-pms-token'] ?? '');
    }
    $token = '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        $token = trim($m[1]);
    } elseif ($rawToken !== '') {
        $token = trim($rawToken);
    }
    if ($token === '') return null;
    $parts = explode('.', $token, 2); if (count($parts) !== 2) return null;
    [$encoded, $signature] = $parts; if (!hash_equals(hash_hmac('sha256', $encoded, $secret), $signature)) return null;
    $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true); if ($decoded === false) return null;
    $payload = json_decode($decoded, true); return is_array($payload) ? $payload : null;
}
function can_access(?array $user, ?string $permission): bool {
    if ($permission === null) return true; if (!$user) return false; if (($user['role'] ?? null) === 'admin') return true;
    return in_array($permission, is_array($user['permissions'] ?? null) ? $user['permissions'] : [], true);
}
function db_one(PDO $db, string $sql, array $params = []): ?array { $stmt = $db->prepare($sql); $stmt->execute($params); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row === false ? null : $row; }
function db_all(PDO $db, string $sql, array $params = []): array { $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
function db_value(PDO $db, string $sql, array $params = []): mixed { $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
function paginate_meta(int $page, int $perPage, int $total): array { return ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / max(1, $perPage))), 'per_page' => $perPage, 'total' => $total]; }
function standard_room_type_id(PDO $db): int {
    $row = db_one($db, "SELECT id FROM room_types WHERE code = ? LIMIT 1", ['STD-ROOM']);
    if ($row) return as_int($row['id']);
    $db->prepare("INSERT INTO room_types (code, name, capacity, base_rate, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute(['STD-ROOM', 'Standard Room', 2, 850000, 'Default room setup without class separation.', now_ts(), now_ts()]);
    return (int) $db->lastInsertId();
}

function ensure_runtime_schema(PDO $db): void {
    static $done = false; if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS hotel_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value LONGTEXT NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS housekeeping_tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room_id BIGINT UNSIGNED NOT NULL,
        business_date DATE NOT NULL,
        task_type VARCHAR(120) NOT NULL,
        task_status VARCHAR(40) NOT NULL DEFAULT 'pending',
        priority VARCHAR(40) NOT NULL DEFAULT 'normal',
        owner_team VARCHAR(80) NOT NULL DEFAULT 'Housekeeping',
        eta_note VARCHAR(120) NULL,
        task_note TEXT NULL,
        source_status VARCHAR(40) NULL,
        assigned_to VARCHAR(120) NULL,
        started_at TIMESTAMP NULL DEFAULT NULL,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_hk_room_date (room_id, business_date),
        INDEX idx_hk_status (task_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS audit_trails (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL, user_name VARCHAR(150) NULL, user_email VARCHAR(150) NULL, user_role VARCHAR(100) NULL,
        module VARCHAR(80) NOT NULL, action VARCHAR(80) NOT NULL, entity_type VARCHAR(120) NULL, entity_id VARCHAR(120) NULL, entity_label VARCHAR(191) NULL,
        description TEXT NOT NULL, metadata LONGTEXT NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS night_audit_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        business_date DATE NOT NULL,
        next_business_date DATE NOT NULL,
        pending_checkouts INT NOT NULL DEFAULT 0,
        unresolved_arrivals INT NOT NULL DEFAULT 0,
        active_in_house INT NOT NULL DEFAULT 0,
        folios_processed INT NOT NULL DEFAULT 0,
        summary_json LONGTEXT NULL,
        closed_by_user_id BIGINT UNSIGNED NULL,
        closed_by_name VARCHAR(150) NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uniq_night_audit_business_date (business_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columns = []; foreach ($db->query("SHOW COLUMNS FROM roles") as $row) $columns[$row['Field']] = true;
    if (!isset($columns['permissions'])) $db->exec("ALTER TABLE roles ADD COLUMN permissions LONGTEXT NULL");
    $userColumns = []; foreach ($db->query("SHOW COLUMNS FROM users") as $row) $userColumns[$row['Field']] = true;
    if (!isset($userColumns['username'])) $db->exec("ALTER TABLE users ADD COLUMN username VARCHAR(120) NULL AFTER name");
    $userIndexCheck = (int) db_value($db, "SELECT COUNT(*) FROM information_schema.statistics WHERE BINARY table_schema = BINARY DATABASE() AND BINARY table_name = BINARY 'users' AND BINARY index_name = BINARY 'users_username_unique'");
    if ($userIndexCheck === 0) $db->exec("ALTER TABLE users ADD UNIQUE KEY users_username_unique (username)");
    $missingUsernameRows = db_all($db, "SELECT id, name, email FROM users WHERE username IS NULL ORDER BY id ASC");
    foreach ($missingUsernameRows as $row) {
        $seed = trim((string) ($row['email'] ?: $row['name'] ?: ('user' . $row['id'])));
        $seed = strtolower(preg_replace('/[^a-z0-9]+/i', '', $seed) ?: ('user' . $row['id']));
        if ($seed === '') $seed = 'user' . $row['id'];
        $candidate = $seed;
        $suffix = 1;
        while ((int) db_value($db, "SELECT COUNT(*) FROM users WHERE BINARY username = BINARY ? AND id != ?", [$candidate, $row['id']]) > 0) {
            $candidate = $seed . $suffix;
            $suffix++;
        }
        $db->prepare("UPDATE users SET username = ?, updated_at = ? WHERE id = ?")->execute([$candidate, now_ts(), $row['id']]);
    }
    $paymentColumns = []; foreach ($db->query("SHOW COLUMNS FROM payments") as $row) $paymentColumns[$row['Field']] = true;
    if (!isset($paymentColumns['transaction_type'])) $db->exec("ALTER TABLE payments ADD COLUMN transaction_type VARCHAR(30) NOT NULL DEFAULT 'payment' AFTER amount");
    if (!isset($paymentColumns['parent_payment_id'])) $db->exec("ALTER TABLE payments ADD COLUMN parent_payment_id BIGINT UNSIGNED NULL AFTER transaction_type");
    $exists = function (string $table) use ($db): bool { $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE BINARY table_schema = BINARY DATABASE() AND BINARY table_name = BINARY ?"); $stmt->execute([$table]); return (int) $stmt->fetchColumn() > 0; };
    if (!$exists('transport_rates')) $db->exec("CREATE TABLE transport_rates (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, driver VARCHAR(255) NOT NULL, pickup_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, drop_off_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, vendor_pickup_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, vendor_drop_off_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, customer_pickup_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, customer_drop_off_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, vehicle VARCHAR(255) NULL, vendor_id BIGINT UNSIGNED NULL, fee_coa_code VARCHAR(40) NULL, payable_coa_code VARCHAR(40) NULL, note TEXT NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('activity_operator_catalog')) $db->exec("CREATE TABLE activity_operator_catalog (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, operator VARCHAR(255) NOT NULL, price_value DECIMAL(15,2) NOT NULL DEFAULT 0, expense_coa_code VARCHAR(40) NULL, payable_coa_code VARCHAR(40) NULL, fee_coa_code VARCHAR(40) NULL, hpp_coa_code VARCHAR(40) NULL, note TEXT NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('island_tour_catalog')) $db->exec("CREATE TABLE island_tour_catalog (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, destination VARCHAR(255) NOT NULL, driver VARCHAR(255) NOT NULL, cost_value DECIMAL(15,2) NOT NULL DEFAULT 0, expense_coa_code VARCHAR(40) NULL, payable_coa_code VARCHAR(40) NULL, fee_coa_code VARCHAR(40) NULL, hpp_coa_code VARCHAR(40) NULL, note TEXT NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('boat_ticket_catalog')) $db->exec("CREATE TABLE boat_ticket_catalog (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company VARCHAR(255) NOT NULL, destination VARCHAR(255) NOT NULL, price_value DECIMAL(15,2) NOT NULL DEFAULT 0, expense_coa_code VARCHAR(40) NULL, payable_coa_code VARCHAR(40) NULL, fee_coa_code VARCHAR(40) NULL, hpp_coa_code VARCHAR(40) NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('master_units')) $db->exec("CREATE TABLE master_units (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('vendors')) $db->exec("CREATE TABLE vendors (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vendor_code VARCHAR(40) NOT NULL,
        vendor_name VARCHAR(255) NOT NULL,
        vendor_type VARCHAR(80) NOT NULL DEFAULT 'general',
        phone VARCHAR(80) NULL,
        email VARCHAR(150) NULL,
        address TEXT NULL,
        contact_person VARCHAR(150) NULL,
        payment_terms_days INT NOT NULL DEFAULT 0,
        opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uniq_vendor_code (vendor_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('vendor_services')) $db->exec("CREATE TABLE vendor_services (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vendor_id BIGINT UNSIGNED NOT NULL,
        service_type VARCHAR(80) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uniq_vendor_service (vendor_id, service_type),
        INDEX idx_vendor_services_vendor (vendor_id),
        INDEX idx_vendor_services_type (service_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('vendor_bills')) $db->exec("CREATE TABLE vendor_bills (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bill_number VARCHAR(50) NOT NULL,
        vendor_id BIGINT UNSIGNED NOT NULL,
        bill_date DATE NOT NULL,
        due_date DATE NOT NULL,
        source_module VARCHAR(80) NOT NULL DEFAULT 'manual',
        source_reference VARCHAR(120) NULL,
        description TEXT NULL,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
        tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        grand_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        balance_due DECIMAL(15,2) NOT NULL DEFAULT 0,
        expense_coa_code VARCHAR(40) NULL,
        payable_coa_code VARCHAR(40) NULL,
        hpp_coa_code VARCHAR(40) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
        created_by BIGINT UNSIGNED NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_vendor_bills_vendor (vendor_id),
        INDEX idx_vendor_bills_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('vendor_payments')) $db->exec("CREATE TABLE vendor_payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_number VARCHAR(50) NOT NULL,
        vendor_id BIGINT UNSIGNED NOT NULL,
        payment_date DATE NOT NULL,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'bank_transfer',
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        reference_number VARCHAR(120) NULL,
        cash_bank_coa_code VARCHAR(40) NULL,
        notes TEXT NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_vendor_payments_vendor (vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('vendor_payment_allocations')) $db->exec("CREATE TABLE vendor_payment_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vendor_payment_id BIGINT UNSIGNED NOT NULL,
        vendor_bill_id BIGINT UNSIGNED NOT NULL,
        allocated_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_vendor_payment_allocations_payment (vendor_payment_id),
        INDEX idx_vendor_payment_allocations_bill (vendor_bill_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($exists('scooter_catalog')) {
        $cols = []; foreach ($db->query("SHOW COLUMNS FROM scooter_catalog") as $row) $cols[$row['Field']] = true;
        if (!isset($cols['start_date'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN start_date DATE NULL");
        if (!isset($cols['end_date'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN end_date DATE NULL");
        if (!isset($cols['vendor'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN vendor VARCHAR(255) NULL");
        if (!isset($cols['vendor_id'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN vendor_id BIGINT UNSIGNED NULL AFTER vendor");
        if (!isset($cols['price_value'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN price_value DECIMAL(15,2) NOT NULL DEFAULT 0");
        if (!isset($cols['vendor_price_value'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN vendor_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER price_value");
        if (!isset($cols['customer_price_value'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN customer_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER vendor_price_value");
        if (!isset($cols['expense_coa_code'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN expense_coa_code VARCHAR(40) NULL AFTER price_value");
        if (!isset($cols['payable_coa_code'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN payable_coa_code VARCHAR(40) NULL AFTER expense_coa_code");
        if (!isset($cols['fee_coa_code'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN fee_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['hpp_coa_code'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN hpp_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['is_active'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER hpp_coa_code");
        $db->exec("UPDATE scooter_catalog SET customer_price_value = COALESCE(NULLIF(customer_price_value, 0), price_value), vendor_price_value = COALESCE(NULLIF(vendor_price_value, 0), price_value), price_value = COALESCE(NULLIF(customer_price_value, 0), price_value)");
        $db->exec("UPDATE scooter_catalog s INNER JOIN vendors v ON BINARY v.vendor_name = BINARY s.vendor AND BINARY v.vendor_type = BINARY 'scooter' SET s.vendor_id = v.id WHERE s.vendor_id IS NULL AND s.vendor IS NOT NULL AND s.vendor != ''");
    }
    if ($exists('transport_rates')) {
        $cols = []; foreach ($db->query("SHOW COLUMNS FROM transport_rates") as $row) $cols[$row['Field']] = true;
        if (!isset($cols['vendor_pickup_price_value'])) $db->exec("ALTER TABLE transport_rates ADD COLUMN vendor_pickup_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER drop_off_price_value");
        if (!isset($cols['vendor_drop_off_price_value'])) $db->exec("ALTER TABLE transport_rates ADD COLUMN vendor_drop_off_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER vendor_pickup_price_value");
        if (!isset($cols['customer_pickup_price_value'])) $db->exec("ALTER TABLE transport_rates ADD COLUMN customer_pickup_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER vendor_drop_off_price_value");
        if (!isset($cols['customer_drop_off_price_value'])) $db->exec("ALTER TABLE transport_rates ADD COLUMN customer_drop_off_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER customer_pickup_price_value");
        if (!isset($cols['vendor_id'])) $db->exec("ALTER TABLE transport_rates ADD COLUMN vendor_id BIGINT UNSIGNED NULL AFTER vehicle");
        if (!isset($cols['fee_coa_code'])) $db->exec("ALTER TABLE transport_rates ADD COLUMN fee_coa_code VARCHAR(40) NULL AFTER vendor_id");
        if (!isset($cols['payable_coa_code'])) $db->exec("ALTER TABLE transport_rates ADD COLUMN payable_coa_code VARCHAR(40) NULL AFTER fee_coa_code");
        if (!isset($cols['fee_coa_code']) && isset($cols['expense_coa_code'])) $db->exec("UPDATE transport_rates SET fee_coa_code = expense_coa_code WHERE fee_coa_code IS NULL AND expense_coa_code IS NOT NULL");
        $db->exec("UPDATE transport_rates SET vendor_pickup_price_value = COALESCE(NULLIF(vendor_pickup_price_value, 0), pickup_price_value), vendor_drop_off_price_value = COALESCE(NULLIF(vendor_drop_off_price_value, 0), drop_off_price_value), customer_pickup_price_value = COALESCE(NULLIF(customer_pickup_price_value, 0), pickup_price_value), customer_drop_off_price_value = COALESCE(NULLIF(customer_drop_off_price_value, 0), drop_off_price_value), pickup_price_value = COALESCE(NULLIF(customer_pickup_price_value, 0), pickup_price_value), drop_off_price_value = COALESCE(NULLIF(customer_drop_off_price_value, 0), drop_off_price_value)");
    }
    if ($exists('activity_operator_catalog')) {
        $cols = []; foreach ($db->query("SHOW COLUMNS FROM activity_operator_catalog") as $row) $cols[$row['Field']] = true;
        if (!isset($cols['vendor'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN vendor VARCHAR(255) NULL AFTER operator");
        if (!isset($cols['vendor_id'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN vendor_id BIGINT UNSIGNED NULL AFTER vendor");
        if (!isset($cols['vendor_price_value'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN vendor_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER price_value");
        if (!isset($cols['customer_price_value'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN customer_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER vendor_price_value");
        if (!isset($cols['expense_coa_code'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN expense_coa_code VARCHAR(40) NULL AFTER price_value");
        if (!isset($cols['payable_coa_code'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN payable_coa_code VARCHAR(40) NULL AFTER expense_coa_code");
        if (!isset($cols['fee_coa_code'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN fee_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['hpp_coa_code'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN hpp_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['is_active'])) $db->exec("ALTER TABLE activity_operator_catalog ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER hpp_coa_code");
        $db->exec("UPDATE activity_operator_catalog SET customer_price_value = COALESCE(NULLIF(customer_price_value, 0), price_value), vendor_price_value = COALESCE(NULLIF(vendor_price_value, 0), price_value), price_value = COALESCE(NULLIF(customer_price_value, 0), price_value)");
        $db->exec("UPDATE activity_operator_catalog a INNER JOIN vendors v ON BINARY v.vendor_name = BINARY a.vendor AND BINARY v.vendor_type = BINARY 'operator' SET a.vendor_id = v.id WHERE a.vendor_id IS NULL AND a.vendor IS NOT NULL AND a.vendor != ''");
    }
    if ($exists('island_tour_catalog')) {
        $cols = []; foreach ($db->query("SHOW COLUMNS FROM island_tour_catalog") as $row) $cols[$row['Field']] = true;
        if (!isset($cols['vendor'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN vendor VARCHAR(255) NULL AFTER destination");
        if (!isset($cols['vendor_id'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN vendor_id BIGINT UNSIGNED NULL AFTER vendor");
        if (!isset($cols['vendor_price_value'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN vendor_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER cost_value");
        if (!isset($cols['customer_price_value'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN customer_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER vendor_price_value");
        if (!isset($cols['expense_coa_code'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN expense_coa_code VARCHAR(40) NULL AFTER cost_value");
        if (!isset($cols['payable_coa_code'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN payable_coa_code VARCHAR(40) NULL AFTER expense_coa_code");
        if (!isset($cols['fee_coa_code'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN fee_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['hpp_coa_code'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN hpp_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['is_active'])) $db->exec("ALTER TABLE island_tour_catalog ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER hpp_coa_code");
        $db->exec("UPDATE island_tour_catalog SET customer_price_value = COALESCE(NULLIF(customer_price_value, 0), cost_value), vendor_price_value = COALESCE(NULLIF(vendor_price_value, 0), cost_value), cost_value = COALESCE(NULLIF(customer_price_value, 0), cost_value)");
        $db->exec("UPDATE island_tour_catalog i INNER JOIN vendors v ON BINARY v.vendor_name = BINARY i.vendor AND BINARY v.vendor_type = BINARY 'island_tour' SET i.vendor_id = v.id WHERE i.vendor_id IS NULL AND i.vendor IS NOT NULL AND i.vendor != ''");
    }
    if ($exists('boat_ticket_catalog')) {
        $cols = []; foreach ($db->query("SHOW COLUMNS FROM boat_ticket_catalog") as $row) $cols[$row['Field']] = true;
        if (!isset($cols['vendor'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN vendor VARCHAR(255) NULL AFTER company");
        if (!isset($cols['vendor_id'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN vendor_id BIGINT UNSIGNED NULL AFTER vendor");
        if (!isset($cols['vendor_price_value'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN vendor_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER price_value");
        if (!isset($cols['customer_price_value'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN customer_price_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER vendor_price_value");
        if (!isset($cols['expense_coa_code'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN expense_coa_code VARCHAR(40) NULL AFTER price_value");
        if (!isset($cols['payable_coa_code'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN payable_coa_code VARCHAR(40) NULL AFTER expense_coa_code");
        if (!isset($cols['fee_coa_code'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN fee_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['hpp_coa_code'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN hpp_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
        if (!isset($cols['is_active'])) $db->exec("ALTER TABLE boat_ticket_catalog ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER hpp_coa_code");
        $db->exec("UPDATE boat_ticket_catalog SET customer_price_value = COALESCE(NULLIF(customer_price_value, 0), price_value), vendor_price_value = COALESCE(NULLIF(vendor_price_value, 0), price_value), price_value = COALESCE(NULLIF(customer_price_value, 0), price_value)");
        $db->exec("UPDATE boat_ticket_catalog b INNER JOIN vendors v ON BINARY v.vendor_name = BINARY b.vendor AND BINARY v.vendor_type = BINARY 'boat_ticket' SET b.vendor_id = v.id WHERE b.vendor_id IS NULL AND b.vendor IS NOT NULL AND b.vendor != ''");
    }
    if ($exists('vendor_bills')) {
        $cols = []; foreach ($db->query("SHOW COLUMNS FROM vendor_bills") as $row) $cols[$row['Field']] = true;
        if (!isset($cols['expense_coa_code'])) $db->exec("ALTER TABLE vendor_bills ADD COLUMN expense_coa_code VARCHAR(40) NULL AFTER balance_due");
        if (!isset($cols['payable_coa_code'])) $db->exec("ALTER TABLE vendor_bills ADD COLUMN payable_coa_code VARCHAR(40) NULL AFTER expense_coa_code");
        if (!isset($cols['hpp_coa_code'])) $db->exec("ALTER TABLE vendor_bills ADD COLUMN hpp_coa_code VARCHAR(40) NULL AFTER payable_coa_code");
    }
    if ($exists('vendors') && $exists('vendor_services')) {
        $db->exec("INSERT IGNORE INTO vendor_services (vendor_id, service_type, created_at, updated_at)
            SELECT id, vendor_type, created_at, updated_at
            FROM vendors
            WHERE vendor_type IN ('scooter', 'operator', 'island_tour', 'boat_ticket')");
    }
    $done = true;
}
function audit_log(PDO $db, array $entry, ?array $actor): void {
    ensure_runtime_schema($db); $stmt = $db->prepare("INSERT INTO audit_trails (user_id,user_name,user_email,user_role,module,action,entity_type,entity_id,entity_label,description,metadata,ip_address,user_agent,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"); $now = now_ts();
    $stmt->execute([$actor['id'] ?? null, $actor['name'] ?? null, $actor['email'] ?? null, $actor['role'] ?? null, (string) ($entry['module'] ?? 'system'), (string) ($entry['action'] ?? 'updated'), $entry['entity_type'] ?? null, isset($entry['entity_id']) ? (string) $entry['entity_id'] : null, $entry['entity_label'] ?? null, (string) ($entry['description'] ?? ''), isset($entry['metadata']) ? json_encode($entry['metadata'], JSON_UNESCAPED_UNICODE) : null, $_SERVER['REMOTE_ADDR'] ?? null, isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null, $now, $now]);
}

function normalize_source(string $value): string { return match (strtolower(trim($value))) { 'direct' => 'direct', 'airbnb' => 'airbnb', 'booking.com' => 'booking.com', 'agoda', 'booking' => 'agoda', 'traveloka' => 'traveloka', 'walk-in', 'walk_in', 'walkin' => 'walk_in', default => 'other', }; }
function source_label(string $value): string { return match ($value) { 'direct' => 'Direct', 'airbnb' => 'Airbnb', 'booking.com' => 'Booking.com', 'agoda' => 'Booking', 'traveloka' => 'Traveloka', 'walk_in' => 'Walk-in', default => 'Other', }; }
function normalize_booking_status(string $value): string { return match (strtolower(trim($value))) { 'tentative', 'draft' => 'draft', 'confirmed' => 'confirmed', 'checked-in', 'checked_in' => 'checked_in', 'checked-out', 'checked_out', 'completed' => 'checked_out', 'cancelled', 'canceled' => 'cancelled', 'no-show', 'no_show' => 'no_show', default => 'confirmed', }; }
function booking_status_label(string $value): string { return match ($value) { 'draft' => 'Tentative', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked-in', 'checked_out' => 'Checked-out', 'cancelled' => 'Cancelled', 'no_show' => 'No-Show', default => ucfirst($value), }; }
function normalize_addon_status(string $value): string { return match (strtolower(trim($value))) { 'planned' => 'planned', 'confirmed' => 'confirmed', 'posted', 'completed' => 'completed', 'cancelled' => 'cancelled', default => 'planned', }; }
function addon_status_label(string $value): string { return match ($value) { 'planned' => 'Planned', 'confirmed' => 'Confirmed', 'completed' => 'Posted', 'cancelled' => 'Cancelled', default => ucfirst(str_replace('_', ' ', $value)), }; }
function normalize_payment_method(string $value): string { return match (strtolower(trim($value))) { 'cash', 'tunai' => 'cash', 'bank transfer', 'bank_transfer', 'transfer' => 'bank_transfer', 'credit card', 'credit_card' => 'credit_card', 'debit card', 'debit_card' => 'debit_card', 'qris' => 'qris', default => 'other', }; }
function activity_vendor_types(): array {
    return ['transport', 'scooter', 'operator', 'island_tour', 'boat_ticket'];
}
function activity_source_modules(): array {
    return ['activity', 'transport', 'scooter', 'operator', 'island_tour', 'boat_ticket'];
}
function parse_catalog_reference_id(mixed $value): ?int {
    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
        $id = as_int($value);
        return $id > 0 ? $id : null;
    }
    $text = strtoupper(trim((string) $value));
    if ($text !== '' && preg_match('/(\d+)$/', $text, $matches)) {
        $id = as_int($matches[1] ?? 0);
        return $id > 0 ? $id : null;
    }
    return null;
}
function service_type_label(string $value): string {
    return match ($value) {
        'transport' => 'Transport',
        'scooter' => 'Scooter',
        'operator' => 'Operator',
        'island_tour' => 'Island Tour',
        'boat_ticket' => 'Boat Ticket',
        default => ucwords(str_replace('_', ' ', $value)),
    };
}
function is_activity_vendor_type(string $value): bool {
    return in_array(trim($value), activity_vendor_types(), true);
}
function is_activity_source_module(string $value): bool {
    return in_array(trim($value), activity_source_modules(), true);
}
function normalize_service_types(mixed $value): array {
    $types = is_array($value) ? $value : [$value];
    $allowed = activity_vendor_types();
    $result = [];
    foreach ($types as $type) {
        $normalized = trim((string) $type);
        if ($normalized !== '' && in_array($normalized, $allowed, true) && !in_array($normalized, $result, true)) {
            $result[] = $normalized;
        }
    }
    return $result;
}
function vendor_supports_service(PDO $db, int $vendorId, string $serviceType): bool {
    return (int) db_value($db, "SELECT COUNT(*) FROM vendor_services WHERE vendor_id = ? AND service_type = ?", [$vendorId, $serviceType]) > 0;
}
function sync_vendor_services(PDO $db, int $vendorId, array $serviceTypes): void {
    $serviceTypes = normalize_service_types($serviceTypes);
    $db->prepare("DELETE FROM vendor_services WHERE vendor_id = ?")->execute([$vendorId]);
    if ($serviceTypes === []) return;
    $insert = $db->prepare("INSERT INTO vendor_services (vendor_id, service_type, created_at, updated_at) VALUES (?, ?, ?, ?)");
    $now = now_ts();
    foreach ($serviceTypes as $type) {
        $insert->execute([$vendorId, $type, $now, $now]);
    }
}
function resolve_activity_vendor(PDO $db, mixed $vendorId, string $vendorType, ?string $vendorName = null): array {
    $resolvedId = as_int($vendorId);
    if ($resolvedId > 0) {
        $vendor = db_one($db, "SELECT * FROM vendors WHERE id = ?", [$resolvedId]);
        if (!$vendor) fail('Vendor activity tidak valid.', 422, ['vendorId' => ['Vendor activity tidak valid.']]);
        return $vendor;
    }

    $name = trim((string) $vendorName);
    if ($name !== '') {
        $vendor = db_one($db, "SELECT * FROM vendors WHERE vendor_name = ? ORDER BY id DESC LIMIT 1", [$name]);
        if ($vendor) return $vendor;
    }

    fail('Vendor activity wajib dipilih.', 422, ['vendorId' => ['Vendor activity wajib dipilih.']]);
}
function find_coa_code(PDO $db, ?string $value): string {
    $code = coa_code_only((string) ($value ?? ''));
    if ($code === '') return '';
    return (int) db_value($db, "SELECT COUNT(*) FROM coa_accounts WHERE code = ?", [$code]) > 0 ? $code : '';
}
function resolve_payable_coa(PDO $db): string {
    if ((int) db_value($db, "SELECT COUNT(*) FROM coa_accounts WHERE code = '211001'") > 0) return '211001';
    $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'liability' AND code LIKE '211%' ORDER BY code ASC LIMIT 1");
    if ($fallback) return (string) $fallback;
    $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'liability' ORDER BY code ASC LIMIT 1");
    return (string) ($fallback ?: '211001');
}
function resolve_activity_payable_source_module(string $addonType): string {
    return in_array($addonType, activity_source_modules(), true) ? $addonType : 'activity';
}
function fetch_activity_service_snapshot(PDO $db, string $addonType, int $referenceId): ?array {
    if ($referenceId <= 0) return null;
    return match ($addonType) {
        'transport' => ($row = db_one($db, "SELECT id, driver, vendor_id, vendor_pickup_price_value, vendor_drop_off_price_value, customer_pickup_price_value, customer_drop_off_price_value, fee_coa_code, expense_coa_code, payable_coa_code, hpp_coa_code FROM transport_rates WHERE id = ? LIMIT 1", [$referenceId])) ? [
            'reference_id' => as_int($row['id']),
            'service_name' => trim((string) ($row['driver'] ?? 'Transport')),
            'vendor_id' => as_int($row['vendor_id'] ?? 0),
            'vendor_pickup_price_value' => as_float($row['vendor_pickup_price_value'] ?? 0),
            'vendor_drop_off_price_value' => as_float($row['vendor_drop_off_price_value'] ?? 0),
            'customer_pickup_price_value' => as_float($row['customer_pickup_price_value'] ?? 0),
            'customer_drop_off_price_value' => as_float($row['customer_drop_off_price_value'] ?? 0),
            'fee_coa_code' => find_coa_code($db, (string) ($row['fee_coa_code'] ?? '')),
            'expense_coa_code' => find_coa_code($db, (string) ($row['expense_coa_code'] ?? '')),
            'payable_coa_code' => find_coa_code($db, (string) ($row['payable_coa_code'] ?? '')),
            'hpp_coa_code' => find_coa_code($db, (string) ($row['hpp_coa_code'] ?? '')),
            'source_module' => 'transport',
        ] : null,
        'scooter' => ($row = db_one($db, "SELECT id, scooter_type, vendor_id, vendor_price_value, customer_price_value, expense_coa_code, payable_coa_code, fee_coa_code, hpp_coa_code FROM scooter_catalog WHERE id = ? LIMIT 1", [$referenceId])) ? [
            'reference_id' => as_int($row['id']),
            'service_name' => trim((string) ($row['scooter_type'] ?? 'Scooter')),
            'vendor_id' => as_int($row['vendor_id'] ?? 0),
            'vendor_price_value' => as_float($row['vendor_price_value'] ?? 0),
            'customer_price_value' => as_float($row['customer_price_value'] ?? 0),
            'expense_coa_code' => find_coa_code($db, (string) ($row['expense_coa_code'] ?? '')),
            'payable_coa_code' => find_coa_code($db, (string) ($row['payable_coa_code'] ?? '')),
            'fee_coa_code' => find_coa_code($db, (string) ($row['fee_coa_code'] ?? '')),
            'hpp_coa_code' => find_coa_code($db, (string) ($row['hpp_coa_code'] ?? '')),
            'source_module' => 'scooter',
        ] : null,
        'operator' => ($row = db_one($db, "SELECT id, operator, vendor_id, vendor_price_value, customer_price_value, expense_coa_code, payable_coa_code, fee_coa_code, hpp_coa_code FROM activity_operator_catalog WHERE id = ? LIMIT 1", [$referenceId])) ? [
            'reference_id' => as_int($row['id']),
            'service_name' => trim((string) ($row['operator'] ?? 'Operator')),
            'vendor_id' => as_int($row['vendor_id'] ?? 0),
            'vendor_price_value' => as_float($row['vendor_price_value'] ?? 0),
            'customer_price_value' => as_float($row['customer_price_value'] ?? 0),
            'expense_coa_code' => find_coa_code($db, (string) ($row['expense_coa_code'] ?? '')),
            'payable_coa_code' => find_coa_code($db, (string) ($row['payable_coa_code'] ?? '')),
            'fee_coa_code' => find_coa_code($db, (string) ($row['fee_coa_code'] ?? '')),
            'hpp_coa_code' => find_coa_code($db, (string) ($row['hpp_coa_code'] ?? '')),
            'source_module' => 'operator',
        ] : null,
        'island_tour' => ($row = db_one($db, "SELECT id, destination, vendor_id, vendor_price_value, customer_price_value, expense_coa_code, payable_coa_code, fee_coa_code, hpp_coa_code FROM island_tour_catalog WHERE id = ? LIMIT 1", [$referenceId])) ? [
            'reference_id' => as_int($row['id']),
            'service_name' => trim((string) ($row['destination'] ?? 'Island Tour')),
            'vendor_id' => as_int($row['vendor_id'] ?? 0),
            'vendor_price_value' => as_float($row['vendor_price_value'] ?? 0),
            'customer_price_value' => as_float($row['customer_price_value'] ?? 0),
            'expense_coa_code' => find_coa_code($db, (string) ($row['expense_coa_code'] ?? '')),
            'payable_coa_code' => find_coa_code($db, (string) ($row['payable_coa_code'] ?? '')),
            'fee_coa_code' => find_coa_code($db, (string) ($row['fee_coa_code'] ?? '')),
            'hpp_coa_code' => find_coa_code($db, (string) ($row['hpp_coa_code'] ?? '')),
            'source_module' => 'island_tour',
        ] : null,
        'boat_ticket' => ($row = db_one($db, "SELECT id, company, vendor_id, vendor_price_value, customer_price_value, expense_coa_code, payable_coa_code, fee_coa_code, hpp_coa_code FROM boat_ticket_catalog WHERE id = ? LIMIT 1", [$referenceId])) ? [
            'reference_id' => as_int($row['id']),
            'service_name' => trim((string) ($row['company'] ?? 'Boat Ticket')),
            'vendor_id' => as_int($row['vendor_id'] ?? 0),
            'vendor_price_value' => as_float($row['vendor_price_value'] ?? 0),
            'customer_price_value' => as_float($row['customer_price_value'] ?? 0),
            'expense_coa_code' => find_coa_code($db, (string) ($row['expense_coa_code'] ?? '')),
            'payable_coa_code' => find_coa_code($db, (string) ($row['payable_coa_code'] ?? '')),
            'fee_coa_code' => find_coa_code($db, (string) ($row['fee_coa_code'] ?? '')),
            'hpp_coa_code' => find_coa_code($db, (string) ($row['hpp_coa_code'] ?? '')),
            'source_module' => 'boat_ticket',
        ] : null,
        default => null,
    };
}
function transport_addon_rate_type(array $meta): string {
    $haystack = strtolower(trim((string) (($meta['addonLabel'] ?? '') . ' ' . ($meta['serviceName'] ?? ''))));
    if (str_contains($haystack, 'drop off') || str_contains($haystack, 'dropoff')) return 'dropoff';
    if (str_contains($haystack, 'pickup') || str_contains($haystack, 'pick up')) return 'pickup';
    return 'pickup';
}
function resolve_transport_service_vendor_unit_price(array $service, array $meta): float {
    return transport_addon_rate_type($meta) === 'dropoff'
        ? as_float($service['vendor_drop_off_price_value'] ?? 0)
        : as_float($service['vendor_pickup_price_value'] ?? 0);
}
function booking_addon_vendor_amount(PDO $db, array $addon, ?array $service = null, ?array $meta = null): float {
    $grossAmount = round(as_float($addon['total_price'] ?? 0), 2);
    if ($grossAmount <= 0) return 0.0;
    $meta = is_array($meta) ? $meta : json_decode((string) ($addon['notes'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];
    $qty = max(1, as_int($addon['qty'] ?? 1));
    $vendorAmount = round(as_float($meta['vendorTotalPriceValue'] ?? 0), 2);
    if ($vendorAmount <= 0) $vendorAmount = round(as_float($meta['vendorUnitPriceValue'] ?? 0) * $qty, 2);
    if ($vendorAmount <= 0) {
        $service = $service ?: fetch_activity_service_snapshot($db, trim((string) ($addon['addon_type'] ?? '')), as_int($addon['reference_id'] ?? 0));
        if (trim((string) ($addon['addon_type'] ?? '')) === 'transport' && $service) {
            $vendorAmount = round(resolve_transport_service_vendor_unit_price($service, $meta) * $qty, 2);
        } else {
            $vendorAmount = round(as_float($service['vendor_price_value'] ?? 0) * $qty, 2);
        }
    }
    return max(0, min($grossAmount, $vendorAmount));
}
function vendor_bill_status(float $grandTotal, float $paidAmount): string {
    if ($paidAmount <= 0) return 'unpaid';
    if ($paidAmount + 0.00001 >= $grandTotal) return 'paid';
    return 'partial';
}
function vendor_bill_status_label(string $value): string {
    return match ($value) {
        'unpaid' => 'Unpaid',
        'partial' => 'Partial',
        'paid' => 'Paid',
        'void' => 'Void',
        default => ucfirst($value),
    };
}
function vendor_refresh_bill(PDO $db, int $billId): void {
    $bill = db_one($db, "SELECT id, grand_total, status FROM vendor_bills WHERE id = ?", [$billId]);
    if (!$bill || (string) $bill['status'] === 'void') return;
    $paidAmount = as_float(db_value($db, "SELECT COALESCE(SUM(allocated_amount), 0) FROM vendor_payment_allocations WHERE vendor_bill_id = ?", [$billId]));
    $grandTotal = as_float($bill['grand_total']);
    $balanceDue = max(0, $grandTotal - $paidAmount);
    $status = vendor_bill_status($grandTotal, $paidAmount);
    $db->prepare("UPDATE vendor_bills SET paid_amount = ?, balance_due = ?, status = ?, updated_at = ? WHERE id = ?")->execute([$paidAmount, $balanceDue, $status, now_ts(), $billId]);
}
function sync_vendor_bill_accounting(PDO $db, int $billId): void {
    $bill = db_one($db, "SELECT * FROM vendor_bills WHERE id = ? LIMIT 1", [$billId]);
    if (!$bill) return;
    $existing = db_one($db, "SELECT * FROM journals WHERE source = 'vendor_bill' AND reference_type = 'vendor_bill' AND reference_id = ? LIMIT 1", [$billId]);
    if ((string) ($bill['status'] ?? '') === 'void') {
        if ($existing) {
            $db->prepare("DELETE FROM journal_lines WHERE journal_id = ?")->execute([as_int($existing['id'])]);
            $db->prepare("DELETE FROM journals WHERE id = ?")->execute([as_int($existing['id'])]);
        }
        return;
    }
    $amount = as_float($bill['grand_total']);
    $expenseCoa = find_coa_code($db, (string) ($bill['expense_coa_code'] ?? ''));
    $hppCoa = find_coa_code($db, (string) ($bill['hpp_coa_code'] ?? ''));
    $payableCoa = find_coa_code($db, (string) ($bill['payable_coa_code'] ?? ''));
    $debitCoa = $hppCoa !== '' ? $hppCoa : $expenseCoa;
    if ($amount <= 0 || $debitCoa === '' || $payableCoa === '') return;
    upsert_generic_journal($db, 'vendor_bill', 'vendor_bill', $billId, (string) $bill['bill_date'], "Vendor bill {$bill['bill_number']}", [
        ['coa_code' => $debitCoa, 'debit' => $amount, 'credit' => 0, 'memo' => ($hppCoa !== '' ? "HPP {$bill['bill_number']}" : "Expense {$bill['bill_number']}")],
        ['coa_code' => $payableCoa, 'debit' => 0, 'credit' => $amount, 'memo' => "Payable {$bill['bill_number']}"],
    ]);
}
function sync_vendor_payment_accounting(PDO $db, int $paymentId): void {
    $payment = db_one($db, "SELECT * FROM vendor_payments WHERE id = ? LIMIT 1", [$paymentId]);
    if (!$payment) return;
    $allocations = db_all($db, "SELECT a.allocated_amount, b.bill_number, b.payable_coa_code FROM vendor_payment_allocations a INNER JOIN vendor_bills b ON b.id = a.vendor_bill_id WHERE a.vendor_payment_id = ?", [$paymentId]);
    if ($allocations === []) return;
    $cashBankCoa = find_coa_code($db, (string) ($payment['cash_bank_coa_code'] ?? '')) ?: resolve_cash_bank_coa($db, (string) $payment['payment_method']);
    if ($cashBankCoa !== (string) ($payment['cash_bank_coa_code'] ?? '')) {
        $db->prepare("UPDATE vendor_payments SET cash_bank_coa_code = ?, updated_at = ? WHERE id = ?")->execute([$cashBankCoa, now_ts(), $paymentId]);
    }
    $payableTotals = [];
    foreach ($allocations as $allocation) {
        $coa = find_coa_code($db, (string) ($allocation['payable_coa_code'] ?? ''));
        $amount = as_float($allocation['allocated_amount']);
        if ($coa === '' || $amount <= 0) continue;
        $payableTotals[$coa] = ($payableTotals[$coa] ?? 0) + $amount;
    }
    if ($payableTotals === []) return;
    $lines = [];
    $totalPaid = 0.0;
    foreach ($payableTotals as $coa => $amount) {
        $amount = round($amount, 2);
        if ($amount <= 0) continue;
        $lines[] = ['coa_code' => $coa, 'debit' => $amount, 'credit' => 0, 'memo' => "Settlement vendor payment {$payment['payment_number']}"];
        $totalPaid += $amount;
    }
    if ($totalPaid <= 0) return;
    $lines[] = ['coa_code' => $cashBankCoa, 'debit' => 0, 'credit' => $totalPaid, 'memo' => "Cash out {$payment['payment_number']}"];
    upsert_generic_journal($db, 'vendor_payment', 'vendor_payment', $paymentId, (string) $payment['payment_date'], "Vendor payment {$payment['payment_number']}", $lines);
}
function sync_booking_addon_vendor_bill(PDO $db, int $addonId, ?array $booking = null, ?array $actor = null): void {
    $addon = db_one($db, "SELECT * FROM booking_addons WHERE id = ? LIMIT 1", [$addonId]);
    $sourceReference = 'booking_addon:' . $addonId;
    $existingBill = db_one($db, "SELECT * FROM vendor_bills WHERE source_reference = ? LIMIT 1", [$sourceReference]);
    if (!$addon) {
        if ($existingBill && (string) $existingBill['status'] !== 'void') {
            $db->prepare("UPDATE vendor_bills SET status = 'void', updated_at = ? WHERE id = ?")->execute([now_ts(), as_int($existingBill['id'])]);
            sync_vendor_bill_accounting($db, as_int($existingBill['id']));
        }
        return;
    }
    $addonType = trim((string) ($addon['addon_type'] ?? ''));
    $service = fetch_activity_service_snapshot($db, $addonType, as_int($addon['reference_id'] ?? 0));
    if (!$service || as_int($service['vendor_id'] ?? 0) <= 0) {
        if ($existingBill && (string) $existingBill['status'] !== 'void') {
            $db->prepare("UPDATE vendor_bills SET status = 'void', updated_at = ? WHERE id = ?")->execute([now_ts(), as_int($existingBill['id'])]);
            sync_vendor_bill_accounting($db, as_int($existingBill['id']));
        }
        return;
    }
    $expenseCoa = (string) ($service['expense_coa_code'] ?? '');
    $hppCoa = (string) ($service['hpp_coa_code'] ?? '');
    $payableCoa = (string) ($service['payable_coa_code'] ?? '') ?: resolve_payable_coa($db);
    if ($payableCoa === '') {
        fail('Master activity belum lengkap. Hutang COA wajib diisi sebelum add-on diposting ke hutang vendor.', 422, ['coa' => ['Hutang COA wajib diisi.']]);
    }
    $booking = $booking ?: db_one($db, "SELECT * FROM bookings WHERE id = ? LIMIT 1", [as_int($addon['booking_id'])]);
    if (!$booking) return;
    $vendor = db_one($db, "SELECT * FROM vendors WHERE id = ? LIMIT 1", [as_int($service['vendor_id'])]);
    if (!$vendor) return;
    $billDate = (string) ($addon['service_date'] ?: $addon['start_date'] ?: substr((string) $booking['check_in_at'], 0, 10));
    $dueDate = date('Y-m-d', strtotime($billDate . ' +' . max(0, as_int($vendor['payment_terms_days'] ?? 0)) . ' day'));
    $meta = json_decode((string) ($addon['notes'] ?? ''), true);
    $meta = is_array($meta) ? $meta : [];
    $amount = booking_addon_vendor_amount($db, $addon, $service, $meta);
    $isVoid = (string) ($addon['status'] ?? '') === 'cancelled' || $amount <= 0;
    if ($existingBill) {
        if ($isVoid) {
            $db->prepare("UPDATE vendor_bills SET status = 'void', updated_at = ? WHERE id = ?")->execute([now_ts(), as_int($existingBill['id'])]);
        } else {
            $db->prepare("UPDATE vendor_bills SET vendor_id = ?, bill_date = ?, due_date = ?, source_module = ?, description = ?, subtotal = ?, grand_total = ?, balance_due = ?, expense_coa_code = ?, payable_coa_code = ?, hpp_coa_code = ?, status = CASE WHEN paid_amount > 0 THEN 'partial' ELSE 'unpaid' END, updated_at = ? WHERE id = ?")
                ->execute([as_int($vendor['id']), $billDate, $dueDate, (string) $service['source_module'], "Booking {$booking['booking_code']} | {$service['service_name']}", $amount, $amount, max(0, $amount - as_float($existingBill['paid_amount'] ?? 0)), $expenseCoa ?: null, $payableCoa, $hppCoa ?: null, now_ts(), as_int($existingBill['id'])]);
            vendor_refresh_bill($db, as_int($existingBill['id']));
        }
        sync_vendor_bill_accounting($db, as_int($existingBill['id']));
        return;
    }
    if ($isVoid) return;
    $billNumber = generate_vendor_bill_number($db, $billDate);
    $db->prepare("INSERT INTO vendor_bills (bill_number, vendor_id, bill_date, due_date, source_module, source_reference, description, subtotal, tax_amount, discount_amount, grand_total, paid_amount, balance_due, expense_coa_code, payable_coa_code, hpp_coa_code, status, created_by, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, ?, ?, 'unpaid', ?, ?, ?, ?)")
        ->execute([$billNumber, as_int($vendor['id']), $billDate, $dueDate, (string) $service['source_module'], $sourceReference, "Booking {$booking['booking_code']} | {$service['service_name']}", $amount, $amount, $amount, $expenseCoa ?: null, $payableCoa, $hppCoa ?: null, as_int($actor['id'] ?? 0) ?: null, "Generated from booking add-on {$booking['booking_code']}", now_ts(), now_ts()]);
    sync_vendor_bill_accounting($db, (int) $db->lastInsertId());
}
function transform_vendor(array $row, ?array $summary = null): array {
    $serviceTypes = [];
    if (isset($row['service_types'])) {
        $serviceTypes = array_values(array_filter(array_map('trim', explode(',', (string) $row['service_types']))));
    } elseif (!empty($row['vendor_type']) && is_activity_vendor_type((string) $row['vendor_type'])) {
        $serviceTypes = [(string) $row['vendor_type']];
    }
    return [
        'id' => as_int($row['id']),
        'vendorCode' => (string) $row['vendor_code'],
        'vendorName' => (string) $row['vendor_name'],
        'serviceTypes' => $serviceTypes,
        'serviceTypeLabels' => array_map(static fn (string $type): string => service_type_label($type), $serviceTypes),
        'vendorType' => $serviceTypes[0] ?? (string) ($row['vendor_type'] ?? ''),
        'vendorTypeLabel' => isset($serviceTypes[0]) ? service_type_label($serviceTypes[0]) : service_type_label((string) ($row['vendor_type'] ?? '')),
        'phone' => (string) ($row['phone'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'contactPerson' => (string) ($row['contact_person'] ?? ''),
        'paymentTermsDays' => as_int($row['payment_terms_days'] ?? 0),
        'openingBalanceValue' => as_float($row['opening_balance'] ?? 0),
        'openingBalance' => money(as_float($row['opening_balance'] ?? 0)),
        'isActive' => (bool) ($row['is_active'] ?? 1),
        'notes' => (string) ($row['notes'] ?? ''),
        'billCount' => as_int($summary['bill_count'] ?? 0),
        'outstandingValue' => as_float($summary['outstanding'] ?? 0),
        'outstanding' => money(as_float($summary['outstanding'] ?? 0)),
        'overdueValue' => as_float($summary['overdue'] ?? 0),
        'overdue' => money(as_float($summary['overdue'] ?? 0)),
    ];
}
function transform_vendor_bill(array $row): array {
    return [
        'id' => as_int($row['id']),
        'billNumber' => (string) $row['bill_number'],
        'vendorId' => as_int($row['vendor_id']),
        'vendorName' => (string) ($row['vendor_name'] ?? ''),
        'billDate' => (string) $row['bill_date'],
        'dueDate' => (string) $row['due_date'],
        'sourceModule' => (string) $row['source_module'],
        'sourceReference' => (string) ($row['source_reference'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'subtotalValue' => as_float($row['subtotal']),
        'subtotal' => money(as_float($row['subtotal'])),
        'taxAmountValue' => as_float($row['tax_amount']),
        'taxAmount' => money(as_float($row['tax_amount'])),
        'discountAmountValue' => as_float($row['discount_amount']),
        'discountAmount' => money(as_float($row['discount_amount'])),
        'grandTotalValue' => as_float($row['grand_total']),
        'grandTotal' => money(as_float($row['grand_total'])),
        'paidAmountValue' => as_float($row['paid_amount']),
        'paidAmount' => money(as_float($row['paid_amount'])),
        'balanceDueValue' => as_float($row['balance_due']),
        'balanceDue' => money(as_float($row['balance_due'])),
        'expenseCoaCode' => (string) ($row['expense_coa_code'] ?? ''),
        'payableCoaCode' => (string) ($row['payable_coa_code'] ?? ''),
        'hppCoaCode' => (string) ($row['hpp_coa_code'] ?? ''),
        'status' => (string) $row['status'],
        'statusLabel' => vendor_bill_status_label((string) $row['status']),
        'notes' => (string) ($row['notes'] ?? ''),
    ];
}
function set_activity_catalog_entry_active(PDO $db, string $addonType, int $referenceId, bool $isActive): void {
    $config = match ($addonType) {
        'scooter' => ['table' => 'scooter_catalog', 'label' => 'Scooter'],
        'operator' => ['table' => 'activity_operator_catalog', 'label' => 'Operator'],
        'island_tour' => ['table' => 'island_tour_catalog', 'label' => 'Island tour'],
        'boat_ticket' => ['table' => 'boat_ticket_catalog', 'label' => 'Boat ticket'],
        default => null,
    };
    if (!$config) fail('Tipe activity tidak dikenali.', 404);
    $existing = db_one($db, "SELECT id FROM {$config['table']} WHERE id = ? LIMIT 1", [$referenceId]);
    if (!$existing) fail("Master {$config['label']} tidak ditemukan.", 404);
    $db->prepare("UPDATE {$config['table']} SET is_active = ?, updated_at = ? WHERE id = ?")->execute([$isActive ? 1 : 0, now_ts(), $referenceId]);
}
function transform_vendor_payment(array $row, array $allocations = []): array {
    return [
        'id' => as_int($row['id']),
        'paymentNumber' => (string) $row['payment_number'],
        'vendorId' => as_int($row['vendor_id']),
        'vendorName' => (string) ($row['vendor_name'] ?? ''),
        'paymentDate' => (string) $row['payment_date'],
        'paymentMethod' => (string) $row['payment_method'],
        'paymentMethodLabel' => ucwords(str_replace('_', ' ', (string) $row['payment_method'])),
        'amountValue' => as_float($row['amount']),
        'amount' => money(as_float($row['amount'])),
        'referenceNumber' => (string) ($row['reference_number'] ?? ''),
        'cashBankCoaCode' => (string) ($row['cash_bank_coa_code'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'allocations' => $allocations,
    ];
}
function vendor_payables_report(PDO $db, ?int $vendorId = null): array {
    $params = [];
    $vendorWhere = " WHERE 1=1";
    if ($vendorId !== null) {
        $vendorWhere .= ' AND v.id = ?';
        $params[] = $vendorId;
    }

    $vendors = db_all($db, "
        SELECT v.*,
            (SELECT GROUP_CONCAT(DISTINCT vs2.service_type ORDER BY vs2.service_type SEPARATOR ',') FROM vendor_services vs2 WHERE vs2.vendor_id = v.id) AS service_types,
            COUNT(b.id) AS bill_count,
            COALESCE(SUM(CASE WHEN b.status != 'void' THEN b.balance_due ELSE 0 END), 0) AS outstanding,
            COALESCE(SUM(CASE WHEN b.status != 'void' AND b.balance_due > 0 AND b.due_date < CURDATE() THEN b.balance_due ELSE 0 END), 0) AS overdue
        FROM vendors v
        LEFT JOIN vendor_bills b ON b.vendor_id = v.id
        {$vendorWhere}
        GROUP BY v.id
        ORDER BY outstanding DESC, v.vendor_name ASC
    ", $params);

    $billParams = [];
    $billWhere = " WHERE b.status != 'void'";
    if ($vendorId !== null) {
        $billWhere .= ' AND b.vendor_id = ?';
        $billParams[] = $vendorId;
    }
    $bills = db_all($db, "
        SELECT b.*, v.vendor_name
        FROM vendor_bills b
        INNER JOIN vendors v ON v.id = b.vendor_id
        {$billWhere}
        ORDER BY b.due_date ASC, b.id DESC
    ", $billParams);

    $paymentParams = [];
    if ($vendorId !== null) {
        $paymentParams[] = $vendorId;
    }
    $payments = db_all($db, "
        SELECT p.*, v.vendor_name
        FROM vendor_payments p
        INNER JOIN vendors v ON v.id = p.vendor_id
        WHERE 1=1" . ($vendorId !== null ? " AND p.vendor_id = ?" : "") . "
        ORDER BY p.payment_date DESC, p.id DESC
    ", $paymentParams);

    $summary = [
        'vendorCount' => count($vendors),
        'openBillCount' => 0,
        'totalOutstandingValue' => 0.0,
        'totalOverdueValue' => 0.0,
        'dueThisWeekValue' => 0.0,
        'currentValue' => 0.0,
        'aging30Value' => 0.0,
        'aging60Value' => 0.0,
        'aging90Value' => 0.0,
        'agingAbove90Value' => 0.0,
    ];

    $today = strtotime(today_date());
    $weekEnd = strtotime('+7 day', $today);
    foreach ($bills as $bill) {
        $balanceDue = as_float($bill['balance_due']);
        if ($balanceDue <= 0) continue;
        $summary['openBillCount']++;
        $summary['totalOutstandingValue'] += $balanceDue;
        $dueDate = strtotime((string) $bill['due_date']);
        if ($dueDate < $today) {
            $summary['totalOverdueValue'] += $balanceDue;
        }
        if ($dueDate >= $today && $dueDate <= $weekEnd) {
            $summary['dueThisWeekValue'] += $balanceDue;
        }
        $ageDays = (int) floor(($today - $dueDate) / 86400);
        if ($ageDays <= 0) $summary['currentValue'] += $balanceDue;
        elseif ($ageDays <= 30) $summary['aging30Value'] += $balanceDue;
        elseif ($ageDays <= 60) $summary['aging60Value'] += $balanceDue;
        elseif ($ageDays <= 90) $summary['aging90Value'] += $balanceDue;
        else $summary['agingAbove90Value'] += $balanceDue;
    }

    return [
        'summary' => [
            ...$summary,
            'totalOutstanding' => money($summary['totalOutstandingValue']),
            'totalOverdue' => money($summary['totalOverdueValue']),
            'dueThisWeek' => money($summary['dueThisWeekValue']),
            'current' => money($summary['currentValue']),
            'aging30' => money($summary['aging30Value']),
            'aging60' => money($summary['aging60Value']),
            'aging90' => money($summary['aging90Value']),
            'agingAbove90' => money($summary['agingAbove90Value']),
        ],
        'vendors' => array_map(static fn (array $row): array => transform_vendor($row, $row), $vendors),
        'bills' => array_map(static fn (array $row): array => transform_vendor_bill($row), $bills),
        'payments' => array_map(static fn (array $row): array => transform_vendor_payment($row), $payments),
    ];
}

function guest_upsert(PDO $db, ?int $guestId, array $payload): int {
    $now = now_ts();
    if ($guestId) { $db->prepare("UPDATE guests SET full_name=?, phone=?, email=?, notes=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['guest']), trim((string) ($payload['phone'] ?? '')) ?: null, trim((string) ($payload['email'] ?? '')) ?: null, trim((string) ($payload['note'] ?? '')) ?: null, $now, $guestId]); return $guestId; }
    $guestCode = 'GST-' . date('ymdHis') . '-' . random_int(10, 99);
    $db->prepare("INSERT INTO guests (guest_code, full_name, email, phone, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$guestCode, trim((string) $payload['guest']), trim((string) ($payload['email'] ?? '')) ?: null, trim((string) ($payload['phone'] ?? '')) ?: null, trim((string) ($payload['note'] ?? '')) ?: null, $now, $now]);
    return (int) $db->lastInsertId();
}
function calculate_room_amount(array $roomDetails, string $checkIn, string $checkOut): float { $nights = max(1, (int) floor((strtotime($checkOut) - strtotime($checkIn)) / 86400)); $total = 0.0; foreach ($roomDetails as $detail) $total += as_float($detail['rate'] ?? 0) * $nights; return $total; }
function generate_booking_code(PDO $db, string $checkIn): string { $prefix = 'BK-' . date('ymd', strtotime($checkIn)); $last = (string) db_value($db, "SELECT booking_code FROM bookings WHERE booking_code LIKE ? ORDER BY id DESC LIMIT 1", [$prefix . '-%']); return sprintf('%s-%03d', $prefix, ($last !== '' ? (int) substr($last, -3) : 0) + 1); }
function generate_payment_code(PDO $db, string $type = 'payment'): string {
    $normalized = match (strtolower(trim($type))) { 'refund' => 'REF', 'void' => 'VOID', default => 'PAY', };
    $prefix = $normalized . '-' . date('ymd');
    $last = (string) db_value($db, "SELECT payment_number FROM payments WHERE payment_number LIKE ? ORDER BY id DESC LIMIT 1", [$prefix . '-%']);
    return sprintf('%s-%03d', $prefix, ($last !== '' ? (int) substr($last, -3) : 0) + 1);
}
function generate_vendor_code(PDO $db): string {
    $last = (string) db_value($db, "SELECT vendor_code FROM vendors WHERE vendor_code LIKE 'VND-%' ORDER BY id DESC LIMIT 1");
    return sprintf('VND-%03d', ($last !== '' ? (int) substr($last, -3) : 0) + 1);
}
function generate_vendor_bill_number(PDO $db, string $billDate): string {
    $prefix = 'AP-' . date('ymd', strtotime($billDate));
    $last = (string) db_value($db, "SELECT bill_number FROM vendor_bills WHERE bill_number LIKE ? ORDER BY id DESC LIMIT 1", [$prefix . '-%']);
    return sprintf('%s-%03d', $prefix, ($last !== '' ? (int) substr($last, -3) : 0) + 1);
}
function generate_vendor_payment_number(PDO $db, string $paymentDate): string {
    $prefix = 'VP-' . date('ymd', strtotime($paymentDate));
    $last = (string) db_value($db, "SELECT payment_number FROM vendor_payments WHERE payment_number LIKE ? ORDER BY id DESC LIMIT 1", [$prefix . '-%']);
    return sprintf('%s-%03d', $prefix, ($last !== '' ? (int) substr($last, -3) : 0) + 1);
}
function generate_journal_number(PDO $db, string $journalDate): string { $count = (int) db_value($db, "SELECT COUNT(*) FROM journals WHERE journal_date = ?", [$journalDate]) + 1; return sprintf('JU-%s-%03d', str_replace('-', '', $journalDate), $count); }
function coa_code_only(string $value): string { return trim((string) strtok($value, '-')); }
function hotel_setting_value(PDO $db, string $key, mixed $default = null): mixed {
    $value = db_value($db, "SELECT setting_value FROM hotel_settings WHERE setting_key = ? LIMIT 1", [$key]);
    return $value === false || $value === null || $value === '' ? $default : $value;
}
function hotel_setting_float(PDO $db, string $key, float $default = 0.0): float { return as_float(hotel_setting_value($db, $key, $default)); }
function upsert_hotel_setting(PDO $db, string $key, mixed $value): void {
    $now = now_ts();
    $db->prepare("INSERT INTO hotel_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)")
        ->execute([$key, (string) $value, $now, $now]);
}
function current_business_date(PDO $db): string {
    $value = trim((string) hotel_setting_value($db, 'current_business_date', today_date()));
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return today_date();
    return $value;
}
function build_business_date_label(string $date): string {
    return date('d F Y', strtotime($date)) . ' | Day Shift';
}
function next_business_date(string $date): string {
    return date('Y-m-d', strtotime($date . ' +1 day'));
}
function last_closed_business_date(PDO $db): ?string {
    $value = trim((string) hotel_setting_value($db, 'last_closed_business_date', ''));
    return $value !== '' ? $value : null;
}
function assert_open_transaction_date(PDO $db, ?string $date, string $label = 'transaction date'): void {
    return;
}
function daily_payment_summary(PDO $db, string $businessDate): array {
    $rows = db_all($db, "SELECT payment_method, transaction_type, COALESCE(SUM(amount), 0) AS total_amount FROM payments WHERE payment_date = ? GROUP BY payment_method, transaction_type", [$businessDate]);
    $byMethod = ['cash' => 0.0, 'bank_transfer' => 0.0, 'credit_card' => 0.0, 'debit_card' => 0.0, 'qris' => 0.0, 'other' => 0.0];
    $grossCollections = 0.0;
    $refundsVoids = 0.0;
    foreach ($rows as $row) {
        $method = normalize_payment_method((string) ($row['payment_method'] ?? 'other'));
        $transactionType = payment_transaction_type($row['transaction_type'] ?? 'payment');
        $amount = as_float($row['total_amount'] ?? 0);
        $signedAmount = $transactionType === 'payment' ? $amount : -$amount;
        $byMethod[$method] = ($byMethod[$method] ?? 0.0) + $signedAmount;
        if ($transactionType === 'payment') $grossCollections += $amount;
        else $refundsVoids += $amount;
    }
    $netCollections = $grossCollections - $refundsVoids;
    return [
        'grossCollections' => $grossCollections,
        'grossCollectionsLabel' => money($grossCollections),
        'refundsVoids' => $refundsVoids,
        'refundsVoidsLabel' => money($refundsVoids),
        'netCollections' => $netCollections,
        'netCollectionsLabel' => money($netCollections),
        'cash' => $byMethod['cash'] ?? 0.0,
        'cashLabel' => money($byMethod['cash'] ?? 0.0),
        'bankTransfer' => $byMethod['bank_transfer'] ?? 0.0,
        'bankTransferLabel' => money($byMethod['bank_transfer'] ?? 0.0),
        'card' => ($byMethod['credit_card'] ?? 0.0) + ($byMethod['debit_card'] ?? 0.0),
        'cardLabel' => money(($byMethod['credit_card'] ?? 0.0) + ($byMethod['debit_card'] ?? 0.0)),
        'qris' => $byMethod['qris'] ?? 0.0,
        'qrisLabel' => money($byMethod['qris'] ?? 0.0),
        'other' => $byMethod['other'] ?? 0.0,
        'otherLabel' => money($byMethod['other'] ?? 0.0),
    ];
}
function booking_addon_fee_amount(PDO $db, array $addon): float {
    $grossAmount = round(as_float($addon['total_price'] ?? 0), 2);
    if ($grossAmount <= 0) return 0.0;
    $vendorAmount = booking_addon_vendor_amount($db, $addon);
    return round(max(0, $grossAmount - $vendorAmount), 2);
}
function booking_addon_fee_total_for_checkin_date(PDO $db, string $businessDate): float {
    $rows = db_all($db, "
        SELECT ba.*
        FROM booking_addons ba
        INNER JOIN bookings b ON b.id = ba.booking_id
        WHERE b.status NOT IN ('cancelled', 'no_show')
          AND ba.status != 'cancelled'
          AND DATE(b.check_in_at) = ?
    ", [$businessDate]);
    $total = 0.0;
    foreach ($rows as $row) $total += booking_addon_fee_amount($db, $row);
    return round($total, 2);
}
function owner_daily_financial_snapshot(PDO $db, string $businessDate): array {
    $todayRevenue = as_float(db_value($db, "
        SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
        FROM journal_lines jl
        INNER JOIN journals j ON j.id = jl.journal_id
        INNER JOIN coa_accounts c ON c.code = jl.coa_code
        WHERE j.journal_date = ? AND LOWER(c.category) = 'revenue'
    ", [$businessDate]));
    $todayExpense = as_float(db_value($db, "
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
        FROM journal_lines jl
        INNER JOIN journals j ON j.id = jl.journal_id
        INNER JOIN coa_accounts c ON c.code = jl.coa_code
        WHERE j.journal_date = ? AND LOWER(c.category) = 'expense'
    ", [$businessDate]));
    $todayPayablesAdded = as_float(db_value($db, "SELECT COALESCE(SUM(grand_total), 0) FROM vendor_bills WHERE status != 'void' AND bill_date = ?", [$businessDate]));
    $outstandingPayables = as_float(db_value($db, "SELECT COALESCE(SUM(balance_due), 0) FROM vendor_bills WHERE status != 'void' AND balance_due > 0"));
    $dueTodayPayables = as_float(db_value($db, "SELECT COALESCE(SUM(balance_due), 0) FROM vendor_bills WHERE status != 'void' AND balance_due > 0 AND due_date = ?", [$businessDate]));
    $overduePayables = as_float(db_value($db, "SELECT COALESCE(SUM(balance_due), 0) FROM vendor_bills WHERE status != 'void' AND balance_due > 0 AND due_date < ?", [$businessDate]));
    $paidTodayToVendors = as_float(db_value($db, "SELECT COALESCE(SUM(amount), 0) FROM vendor_payments WHERE payment_date = ?", [$businessDate]));
    return [
        'todayRevenue' => money($todayRevenue),
        'todayExpense' => money($todayExpense),
        'todayNet' => money($todayRevenue - $todayExpense),
        'todayPayablesAdded' => money($todayPayablesAdded),
        'outstandingPayables' => money($outstandingPayables),
        'dueTodayPayables' => money($dueTodayPayables),
        'overduePayables' => money($overduePayables),
        'paidTodayToVendors' => money($paidTodayToVendors),
    ];
}
function owner_annual_revenue_series(PDO $db, string $businessDate): array {
    $targetYear = date('Y', strtotime($businessDate));
    $monthStart = $targetYear . '-01-01';
    $monthEnd = $targetYear . '-12-31';
    $rows = db_all($db, "
        SELECT
            DATE_FORMAT(check_in_at, '%Y-%m') AS month_key,
            COALESCE(SUM(room_amount), 0) AS room_revenue,
            0 AS addon_revenue
        FROM bookings
        WHERE status NOT IN ('cancelled', 'no_show')
          AND DATE(check_in_at) BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(check_in_at, '%Y-%m')
        ORDER BY month_key ASC
    ", [$monthStart, $monthEnd]);
    $byMonth = [];
    foreach ($rows as $row) {
        $byMonth[(string) $row['month_key']] = [
            'room' => as_float($row['room_revenue'] ?? 0),
            'addon' => 0.0,
        ];
    }
    $addonRows = db_all($db, "
        SELECT ba.*, DATE_FORMAT(b.check_in_at, '%Y-%m') AS month_key
        FROM booking_addons ba
        INNER JOIN bookings b ON b.id = ba.booking_id
        WHERE b.status NOT IN ('cancelled', 'no_show')
          AND ba.status != 'cancelled'
          AND DATE(b.check_in_at) BETWEEN ? AND ?
        ORDER BY ba.id ASC
    ", [$monthStart, $monthEnd]);
    foreach ($addonRows as $row) {
        $monthKey = (string) ($row['month_key'] ?? '');
        if ($monthKey === '') continue;
        if (!isset($byMonth[$monthKey])) {
            $byMonth[$monthKey] = ['room' => 0.0, 'addon' => 0.0];
        }
        $byMonth[$monthKey]['addon'] += booking_addon_fee_amount($db, $row);
    }
    $series = [];
    for ($month = 1; $month <= 12; $month++) {
        $monthDate = sprintf('%s-%02d-01', $targetYear, $month);
        $monthKey = date('Y-m', strtotime($monthDate));
        $roomRevenue = $byMonth[$monthKey]['room'] ?? 0.0;
        $addonRevenue = $byMonth[$monthKey]['addon'] ?? 0.0;
        $totalRevenue = $roomRevenue + $addonRevenue;
        $series[] = [
            'monthKey' => $monthKey,
            'label' => date('M', strtotime($monthDate)),
            'roomRevenueValue' => $roomRevenue,
            'addonRevenueValue' => $addonRevenue,
            'totalRevenueValue' => $totalRevenue,
            'roomRevenue' => money($roomRevenue),
            'addonRevenue' => money($addonRevenue),
            'totalRevenue' => money($totalRevenue),
        ];
    }
    return $series;
}
function resolve_revenue_coa(PDO $db, array $preferredCodes, string $prefix): string {
    foreach ($preferredCodes as $code) if ((int) db_value($db, "SELECT COUNT(*) FROM coa_accounts WHERE code = ?", [$code]) > 0) return $code;
    $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'revenue' AND code LIKE ? ORDER BY code ASC LIMIT 1", [$prefix . '%']); if ($fallback) return (string) $fallback;
    $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'revenue' ORDER BY code ASC LIMIT 1"); return (string) ($fallback ?: $preferredCodes[0]);
}
function validate_coa_category(PDO $db, string $code, string $expectedCategory, string $fieldLabel): string {
    $normalizedCode = trim($code);
    if ($normalizedCode === '') return '';
    $row = db_one($db, "SELECT code, category, is_active FROM coa_accounts WHERE code = ? LIMIT 1", [$normalizedCode]);
    if (!$row) fail("{$fieldLabel} tidak ditemukan di master COA.", 422, ['coa' => ["{$fieldLabel} tidak ditemukan di master COA."]]);
    if (!as_int($row['is_active'] ?? 1)) fail("{$fieldLabel} tidak aktif di master COA.", 422, ['coa' => ["{$fieldLabel} tidak aktif di master COA."]]);
    if (strtolower((string) ($row['category'] ?? '')) !== strtolower($expectedCategory)) fail("{$fieldLabel} harus menggunakan akun kategori {$expectedCategory}.", 422, ['coa' => ["{$fieldLabel} harus menggunakan akun kategori {$expectedCategory}."]]);
    return (string) $row['code'];
}
function resolve_room_revenue_coa(PDO $db, string $source): string { $preferred = match ($source) { 'airbnb' => '411018', 'booking.com' => '411023', default => '411021', }; return resolve_revenue_coa($db, [$preferred, '411021', '411018', '411023'], '411'); }
function resolve_addon_revenue_coa(PDO $db, string $addonType): string { $preferred = match ($addonType) { 'transport' => '510001', 'scooter' => '510002', 'boat_ticket' => '510004', 'island_tour' => '510005', default => '411021', }; return resolve_revenue_coa($db, [$preferred, '411021', '510001'], str_starts_with($preferred, '510') ? '510' : '411'); }
function booking_addon_invoice_credit_rows(PDO $db, int $bookingId, string $bookingCode): array {
    $rows = db_all($db, "SELECT id, addon_type, reference_id, qty, total_price, notes FROM booking_addons WHERE booking_id = ? AND status != 'cancelled' ORDER BY id ASC", [$bookingId]);
    $lines = [];
    foreach ($rows as $row) {
        $addonType = trim((string) ($row['addon_type'] ?? ''));
        $grossAmount = round(as_float($row['total_price'] ?? 0), 2);
        if ($grossAmount <= 0) continue;
        $referenceId = as_int($row['reference_id'] ?? 0);
        $service = fetch_activity_service_snapshot($db, $addonType, $referenceId);
        $meta = json_decode((string) ($row['notes'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $vendorAmount = booking_addon_vendor_amount($db, $row, $service, $meta);
        $feeAmount = round(max(0, $grossAmount - $vendorAmount), 2);
        $feeCoa = $service ? ((string) ($service['fee_coa_code'] ?? '') ?: resolve_addon_revenue_coa($db, $addonType)) : resolve_addon_revenue_coa($db, $addonType);
        $payableCoa = $service ? (string) ($service['payable_coa_code'] ?? '') : '';
        if ($payableCoa !== '' && $vendorAmount > 0) {
            $lines[] = [
                'coa_code' => $payableCoa,
                'credit' => $vendorAmount,
                'memo' => "Vendor payable {$bookingCode} ({$addonType})",
            ];
        }
        if ($feeAmount > 0 || $vendorAmount <= 0) {
            $lines[] = [
                'coa_code' => $feeCoa,
                'credit' => $feeAmount > 0 ? $feeAmount : $grossAmount,
                'memo' => ($payableCoa !== '' && $vendorAmount > 0)
                    ? "Add-on fee {$bookingCode} ({$addonType})"
                    : "Add-on revenue {$bookingCode} ({$addonType})",
            ];
        }
    }
    return $lines;
}
function resolve_discount_coa(PDO $db, string $source): string { return resolve_revenue_coa($db, ['411099', resolve_room_revenue_coa($db, $source), '411021'], '411'); }
function resolve_cancellation_penalty_coa(PDO $db, string $source): string { return resolve_revenue_coa($db, ['411098', resolve_room_revenue_coa($db, $source), '411021'], '411'); }
function resolve_cash_bank_coa(PDO $db, string $method): string { $preferred = normalize_payment_method($method) === 'cash' ? '111001' : '111005'; if ((int) db_value($db, "SELECT COUNT(*) FROM coa_accounts WHERE code = ?", [$preferred]) > 0) return $preferred; $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'asset' AND code LIKE '111%' ORDER BY code ASC LIMIT 1"); return (string) ($fallback ?: '111001'); }
function resolve_receivable_coa(PDO $db): string { if ((int) db_value($db, "SELECT COUNT(*) FROM coa_accounts WHERE code = '112001'") > 0) return '112001'; $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'asset' AND code LIKE '112%' ORDER BY code ASC LIMIT 1"); return (string) ($fallback ?: '112001'); }
function cancellation_penalty_percent(PDO $db): float { return max(0, min(100, hotel_setting_float($db, 'booking_cancel_penalty_percent', 0))); }
function booking_charge_base(PDO $db, array $booking, ?float $addonTotal = null): float {
    $effectiveAddonTotal = $addonTotal ?? as_float(db_value($db, "SELECT COALESCE(SUM(total_price), 0) FROM booking_addons WHERE booking_id = ? AND status != 'cancelled'", [as_int($booking['id'])]));
    return max(0, as_float($booking['room_amount']) + $effectiveAddonTotal - as_float($booking['discount_amount']));
}
function booking_cancellation_penalty_amount(PDO $db, array $booking, ?float $addonTotal = null): float {
    $percent = cancellation_penalty_percent($db);
    if ($percent <= 0) return 0.0;
    return round(booking_charge_base($db, $booking, $addonTotal) * ($percent / 100), 2);
}
function cancellation_policy_payload(PDO $db): array {
    $percent = cancellation_penalty_percent($db);
    return [
        'percent' => $percent,
        'label' => rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'),
        'enabled' => $percent > 0,
    ];
}
function payment_transaction_type(mixed $value): string {
    return match (strtolower(trim((string) $value))) { 'refund' => 'refund', 'void' => 'void', default => 'payment', };
}
function payment_signed_amount(array $payment): float {
    $amount = as_float($payment['amount'] ?? 0);
    return in_array(payment_transaction_type($payment['transaction_type'] ?? 'payment'), ['refund', 'void'], true) ? -$amount : $amount;
}
function payment_has_reversal(PDO $db, int $paymentId): bool {
    return (int) db_value($db, "SELECT COUNT(*) FROM payments WHERE parent_payment_id = ? AND transaction_type IN ('refund', 'void')", [$paymentId]) > 0;
}
function reverse_payment_entry(PDO $db, int $paymentId, string $transactionType, array $payload = []): array {
    $payment = db_one($db, "SELECT * FROM payments WHERE id = ? LIMIT 1", [$paymentId]); if (!$payment) fail('Payment tidak ditemukan.', 404);
    if (payment_transaction_type($payment['transaction_type'] ?? 'payment') !== 'payment') fail('Hanya payment utama yang bisa di-refund atau di-void.', 422);
    if (payment_has_reversal($db, $paymentId)) fail('Payment ini sudah pernah direfund atau di-void.', 422);
    $allocation = db_one($db, "SELECT * FROM payment_allocations WHERE payment_id = ? LIMIT 1", [$paymentId]); if (!$allocation) fail('Alokasi payment tidak ditemukan.', 422);
    $invoice = db_one($db, "SELECT * FROM invoices WHERE id = ? LIMIT 1", [$allocation['invoice_id']]); if (!$invoice) fail('Invoice terkait payment tidak ditemukan.', 422);
    $booking = db_one($db, "SELECT * FROM bookings WHERE id = ? LIMIT 1", [$invoice['booking_id']]); if (!$booking) fail('Booking terkait payment tidak ditemukan.', 422);
    $amount = as_float($payment['amount']); if ($amount <= 0) fail('Nominal payment tidak valid untuk direfund.', 422);
    $dateField = $transactionType === 'void' ? 'voidDate' : 'refundDate';
    $postedDate = trim((string) ($payload[$dateField] ?? '')) ?: current_business_date($db);
    assert_open_transaction_date($db, $postedDate, $transactionType === 'void' ? 'Void date' : 'Refund date');
    $note = trim((string) ($payload['note'] ?? '')) ?: ($transactionType === 'void' ? 'Void payment' : 'Refund payment');
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO payments (payment_number, guest_id, payment_date, payment_method, amount, transaction_type, parent_payment_id, cash_bank_coa_code, reference_number, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([generate_payment_code($db, $transactionType), $payment['guest_id'], $postedDate, $payment['payment_method'], $amount, $transactionType, $paymentId, $payment['cash_bank_coa_code'], trim((string) ($payload['referenceNo'] ?? '')) ?: ($payment['reference_number'] ?? null), $note, now_ts(), now_ts()]);
        $reverseId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, allocated_amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$reverseId, $invoice['id'], -$amount, now_ts(), now_ts()]);
        sync_payment_accounting($db, $reverseId);
        sync_booking_financial_state($db, as_int($booking['id']));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $entry = db_one($db, "SELECT * FROM payments WHERE id = ? LIMIT 1", [$reverseId]);
    return ['payment' => $entry ? payment_transform($db, $entry) : null, 'booking_code' => $booking['booking_code']];
}
function room_details_for_booking(PDO $db, int $bookingId): array {
    $rows = db_all($db, "SELECT br.*, r.room_code FROM booking_rooms br JOIN rooms r ON r.id = br.room_id WHERE br.booking_id = ? ORDER BY r.room_code ASC", [$bookingId]);
    return array_map(static fn (array $row): array => ['room' => $row['room_code'], 'roomType' => '', 'rate' => as_float($row['rate']), 'adults' => as_int($row['adult_count']), 'children' => as_int($row['child_count'])], $rows);
}
function addon_rows_for_booking(PDO $db, int $bookingId): array {
    $rows = db_all($db, "SELECT * FROM booking_addons WHERE booking_id = ? ORDER BY id ASC", [$bookingId]);
    return array_map(static function (array $row): array {
        $meta = json_decode((string) ($row['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : ['note' => (string) ($row['notes'] ?? '')];
        $serviceDate = $row['service_date'] ?: ($row['start_date'] ?: null); $startDate = $row['start_date'] ?: null; $endDate = $row['end_date'] ?: null;
        $vendorUnitPrice = as_float($meta['vendorUnitPriceValue'] ?? 0);
        $vendorTotalPrice = as_float($meta['vendorTotalPriceValue'] ?? ($vendorUnitPrice * as_int($row['qty'])));
        $feeTotalValue = as_float($meta['feeTotalValue'] ?? (as_float($row['total_price']) - $vendorTotalPrice));
        return ['id' => as_int($row['id']), 'addonType' => $row['addon_type'], 'addonLabel' => $meta['addonLabel'] ?? ucfirst(str_replace('_', ' ', (string) $row['addon_type'])), 'serviceName' => $meta['serviceName'] ?? 'Add-on service', 'itemRef' => $meta['itemRef'] ?? '', 'serviceDate' => $serviceDate, 'startDate' => $startDate, 'endDate' => $endDate, 'serviceDateLabel' => $row['addon_type'] === 'scooter' && $startDate && $endDate ? "{$startDate} to {$endDate}" : $serviceDate, 'quantity' => as_int($row['qty']), 'unitPriceValue' => as_float($row['unit_price']), 'unitPrice' => money(as_float($row['unit_price'])), 'vendorUnitPriceValue' => $vendorUnitPrice, 'vendorUnitPrice' => money($vendorUnitPrice), 'totalPriceValue' => as_float($row['total_price']), 'totalPrice' => money(as_float($row['total_price'])), 'vendorTotalPriceValue' => $vendorTotalPrice, 'vendorTotalPrice' => money($vendorTotalPrice), 'feeTotalValue' => $feeTotalValue, 'feeTotal' => money($feeTotalValue), 'status' => addon_status_label((string) $row['status']), 'notes' => $meta['note'] ?? ''];
    }, $rows);
}
function transform_booking(PDO $db, array $booking): array {
    $roomDetails = room_details_for_booking($db, as_int($booking['id'])); $addons = addon_rows_for_booking($db, as_int($booking['id'])); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]); $rooms = array_values(array_filter(array_map(static fn (array $row): ?string => $row['room'] ?: null, $roomDetails)));
    return ['code' => $booking['booking_code'], 'guest' => $booking['guest_name'] ?? '', 'phone' => $booking['guest_phone'] ?? '', 'email' => $booking['guest_email'] ?? '', 'checkIn' => date('Y-m-d H:i', strtotime((string) $booking['check_in_at'])), 'checkOut' => date('Y-m-d H:i', strtotime((string) $booking['check_out_at'])), 'channel' => source_label((string) $booking['source']), 'status' => booking_status_label((string) $booking['status']), 'note' => $booking['notes'] ?? '', 'roomDetails' => $roomDetails, 'rooms' => $rooms, 'room' => implode(', ', $rooms), 'roomType' => implode(', ', array_values(array_unique(array_filter(array_map(static fn (array $row): ?string => $row['roomType'] ?: null, $roomDetails))))), 'roomCount' => count($roomDetails), 'adults' => array_sum(array_map(static fn (array $row): int => as_int($row['adults']), $roomDetails)), 'children' => array_sum(array_map(static fn (array $row): int => as_int($row['children']), $roomDetails)), 'amountValue' => as_float($booking['room_amount']), 'amount' => money(as_float($booking['room_amount'])), 'addons' => $addons, 'addonsTotalValue' => as_float($booking['addon_amount']), 'addonsTotal' => money(as_float($booking['addon_amount'])), 'grandTotalValue' => as_float($booking['grand_total']), 'grandTotal' => money(as_float($booking['grand_total'])), 'invoiceNo' => $invoice['invoice_number'] ?? ('INV-' . str_replace('BK-', '', (string) $booking['booking_code'])), 'issueDate' => $invoice['invoice_date'] ?? date('Y-m-d', strtotime((string) $booking['check_in_at'])), 'dueDate' => $invoice['due_date'] ?? date('Y-m-d', strtotime((string) $booking['check_out_at'])), 'paidAmountValue' => as_float($invoice['paid_amount'] ?? 0), 'paidAmount' => money(as_float($invoice['paid_amount'] ?? 0)), 'balanceValue' => as_float($invoice['balance_due'] ?? $booking['grand_total']), 'balanceAmount' => money(as_float($invoice['balance_due'] ?? $booking['grand_total'])), 'invoiceStatus' => ucfirst((string) ($invoice['status'] ?? 'draft'))];
}
function load_booking_row(PDO $db, string $bookingCode): ?array { return db_one($db, "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone, g.email AS guest_email FROM bookings b JOIN guests g ON g.id = b.guest_id WHERE b.booking_code = ? LIMIT 1", [$bookingCode]); }
function sync_booking_rooms(PDO $db, int $bookingId, array $roomDetails, string $checkIn, string $checkOut): void {
    $db->prepare("DELETE FROM booking_rooms WHERE booking_id = ?")->execute([$bookingId]); $lookup = $db->prepare("SELECT id FROM rooms WHERE room_code = ? LIMIT 1");
    $insert = $db->prepare("INSERT INTO booking_rooms (booking_id, room_id, adult_count, child_count, rate, check_in_at, check_out_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"); $now = now_ts();
    foreach ($roomDetails as $detail) { $lookup->execute([trim((string) ($detail['room'] ?? ''))]); $roomId = $lookup->fetchColumn(); if (!$roomId) fail("Kamar {$detail['room']} tidak ditemukan."); $insert->execute([$bookingId, $roomId, max(1, as_int($detail['adults'] ?? 1)), max(0, as_int($detail['children'] ?? 0)), as_float($detail['rate'] ?? 0), $checkIn, $checkOut, $now, $now]); }
}
function ensure_rooms_available(PDO $db, array $roomDetails, string $checkIn, string $checkOut, ?int $ignoreBookingId = null): void {
    $seen = [];
    foreach ($roomDetails as $detail) {
        $roomCode = trim((string) ($detail['room'] ?? '')); if ($roomCode === '') fail('Data kamar belum lengkap.', 422, ['roomDetails' => ['Kode kamar wajib diisi.']]); if (isset($seen[$roomCode])) fail('Satu kamar tidak boleh dipilih lebih dari sekali.', 422, ['roomDetails' => ['Satu kamar tidak boleh dipilih lebih dari sekali.']]); $seen[$roomCode] = true;
        $room = db_one($db, "SELECT id FROM rooms WHERE room_code = ? LIMIT 1", [$roomCode]); if (!$room) fail("Kamar {$roomCode} tidak ditemukan.", 422, ['roomDetails' => ["Kamar {$roomCode} tidak ditemukan."]]);
        $params = [$room['id'], $checkOut, $checkIn]; $sql = "SELECT COUNT(*) FROM booking_rooms br JOIN bookings b ON b.id = br.booking_id WHERE br.room_id = ? AND b.status NOT IN ('cancelled', 'no_show') AND b.check_in_at < ? AND b.check_out_at > ?";
        if ($ignoreBookingId !== null) { $sql .= " AND b.id != ?"; $params[] = $ignoreBookingId; }
        if ((int) db_value($db, $sql, $params) > 0) fail("Kamar {$roomCode} sudah terpakai pada rentang tanggal tersebut.", 422, ['roomDetails' => ["Kamar {$roomCode} sudah terpakai pada rentang tanggal tersebut."]]);
    }
}
function housekeeping_task_blueprint(array $room): ?array {
    $status = strtolower(trim((string) ($room['status'] ?? '')));
    return match ($status) {
        'dirty' => ['task_type' => 'Clean room after check-out', 'task_status' => 'pending', 'priority' => 'high', 'owner_team' => 'Housekeeping', 'eta_note' => 'Need room attendant'],
        'cleaning' => ['task_type' => 'Cleaning in progress', 'task_status' => 'in_progress', 'priority' => 'high', 'owner_team' => 'Housekeeping', 'eta_note' => 'Housekeeping queue'],
        'blocked' => ['task_type' => 'Inspect blocked room', 'task_status' => 'pending', 'priority' => 'normal', 'owner_team' => 'Housekeeping', 'eta_note' => 'Need inspection'],
        'maintenance', 'repair' => ['task_type' => 'Engineering follow-up', 'task_status' => 'pending', 'priority' => 'high', 'owner_team' => 'Engineering', 'eta_note' => 'Need engineering'],
        default => null,
    };
}
function sync_housekeeping_queue(PDO $db, ?string $businessDate = null): void {
    $date = $businessDate ?: today_date();
    $rooms = db_all($db, "SELECT id, room_code, room_name, status, notes FROM rooms ORDER BY room_code ASC");
    foreach ($rooms as $room) {
        $blueprint = housekeeping_task_blueprint($room);
        $activeTask = db_one($db, "SELECT * FROM housekeeping_tasks WHERE room_id = ? AND business_date = ? AND task_status IN ('pending', 'in_progress') ORDER BY id DESC LIMIT 1", [$room['id'], $date]);
        if ($blueprint) {
            if ($activeTask) {
                $db->prepare("UPDATE housekeeping_tasks SET task_type = ?, task_status = ?, priority = ?, owner_team = ?, eta_note = ?, task_note = ?, source_status = ?, updated_at = ? WHERE id = ?")
                    ->execute([$blueprint['task_type'], $blueprint['task_status'], $blueprint['priority'], $blueprint['owner_team'], $blueprint['eta_note'], trim((string) ($room['notes'] ?? '')) ?: null, strtolower(trim((string) ($room['status'] ?? ''))), now_ts(), $activeTask['id']]);
            } else {
                $db->prepare("INSERT INTO housekeeping_tasks (room_id, business_date, task_type, task_status, priority, owner_team, eta_note, task_note, source_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$room['id'], $date, $blueprint['task_type'], $blueprint['task_status'], $blueprint['priority'], $blueprint['owner_team'], $blueprint['eta_note'], trim((string) ($room['notes'] ?? '')) ?: null, strtolower(trim((string) ($room['status'] ?? ''))), now_ts(), now_ts()]);
            }
            continue;
        }
        if ($activeTask) {
            $db->prepare("UPDATE housekeeping_tasks SET task_status = 'done', completed_at = COALESCE(completed_at, ?), updated_at = ? WHERE id = ?")
                ->execute([now_ts(), now_ts(), $activeTask['id']]);
        }
    }
}
function housekeeping_queue_rows(PDO $db, ?string $businessDate = null): array {
    $date = $businessDate ?: today_date();
    sync_housekeeping_queue($db, $date);
    $rows = db_all($db, "SELECT ht.*, r.room_code, r.room_name, r.status AS room_status FROM housekeeping_tasks ht JOIN rooms r ON r.id = ht.room_id WHERE ht.business_date = ? AND ht.task_status IN ('pending', 'in_progress') ORDER BY FIELD(ht.priority, 'high', 'normal', 'low'), ht.id ASC", [$date]);
    return array_map(static fn (array $row): array => [
        'id' => as_int($row['id']),
        'room' => $row['room_code'],
        'roomName' => $row['room_name'],
        'task' => $row['task_type'],
        'eta' => $row['eta_note'] ?: 'Queue',
        'owner' => $row['owner_team'],
        'status' => ucfirst(str_replace('_', ' ', (string) $row['task_status'])),
        'priority' => ucfirst((string) $row['priority']),
        'note' => $row['task_note'] ?? '',
        'sourceStatus' => ucfirst((string) $row['source_status']),
        'canStart' => (string) $row['task_status'] === 'pending',
        'canComplete' => in_array((string) $row['task_status'], ['pending', 'in_progress'], true),
    ], $rows);
}
function update_housekeeping_task_status(PDO $db, int $taskId, string $status): array {
    $normalized = match (strtolower(trim($status))) { 'in_progress', 'started', 'start' => 'in_progress', 'done', 'completed', 'complete' => 'done', 'cancelled', 'canceled' => 'cancelled', default => 'pending', };
    $task = db_one($db, "SELECT * FROM housekeeping_tasks WHERE id = ? LIMIT 1", [$taskId]); if (!$task) fail('Task housekeeping tidak ditemukan.', 404);
    $room = db_one($db, "SELECT * FROM rooms WHERE id = ? LIMIT 1", [$task['room_id']]); if (!$room) fail('Room task housekeeping tidak ditemukan.', 404);
    $db->beginTransaction();
    try {
        $startedAt = $normalized === 'in_progress' ? now_ts() : $task['started_at'];
        $completedAt = $normalized === 'done' ? now_ts() : ($normalized === 'cancelled' ? now_ts() : null);
        $db->prepare("UPDATE housekeeping_tasks SET task_status = ?, started_at = ?, completed_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$normalized, $startedAt, $completedAt, now_ts(), $taskId]);
        $roomStatus = strtolower(trim((string) ($room['status'] ?? 'available')));
        if ($normalized === 'in_progress' && $roomStatus === 'dirty') {
            $db->prepare("UPDATE rooms SET status = 'cleaning', updated_at = ? WHERE id = ?")->execute([now_ts(), $room['id']]);
        }
        if ($normalized === 'done') {
            $nextRoomStatus = in_array($roomStatus, ['maintenance', 'repair'], true) ? 'available' : 'available';
            $db->prepare("UPDATE rooms SET status = ?, updated_at = ? WHERE id = ?")->execute([$nextRoomStatus, now_ts(), $room['id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    sync_housekeeping_queue($db, (string) $task['business_date']);
    $fresh = db_one($db, "SELECT * FROM housekeeping_tasks WHERE id = ? LIMIT 1", [$taskId]);
    return ['id' => as_int($fresh['id']), 'status' => ucfirst(str_replace('_', ' ', (string) $fresh['task_status'])), 'room' => $room['room_code']];
}
function upsert_booking_invoice_journal(PDO $db, int $bookingId, ?int $invoiceId): void {
    if (!$invoiceId) return; $booking = db_one($db, "SELECT * FROM bookings WHERE id = ?", [$bookingId]); $invoice = db_one($db, "SELECT * FROM invoices WHERE id = ?", [$invoiceId]); if (!$booking || !$invoice) return;
    $roomRevenue = as_float($booking['room_amount']); $discountAmount = as_float($booking['discount_amount']); $receivableTotal = as_float($invoice['grand_total']); $addonCreditRows = booking_addon_invoice_credit_rows($db, $bookingId, (string) $booking['booking_code']);
    $journal = db_one($db, "SELECT * FROM journals WHERE source = 'invoice' AND reference_type = 'booking' AND reference_id = ? LIMIT 1", [$bookingId]); $journalDate = $invoice['invoice_date'] ?: today_date();
    $isCancelledWithPenalty = (string) $booking['status'] === 'cancelled' && $receivableTotal > 0;
    if (((string) $booking['status'] === 'no_show') || ((string) $booking['status'] === 'cancelled' && !$isCancelledWithPenalty) || $receivableTotal <= 0) {
        if ($journal) {
            $db->prepare("DELETE FROM journal_lines WHERE journal_id = ?")->execute([as_int($journal['id'])]);
            $db->prepare("DELETE FROM journals WHERE id = ?")->execute([as_int($journal['id'])]);
        }
        return;
    }
    if ($journal) { $journalId = as_int($journal['id']); $db->prepare("UPDATE journals SET journal_date = ?, description = ?, updated_at = ? WHERE id = ?")->execute([$journalDate, "Invoice {$invoice['invoice_number']} for {$booking['booking_code']}", now_ts(), $journalId]); $db->prepare("DELETE FROM journal_lines WHERE journal_id = ?")->execute([$journalId]); }
    else { $db->prepare("INSERT INTO journals (journal_number, journal_date, reference_type, reference_id, description, source, posted_by, created_at, updated_at) VALUES (?, ?, 'booking', ?, ?, 'invoice', NULL, ?, ?)")->execute([generate_journal_number($db, $journalDate), $journalDate, $bookingId, "Invoice {$invoice['invoice_number']} for {$booking['booking_code']}", now_ts(), now_ts()]); $journalId = (int) $db->lastInsertId(); }
    $insert = $db->prepare("INSERT INTO journal_lines (journal_id, coa_code, line_description, debit, credit, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)"); $now = now_ts();
    $insert->execute([$journalId, resolve_receivable_coa($db), "Accounts receivable {$invoice['invoice_number']}", $receivableTotal, 0, $now, $now]);
    if ($isCancelledWithPenalty) {
        $insert->execute([$journalId, resolve_cancellation_penalty_coa($db, (string) $booking['source']), "Cancellation penalty {$booking['booking_code']}", 0, $receivableTotal, $now, $now]);
        return;
    }
    if ($roomRevenue > 0) $insert->execute([$journalId, resolve_room_revenue_coa($db, (string) $booking['source']), "Room revenue {$booking['booking_code']}", 0, $roomRevenue, $now, $now]);
    foreach ($addonCreditRows as $row) {
        $value = round(as_float($row['credit'] ?? 0), 2);
        if ($value <= 0) continue;
        $insert->execute([$journalId, (string) $row['coa_code'], (string) $row['memo'], 0, $value, $now, $now]);
    }
    if ($discountAmount > 0) $insert->execute([$journalId, resolve_discount_coa($db, (string) $booking['source']), "Discount {$booking['booking_code']}", $discountAmount, 0, $now, $now]);
}
function sync_booking_financial_state(PDO $db, int $bookingId): void {
    $booking = db_one($db, "SELECT * FROM bookings WHERE id = ?", [$bookingId]); if (!$booking) return; foreach (db_all($db, "SELECT id FROM booking_addons WHERE booking_id = ?", [$bookingId]) as $addonRow) sync_booking_addon_vendor_bill($db, as_int($addonRow['id']), $booking, null); $status = (string) $booking['status']; $addonTotal = as_float(db_value($db, "SELECT COALESCE(SUM(total_price), 0) FROM booking_addons WHERE booking_id = ? AND status != 'cancelled'", [$bookingId])); $chargeBase = booking_charge_base($db, $booking, $addonTotal); $isCancelled = in_array($status, ['cancelled', 'no_show'], true); $grandTotal = match ($status) { 'cancelled' => booking_cancellation_penalty_amount($db, $booking, $addonTotal), 'no_show' => 0.0, default => $chargeBase, };
    $db->prepare("UPDATE bookings SET addon_amount = ?, grand_total = ?, updated_at = ? WHERE id = ?")->execute([$addonTotal, $grandTotal, now_ts(), $bookingId]); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$bookingId]);
    if ($invoice) { $paidAmount = as_float(db_value($db, "SELECT COALESCE(SUM(allocated_amount),0) FROM payment_allocations WHERE invoice_id = ?", [$invoice['id']])); $balance = max(0, $grandTotal - $paidAmount); $invoiceStatus = $isCancelled ? ($balance <= 0 ? 'paid' : 'partial') : ($balance <= 0 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid')); $db->prepare("UPDATE invoices SET due_date = ?, subtotal = ?, discount_amount = ?, tax_amount = 0, grand_total = ?, paid_amount = ?, balance_due = ?, status = ?, updated_at = ? WHERE id = ?")->execute([date('Y-m-d', strtotime((string) $booking['check_out_at'])), $isCancelled ? $grandTotal : as_float($booking['room_amount']) + $addonTotal, $isCancelled ? 0 : as_float($booking['discount_amount']), $grandTotal, $paidAmount, $balance, $invoiceStatus, now_ts(), $invoice['id']]); $invoice['id'] = $invoice['id']; }
    elseif (!$isCancelled) { $db->prepare("INSERT INTO invoices (booking_id, invoice_number, invoice_date, due_date, subtotal, discount_amount, tax_amount, grand_total, paid_amount, balance_due, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0, ?, 'unpaid', ?, ?)")->execute([$bookingId, 'INV-' . str_replace('BK-', '', (string) $booking['booking_code']), today_date(), date('Y-m-d', strtotime((string) $booking['check_out_at'])), as_float($booking['room_amount']) + $addonTotal, as_float($booking['discount_amount']), $grandTotal, $grandTotal, now_ts(), now_ts()]); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$bookingId]); }
    elseif ($grandTotal > 0) { $paidAmount = 0.0; $balance = $grandTotal; $db->prepare("INSERT INTO invoices (booking_id, invoice_number, invoice_date, due_date, subtotal, discount_amount, tax_amount, grand_total, paid_amount, balance_due, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 'unpaid', ?, ?)")->execute([$bookingId, 'INV-' . str_replace('BK-', '', (string) $booking['booking_code']), today_date(), date('Y-m-d', strtotime((string) $booking['check_out_at'])), $grandTotal, $grandTotal, $paidAmount, $balance, now_ts(), now_ts()]); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$bookingId]); }
    upsert_booking_invoice_journal($db, $bookingId, $invoice ? as_int($invoice['id']) : null);
}
function sync_payment_accounting(PDO $db, int $paymentId): void {
    $payment = db_one($db, "SELECT * FROM payments WHERE id = ?", [$paymentId]); if (!$payment) return; $allocation = db_one($db, "SELECT * FROM payment_allocations WHERE payment_id = ? LIMIT 1", [$paymentId]); if (!$allocation) return; $invoice = db_one($db, "SELECT * FROM invoices WHERE id = ?", [$allocation['invoice_id']]); if (!$invoice) return;
    $transactionType = payment_transaction_type($payment['transaction_type'] ?? 'payment');
    $cashBank = $payment['cash_bank_coa_code'] ?: resolve_cash_bank_coa($db, (string) $payment['payment_method']); if ($cashBank !== $payment['cash_bank_coa_code']) $db->prepare("UPDATE payments SET cash_bank_coa_code = ?, updated_at = ? WHERE id = ?")->execute([$cashBank, now_ts(), $paymentId]);
    $source = $transactionType === 'payment' ? 'payment' : 'payment_' . $transactionType;
    $description = match ($transactionType) {
        'refund' => "Guest refund {$payment['payment_number']} for {$invoice['invoice_number']}",
        'void' => "Void payment {$payment['payment_number']} for {$invoice['invoice_number']}",
        default => "Guest payment {$payment['payment_number']} for {$invoice['invoice_number']}",
    };
    $journal = db_one($db, "SELECT * FROM journals WHERE source = ? AND reference_type = 'payment' AND reference_id = ? LIMIT 1", [$source, $paymentId]); $journalDate = $payment['payment_date'];
    if ($journal) { $journalId = as_int($journal['id']); $db->prepare("UPDATE journals SET journal_date = ?, description = ?, updated_at = ? WHERE id = ?")->execute([$journalDate, $description, now_ts(), $journalId]); $db->prepare("DELETE FROM journal_lines WHERE journal_id = ?")->execute([$journalId]); }
    else { $db->prepare("INSERT INTO journals (journal_number, journal_date, reference_type, reference_id, description, source, posted_by, created_at, updated_at) VALUES (?, ?, 'payment', ?, ?, ?, NULL, ?, ?)")->execute([generate_journal_number($db, (string) $journalDate), $journalDate, $paymentId, $description, $source, now_ts(), now_ts()]); $journalId = (int) $db->lastInsertId(); }
    $insert = $db->prepare("INSERT INTO journal_lines (journal_id, coa_code, line_description, debit, credit, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)"); $amount = as_float($payment['amount']); $now = now_ts();
    if ($transactionType === 'payment') {
        $insert->execute([$journalId, $cashBank, "Cash receipt {$payment['payment_number']}", $amount, 0, $now, $now]); $insert->execute([$journalId, resolve_receivable_coa($db), "Settlement invoice {$invoice['invoice_number']}", 0, $amount, $now, $now]);
    } else {
        $insert->execute([$journalId, resolve_receivable_coa($db), ucfirst($transactionType) . " {$payment['payment_number']}", $amount, 0, $now, $now]); $insert->execute([$journalId, $cashBank, "Cash reversal {$payment['payment_number']}", 0, $amount, $now, $now]);
    }
}
function payment_transform(PDO $db, array $payment): array {
    $allocation = db_one($db, "SELECT * FROM payment_allocations WHERE payment_id = ? LIMIT 1", [$payment['id']]); $invoice = $allocation ? db_one($db, "SELECT * FROM invoices WHERE id = ?", [$allocation['invoice_id']]) : null; $booking = $invoice ? db_one($db, "SELECT booking_code FROM bookings WHERE id = ?", [$invoice['booking_id']]) : null;
    $transactionType = payment_transaction_type($payment['transaction_type'] ?? 'payment'); $signedAmount = payment_signed_amount($payment); $hasReversal = payment_has_reversal($db, as_int($payment['id']));
    return ['id' => as_int($payment['id']), 'paymentNumber' => $payment['payment_number'], 'bookingCode' => $booking['booking_code'] ?? '', 'invoiceNo' => $invoice['invoice_number'] ?? '', 'paymentDate' => $payment['payment_date'], 'method' => ucfirst(str_replace('_', ' ', (string) $payment['payment_method'])), 'referenceNo' => $payment['reference_number'], 'amountValue' => as_float($payment['amount']), 'amount' => money(as_float($payment['amount'])), 'signedAmountValue' => $signedAmount, 'signedAmount' => money(abs($signedAmount)), 'transactionType' => $transactionType, 'transactionLabel' => match ($transactionType) { 'refund' => 'Refund', 'void' => 'Void', default => 'Payment', }, 'parentPaymentId' => isset($payment['parent_payment_id']) ? as_int($payment['parent_payment_id']) : null, 'note' => $payment['notes'] ?? '', 'canRefund' => $transactionType === 'payment' && !$hasReversal, 'canVoid' => $transactionType === 'payment' && !$hasReversal];
}
function invoice_print_base64url_decode(string $value): string {
    $normalized = strtr($value, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($normalized, true);
    return $decoded === false ? '' : $decoded;
}
function invoice_print_request_overrides(): array {
    $raw = trim((string) ($_GET['overrides'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $decoded = invoice_print_base64url_decode($raw);
    if ($decoded === '') {
        return [];
    }
    $payload = json_decode($decoded, true);
    return is_array($payload) ? $payload : [];
}
function invoice_print_apply_overrides(array $payload, array $overrides): array {
    if ($overrides === []) {
        return $payload;
    }

    if (isset($overrides['property']) && is_array($overrides['property'])) {
        foreach (['name', 'address', 'phone', 'email'] as $key) {
            if (array_key_exists($key, $overrides['property'])) {
                $payload['property'][$key] = trim((string) $overrides['property'][$key]);
            }
        }
    }

    if (isset($overrides['invoice']) && is_array($overrides['invoice'])) {
        $map = [
            'invoice_number' => 'invoice_number',
            'issued_at' => 'issued_at',
            'due_at' => 'due_at',
            'status' => 'status',
        ];
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $overrides['invoice'])) {
                $payload['invoice'][$target] = trim((string) $overrides['invoice'][$source]);
            }
        }
    }

    if (isset($overrides['booking']) && is_array($overrides['booking'])) {
        foreach (['code', 'guest', 'guestPhone', 'guestEmail', 'checkIn', 'checkOut', 'room', 'note', 'invoiceStatus'] as $key) {
            if (array_key_exists($key, $overrides['booking'])) {
                $payload['booking'][$key] = trim((string) $overrides['booking'][$key]);
            }
        }

        if (isset($overrides['booking']['roomDetails']) && is_array($overrides['booking']['roomDetails'])) {
            $payload['booking']['roomDetails'] = array_values(array_map(static function ($row): array {
                $row = is_array($row) ? $row : [];
                $rateValue = as_float($row['rateValue'] ?? 0);
                $lineTotalValue = as_float($row['lineTotalValue'] ?? 0);
                return [
                    'room' => trim((string) ($row['room'] ?? '-')),
                    'roomType' => trim((string) ($row['roomType'] ?? '')),
                    'adults' => max(0, (int) as_float($row['adults'] ?? 0)),
                    'children' => max(0, (int) as_float($row['children'] ?? 0)),
                    'rateValue' => $rateValue,
                    'lineTotalValue' => $lineTotalValue,
                ];
            }, $overrides['booking']['roomDetails']));
        }

        if (isset($overrides['booking']['addons']) && is_array($overrides['booking']['addons'])) {
            $payload['booking']['addons'] = array_values(array_map(static function ($row): array {
                $row = is_array($row) ? $row : [];
                $quantity = max(0, (int) as_float($row['quantity'] ?? 1));
                $unitPriceValue = as_float($row['unitPriceValue'] ?? 0);
                $totalPriceValue = as_float($row['totalPriceValue'] ?? ($quantity * $unitPriceValue));
                return [
                    'addonLabel' => trim((string) ($row['addonLabel'] ?? '-')),
                    'serviceName' => trim((string) ($row['serviceName'] ?? '')),
                    'serviceDateLabel' => trim((string) ($row['serviceDateLabel'] ?? '-')),
                    'quantity' => $quantity,
                    'unitPriceValue' => $unitPriceValue,
                    'totalPriceValue' => $totalPriceValue,
                ];
            }, $overrides['booking']['addons']));
        }

        if (isset($overrides['booking']['payments']) && is_array($overrides['booking']['payments'])) {
            $payload['booking']['payments'] = array_values(array_map(static function ($row): array {
                $row = is_array($row) ? $row : [];
                $signedAmountValue = as_float($row['signedAmountValue'] ?? $row['amountValue'] ?? 0);
                return [
                    'paymentDate' => trim((string) ($row['paymentDate'] ?? '-')),
                    'transactionLabel' => trim((string) ($row['transactionLabel'] ?? 'Payment')),
                    'method' => trim((string) ($row['method'] ?? '-')),
                    'referenceNo' => trim((string) ($row['referenceNo'] ?? '-')),
                    'signedAmountValue' => $signedAmountValue,
                    'amountValue' => $signedAmountValue,
                ];
            }, $overrides['booking']['payments']));
        }
    }

    $checkIn = (string) ($payload['booking']['checkIn'] ?? '');
    $checkOut = (string) ($payload['booking']['checkOut'] ?? '');
    $nights = 1;
    if ($checkIn !== '' && $checkOut !== '') {
        $nights = max(1, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    }

    if (is_array($payload['booking']['roomDetails'] ?? null)) {
        $roomTotal = 0.0;
        $roomNames = [];
        foreach ($payload['booking']['roomDetails'] as $index => $room) {
            $rateValue = as_float($room['rateValue'] ?? 0);
            $lineTotalValue = as_float($room['lineTotalValue'] ?? 0);
            if ($lineTotalValue <= 0) {
                $lineTotalValue = $rateValue * $nights;
            }
            $payload['booking']['roomDetails'][$index]['lineTotalValue'] = $lineTotalValue;
            $roomTotal += $lineTotalValue;
            $roomNames[] = trim((string) ($room['room'] ?? '-'));
        }
        $payload['booking']['amountValue'] = $roomTotal;
        $payload['booking']['roomCount'] = count($payload['booking']['roomDetails']);
        $payload['booking']['room'] = implode(', ', array_filter($roomNames, static fn ($item): bool => $item !== ''));
    }

    if (is_array($payload['booking']['addons'] ?? null)) {
        $addonsTotal = 0.0;
        foreach ($payload['booking']['addons'] as $index => $addon) {
            $quantity = max(0, (int) as_float($addon['quantity'] ?? 1));
            $unitPriceValue = as_float($addon['unitPriceValue'] ?? 0);
            $totalPriceValue = as_float($addon['totalPriceValue'] ?? ($quantity * $unitPriceValue));
            $payload['booking']['addons'][$index]['quantity'] = $quantity;
            $payload['booking']['addons'][$index]['unitPriceValue'] = $unitPriceValue;
            $payload['booking']['addons'][$index]['totalPriceValue'] = $totalPriceValue;
            $addonsTotal += $totalPriceValue;
        }
        $payload['booking']['addonsTotalValue'] = $addonsTotal;
    }

    if (is_array($payload['booking']['payments'] ?? null)) {
        $paidAmountValue = 0.0;
        foreach ($payload['booking']['payments'] as $payment) {
            $paidAmountValue += as_float($payment['signedAmountValue'] ?? $payment['amountValue'] ?? 0);
        }
        $payload['booking']['paidAmountValue'] = $paidAmountValue;
    }

    $roomTotalValue = as_float($payload['booking']['amountValue'] ?? 0);
    $addonsTotalValue = as_float($payload['booking']['addonsTotalValue'] ?? 0);
    $paidAmountValue = as_float($payload['booking']['paidAmountValue'] ?? 0);
    $payload['booking']['grandTotalValue'] = $roomTotalValue + $addonsTotalValue;
    $payload['booking']['balanceValue'] = max(0, $payload['booking']['grandTotalValue'] - $paidAmountValue);

    if (isset($overrides['document']) && is_array($overrides['document'])) {
        $payload['document'] = [
            'invoiceTitle' => trim((string) ($overrides['document']['invoiceTitle'] ?? 'INVOICE')) ?: 'INVOICE',
            'folioTitle' => trim((string) ($overrides['document']['folioTitle'] ?? 'FOLIO')) ?: 'FOLIO',
            'addonFooterNote' => trim((string) ($overrides['document']['addonFooterNote'] ?? 'Please review this invoice carefully. Payments are considered settled after confirmed receipt.')),
            'folioFooterNote' => trim((string) ($overrides['document']['folioFooterNote'] ?? 'Guest folio includes settlement movements and is intended for operational reconciliation.')),
        ];
    }

    if (($payload['booking']['invoiceStatus'] ?? '') !== '') {
        $payload['invoice']['status'] = (string) $payload['booking']['invoiceStatus'];
    } elseif (($payload['invoice']['status'] ?? '') !== '') {
        $payload['booking']['invoiceStatus'] = (string) $payload['invoice']['status'];
    }

    return $payload;
}
function invoice_pdf_payload(PDO $db, string $bookingCode, array $overrides = []): array {
    $booking = load_booking_row($db, $bookingCode);
    if (!$booking) fail('Booking tidak ditemukan.', 404);
    $bookingData = transform_booking($db, $booking);
    $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [as_int($booking['id'])]);
    if (!$invoice) fail('Invoice tidak ditemukan untuk booking ini.', 404);
    $payments = db_all(
        $db,
        "SELECT p.*
         FROM payments p
         JOIN payment_allocations pa ON pa.payment_id = p.id
         JOIN invoices i ON i.id = pa.invoice_id
         WHERE i.booking_id = ?
         ORDER BY p.payment_date ASC, p.id ASC",
        [as_int($booking['id'])]
    );
    $payload = [
        'booking' => $bookingData,
        'invoice' => $invoice,
        'property' => [
            'name' => trim((string) hotel_setting_value($db, 'property_legal_name', 'Udara Hideaway Villa')),
            'address' => trim((string) hotel_setting_value($db, 'property_address', 'Jl. Udara Hideaway No. 8, Indonesia')),
            'phone' => trim((string) hotel_setting_value($db, 'property_phone', '+62 000 0000 0000')),
            'email' => trim((string) hotel_setting_value($db, 'property_email', 'hello@udarahideawayvilla.com')),
        ],
        'document' => [
            'invoiceTitle' => 'INVOICE',
            'folioTitle' => 'FOLIO',
            'addonFooterNote' => 'Please review this invoice carefully. Payments are considered settled after confirmed receipt.',
            'folioFooterNote' => 'Guest folio includes settlement movements and is intended for operational reconciliation.',
        ],
        'payments' => array_map(static fn (array $payment): array => payment_transform($db, $payment), $payments),
    ];
    return invoice_print_apply_overrides($payload, $overrides);
}
function tcpdf_invoice_apply_stamp(\TCPDF $pdf, bool $isPaid): void {
    if (!$isPaid) {
        return;
    }
    $pageWidth = method_exists($pdf, 'getPageWidth') ? (float) $pdf->getPageWidth() : 210.0;
    $pageHeight = method_exists($pdf, 'getPageHeight') ? (float) $pdf->getPageHeight() : 148.5;
    $centerX = $pageWidth / 2;
    $centerY = $pageHeight / 2;
    if (method_exists($pdf, 'SetAlpha')) {
        $pdf->SetAlpha(0.08);
    }
    $pdf->SetTextColor(176, 24, 24);
    $pdf->SetFont('times', 'B', 30);
    $pdf->StartTransform();
    $pdf->Rotate(28, $centerX, $centerY);
    $pdf->Text($centerX - 18, $centerY, 'PAID');
    $pdf->StopTransform();
    if (method_exists($pdf, 'SetAlpha')) {
        $pdf->SetAlpha(1);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('times', '', 9);
}
function tcpdf_invoice_layout(\TCPDF $pdf): array {
    $left = 10.0;
    $right = 10.0;
    $top = 10.0;
    $bottom = 10.0;
    $pageWidth = method_exists($pdf, 'getPageWidth') ? (float) $pdf->getPageWidth() : 148.0;
    $contentWidth = max(100.0, $pageWidth - $left - $right);
    return [
        'left' => $left,
        'right' => $right,
        'top' => $top,
        'bottom' => $bottom,
        'pageWidth' => $pageWidth,
        'contentWidth' => $contentWidth,
    ];
}
function tcpdf_invoice_rule(\TCPDF $pdf, float $y): void {
    $layout = tcpdf_invoice_layout($pdf);
    $pdf->Line($layout['left'], $y, $layout['left'] + $layout['contentWidth'], $y, ['dash' => '2,2', 'width' => 0.2, 'color' => [110, 110, 110]]);
}
function tcpdf_invoice_header(\TCPDF $pdf, array $payload, string $documentType = 'invoice'): float {
    $property = $payload['property'];
    $booking = $payload['booking'];
    $invoice = $payload['invoice'];
    $statusLabel = trim((string) ($booking['invoiceStatus'] ?? $invoice['status'] ?? 'Open'));
    $issuedDate = substr((string) ($invoice['issued_at'] ?? $invoice['created_at'] ?? today_date()), 0, 10);
    $dueDate = (string) ($booking['checkIn'] ?? '') !== '' ? (string) $booking['checkIn'] : $issuedDate;

    $layout = tcpdf_invoice_layout($pdf);
    $left = $layout['left'];
    $contentWidth = $layout['contentWidth'];
    $metaWidth = min(48.0, $contentWidth * 0.34);
    $titleWidth = 40.0;
    $propertyWidth = max(44.0, $contentWidth - $metaWidth - $titleWidth);
    $titleX = $left + $propertyWidth;
    $metaX = $left + $contentWidth - $metaWidth;
    $startY = $layout['top'];

    $pdf->SetFont('times', 'B', 10.5);
    $pdf->SetXY($left, $startY);
    $pdf->Cell($propertyWidth, 4, (string) $property['name'], 0, 1, 'L');

    $pdf->SetFont('times', '', 9.2);
    $leftY = $startY + 4;
    foreach ([(string) $property['address'], 'Phone: ' . (string) $property['phone'], 'Email: ' . (string) $property['email']] as $line) {
        $pdf->SetXY($left, $leftY);
        $pdf->MultiCell($propertyWidth, 4.4, $line, 0, 'L', false, 1);
        $leftY = $pdf->GetY();
    }

    $documentTitle = $documentType === 'folio'
        ? (string) ($payload['document']['folioTitle'] ?? 'FOLIO')
        : (string) ($payload['document']['invoiceTitle'] ?? 'INVOICE');
    $pdf->SetFont('times', 'B', 14.5);
    $pdf->SetXY($titleX, $startY - 1);
    $pdf->Cell($titleWidth, 6, $documentTitle, 0, 0, 'C');

      $metaRows = [
          ['Number', (string) ($invoice['invoice_number'] ?? $booking['code'] ?? '')],
          ['Inv. Date', $issuedDate],
          ['Due Date', $dueDate],
          ['Booking Ref', (string) ($booking['code'] ?? '')],
          ['Currency', 'IDR'],
      ];
    $rightY = $startY;
    foreach ($metaRows as [$label, $value]) {
        $pdf->SetFont('times', '', 9);
        $pdf->SetXY($metaX, $rightY);
        $pdf->Cell(18, 4, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', 9);
        $pdf->Cell(4, 4, ':', 0, 0, 'C');
        $pdf->SetFont('times', 'B', 9);
        $pdf->MultiCell($metaWidth - 22, 4, (string) $value, 0, 'L', false, 1);
        $rightY = $pdf->GetY();
    }

    return max($leftY, $rightY) + 5;
}
function tcpdf_invoice_customer_block(\TCPDF $pdf, array $payload, float $y): float {
    $booking = $payload['booking'];
    $checkIn = (string) ($booking['checkIn'] ?? '');
    $checkOut = (string) ($booking['checkOut'] ?? '');
    $nights = 1;
    if ($checkIn !== '' && $checkOut !== '') {
        $nights = max(1, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    }
    $rows = [
        ['Customer', (string) ($booking['guest'] ?? '-')],
        ['Contact', (string) (($booking['guestPhone'] ?? '-') . ' / ' . ($booking['guestEmail'] ?? '-'))],
        ['Stay', $checkIn . ' to ' . $checkOut . ' (' . $nights . ' night(s))'],
    ];
    $layout = tcpdf_invoice_layout($pdf);
    $left = $layout['left'];
    $contentWidth = $layout['contentWidth'];
    foreach ($rows as [$label, $value]) {
        $pdf->SetFont('times', 'B', 9.5);
        $pdf->SetXY($left, $y);
        $pdf->Cell(22, 5, $label, 0, 0, 'L');
        $pdf->SetFont('times', '', 9.5);
        $pdf->Cell(4, 5, ':', 0, 0, 'C');
        $pdf->MultiCell($contentWidth - 26, 5, $value, 0, 'L', false, 1);
        $y = $pdf->GetY();
    }
    return $y + 2;
}
function tcpdf_invoice_room_page(\TCPDF $pdf, array $payload, string $documentType = 'invoice'): void {
    $y = tcpdf_invoice_header($pdf, $payload, $documentType);
    $y = tcpdf_invoice_customer_block($pdf, $payload, $y);
    tcpdf_invoice_rule($pdf, $y);
    $y += 3;

    $layout = tcpdf_invoice_layout($pdf);
    $left = $layout['left'];
    $contentWidth = $layout['contentWidth'];
    $baseWidths = [10, 56, 18, 24, 24];
    $widthTotal = array_sum($baseWidths);
    $widths = array_map(static fn ($width): float => round(($width / $widthTotal) * $contentWidth, 2), $baseWidths);
    $headers = ['No.', 'Room', 'Malam', 'Harga', 'Subtotal'];
    $aligns = ['C', 'L', 'C', 'R', 'R'];
    $x = $left;
    $pdf->SetFont('times', 'B', 9);
    foreach ($headers as $index => $header) {
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[$index], 5, $header, 0, 0, $aligns[$index]);
        $x += $widths[$index];
    }
    $y += 5;

    $rooms = is_array($payload['booking']['roomDetails'] ?? null) ? $payload['booking']['roomDetails'] : [];
    if ($rooms === []) {
        $rooms[] = [
            'room' => $payload['booking']['room'] ?? '-',
            'roomType' => '',
            'adults' => 0,
            'children' => 0,
            'rateValue' => as_float($payload['booking']['amountValue'] ?? 0),
            'lineTotalValue' => as_float($payload['booking']['amountValue'] ?? 0),
        ];
    }
    $checkIn = (string) ($payload['booking']['checkIn'] ?? '');
    $checkOut = (string) ($payload['booking']['checkOut'] ?? '');
    $nights = 1;
    if ($checkIn !== '' && $checkOut !== '') {
        $nights = max(1, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    }

      $pdf->SetFont('times', '', 9);
      foreach ($rooms as $index => $room) {
          $rowHeight = 8;
          $rateValue = as_float($room['rateValue'] ?? ($room['rate'] ?? 0));
          $subtotalValue = as_float($room['lineTotalValue'] ?? 0);
          if ($subtotalValue <= 0) {
              $subtotalValue = $rateValue * $nights;
          }
          $x = $left;
          $pdf->SetXY($x, $y);
          $pdf->Cell($widths[0], $rowHeight, (string) ($index + 1), 0, 0, 'C');
          $x += $widths[0];
          $pdf->SetXY($x, $y);
          $pdf->MultiCell($widths[1], 4, trim((string) ($room['room'] ?? '-') . "\n" . (string) ($room['roomType'] ?? '')), 0, 'L', false, 0);
          $x += $widths[1];
          $pdf->SetXY($x, $y);
          $pdf->Cell($widths[2], $rowHeight, (string) $nights, 0, 0, 'C');
          $x += $widths[2];
          $pdf->SetXY($x, $y);
          $pdf->Cell($widths[3], $rowHeight, money($rateValue), 0, 0, 'R');
          $x += $widths[3];
          $pdf->SetXY($x, $y);
          $pdf->Cell($widths[4], $rowHeight, money($subtotalValue), 0, 0, 'R');
          $y += $rowHeight;
          tcpdf_invoice_rule($pdf, $y);
          $y += 2;
      }

    $y += 2;
    tcpdf_invoice_rule($pdf, $y);
    $y += 3;
    $pdf->SetFont('times', 'B', 9.5);
      $labelWidth = 30;
      $valueWidth = 24;
      $summaryX = $left + $contentWidth - ($labelWidth + 4 + $valueWidth);
      $pdf->SetXY($summaryX, $y);
      $pdf->Cell($labelWidth, 5, 'Total Kamar Hotel', 0, 0, 'L');
      $pdf->Cell(4, 5, ':', 0, 0, 'C');
      $pdf->Cell($valueWidth, 5, money(as_float($payload['booking']['amountValue'] ?? 0)), 0, 0, 'R');
}
function tcpdf_invoice_addon_page(\TCPDF $pdf, array $payload, string $documentType = 'invoice'): void {
    $y = tcpdf_invoice_header($pdf, $payload, $documentType);
    $y += 10;
    $pdf->SetFont('times', 'B', 9.5);
    $layout = tcpdf_invoice_layout($pdf);
    $left = $layout['left'];
    $contentWidth = $layout['contentWidth'];
    $pdf->SetXY($left, $y);
    $pdf->Cell(40, 5, 'Add-on Charges', 0, 1, 'L');
    $y += 6;
    tcpdf_invoice_rule($pdf, $y);
    $y += 4;

    $baseWidths = [10, 46, 24, 10, 18, 24];
    $widthTotal = array_sum($baseWidths);
    $widths = array_map(static fn ($width): float => round(($width / $widthTotal) * $contentWidth, 2), $baseWidths);
    $headers = ['No.', 'Service Description', 'Service Date', 'Qty', 'Unit Price', 'Net Amount'];
    $aligns = ['C', 'L', 'C', 'C', 'R', 'R'];
    $x = $left;
    $pdf->SetFont('times', 'B', 9);
    foreach ($headers as $index => $header) {
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[$index], 5, $header, 0, 0, $aligns[$index]);
        $x += $widths[$index];
    }
    $y += 5;

    $addons = is_array($payload['booking']['addons'] ?? null) ? $payload['booking']['addons'] : [];
    if ($addons === []) {
        $addons[] = [
            'addonLabel' => 'No add-ons posted',
            'serviceName' => '',
            'serviceDateLabel' => '-',
            'quantity' => '-',
            'unitPriceValue' => 0,
            'totalPriceValue' => 0,
        ];
    }
    $pdf->SetFont('times', '', 9);
    foreach ($addons as $index => $addon) {
        $rowHeight = 8;
          $x = $left;
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[0], $rowHeight, (string) ($index + 1), 0, 0, 'C');
          $x += $widths[0];
          $pdf->SetXY($x, $y);
          $pdf->MultiCell($widths[1], 4, trim((string) ($addon['addonLabel'] ?? '-')), 0, 'L', false, 0);
        $x += $widths[1];
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($widths[2], 4, (string) ($addon['serviceDateLabel'] ?? ($addon['serviceDate'] ?? '-')), 0, 'C', false, 0);
        $x += $widths[2];
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[3], $rowHeight, (string) ($addon['quantity'] ?? 1), 0, 0, 'C');
        $x += $widths[3];
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[4], $rowHeight, money(as_float($addon['unitPriceValue'] ?? 0)), 0, 0, 'R');
        $x += $widths[4];
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[5], $rowHeight, money(as_float($addon['totalPriceValue'] ?? 0)), 0, 0, 'R');
        $y += $rowHeight;
        tcpdf_invoice_rule($pdf, $y);
        $y += 2;
    }

    $y += 2;
    tcpdf_invoice_rule($pdf, $y);
    $y += 3;
    $pdf->SetFont('times', 'B', 9.5);
      $labelWidth = 22;
      $valueWidth = 24;
      $summaryX = $left + $contentWidth - ($labelWidth + 4 + $valueWidth);
      $pdf->SetXY($summaryX, $y);
      $pdf->Cell($labelWidth, 5, 'Total Add-on', 0, 0, 'L');
      $pdf->Cell(4, 5, ':', 0, 0, 'C');
      $pdf->Cell($valueWidth, 5, money(as_float($payload['booking']['addonsTotalValue'] ?? 0)), 0, 0, 'R');
      $y += 8;
      tcpdf_invoice_rule($pdf, $y);
      $pdf->SetFont('times', '', 7.8);
    $pdf->SetXY($left, $y + 2);
    $pdf->Cell($contentWidth, 4, (string) ($payload['document']['addonFooterNote'] ?? 'Please review this invoice carefully. Payments are considered settled after confirmed receipt.'), 0, 1, 'C');
}
function tcpdf_invoice_folio_page(\TCPDF $pdf, array $payload): void {
    $y = tcpdf_invoice_header($pdf, $payload, 'folio');
    $y = tcpdf_invoice_customer_block($pdf, $payload, $y);
    tcpdf_invoice_rule($pdf, $y);
    $y += 3;

    $layout = tcpdf_invoice_layout($pdf);
    $left = $layout['left'];
    $contentWidth = $layout['contentWidth'];
    $baseWidths = [16, 22, 26, 26, 28];
    $widthTotal = array_sum($baseWidths);
    $widths = array_map(static fn ($width): float => round(($width / $widthTotal) * $contentWidth, 2), $baseWidths);
    $headers = ['Date', 'Type', 'Method', 'Reference', 'Amount'];
    $aligns = ['L', 'L', 'L', 'L', 'R'];
    $x = $left;
    $pdf->SetFont('times', 'B', 9);
    foreach ($headers as $index => $header) {
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[$index], 5, $header, 0, 0, $aligns[$index]);
        $x += $widths[$index];
    }
    $y += 5;

    $payments = is_array($payload['booking']['payments'] ?? null) ? $payload['booking']['payments'] : [];
    $pdf->SetFont('times', '', 8.8);
    if ($payments === []) {
        $pdf->SetXY($left, $y);
        $pdf->Cell($contentWidth, 6, 'No payment history has been posted for this folio.', 0, 1, 'L');
        $y += 6;
        tcpdf_invoice_rule($pdf, $y);
        $y += 3;
    } else {
        foreach ($payments as $payment) {
            $rowHeight = 6;
            $signedValue = as_float($payment['signedAmountValue'] ?? ($payment['amountValue'] ?? 0));
            $amountLabel = ($signedValue < 0 ? '- ' : '') . money(abs($signedValue));
            $x = $left;
            $pdf->SetXY($x, $y);
            $pdf->Cell($widths[0], $rowHeight, (string) ($payment['paymentDate'] ?? '-'), 0, 0, 'L');
            $x += $widths[0];
            $pdf->SetXY($x, $y);
            $pdf->Cell($widths[1], $rowHeight, (string) ($payment['transactionLabel'] ?? 'Payment'), 0, 0, 'L');
            $x += $widths[1];
            $pdf->SetXY($x, $y);
            $pdf->Cell($widths[2], $rowHeight, (string) ($payment['method'] ?? '-'), 0, 0, 'L');
            $x += $widths[2];
            $pdf->SetXY($x, $y);
            $pdf->Cell($widths[3], $rowHeight, (string) ($payment['referenceNo'] ?? '-'), 0, 0, 'L');
            $x += $widths[3];
            $pdf->SetXY($x, $y);
            $pdf->Cell($widths[4], $rowHeight, $amountLabel, 0, 0, 'R');
            $y += $rowHeight;
            tcpdf_invoice_rule($pdf, $y);
            $y += 1.5;
        }
    }

    $y += 2;
    $pdf->SetFont('times', 'B', 9.5);
    $labelWidth = 30;
    $valueWidth = 28;
    $summaryX = $left + $contentWidth - ($labelWidth + 4 + $valueWidth);
    $rows = [
        ['Paid', money(as_float($payload['booking']['paidAmountValue'] ?? 0))],
        ['Outstanding', money(as_float($payload['booking']['balanceValue'] ?? 0))],
    ];
    foreach ($rows as [$label, $value]) {
        $pdf->SetXY($summaryX, $y);
        $pdf->Cell($labelWidth, 5, $label, 0, 0, 'L');
        $pdf->Cell(4, 5, ':', 0, 0, 'C');
        $pdf->Cell($valueWidth, 5, $value, 0, 1, 'R');
        $y += 5;
    }
    tcpdf_invoice_rule($pdf, $y + 1);
    $pdf->SetFont('times', '', 7.8);
    $pdf->SetXY($left, $y + 3);
    $pdf->Cell($contentWidth, 4, (string) ($payload['document']['folioFooterNote'] ?? 'Guest folio includes settlement movements and is intended for operational reconciliation.'), 0, 1, 'C');
}
function invoice_print_html(PDO $db, string $bookingCode): string {

    $payload = invoice_pdf_payload($db, $bookingCode, invoice_print_request_overrides());
    $booking = $payload['booking'];
    $invoice = $payload['invoice'];
    $safe = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $propertyName = trim((string) hotel_setting_value($db, 'property_legal_name', 'Udara Hideaway Villa'));
    $propertyAddress = trim((string) hotel_setting_value($db, 'property_address', 'Jl. Udara Hideaway No. 8, Indonesia'));
    $propertyPhone = trim((string) hotel_setting_value($db, 'property_phone', '+62 000 0000 0000'));
    $propertyEmail = trim((string) hotel_setting_value($db, 'property_email', 'hello@udarahideawayvilla.com'));
    $checkIn = (string) ($booking['checkIn'] ?? '');
    $checkOut = (string) ($booking['checkOut'] ?? '');
    $nights = 1;
    if ($checkIn !== '' && $checkOut !== '') {
        $nights = max(1, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    }

    $roomRows = '';
    foreach (($booking['roomDetails'] ?? []) as $index => $room) {
        $roomRows .= '<tr>'
            . '<td class="center">' . ($index + 1) . '</td>'
            . '<td>' . $safe($room['room'] ?? '-') . '<br><span class="muted">' . $safe($room['roomType'] ?? '-') . '</span></td>'
            . '<td class="center">' . $safe(($room['adults'] ?? 0) . 'A / ' . ($room['children'] ?? 0) . 'C') . '</td>'
            . '<td class="right">' . money(as_float($room['rateValue'] ?? 0)) . '</td>'
            . '<td class="center">' . $nights . '</td>'
            . '<td class="right">' . money(as_float($room['lineTotalValue'] ?? 0)) . '</td>'
            . '</tr>';
    }

    if ($roomRows === '') {
        $roomRows = '<tr><td class="center">1</td><td>' . $safe($booking['room'] ?? '-') . '</td><td class="center">-</td><td class="right">' . money(as_float($booking['amountValue'] ?? 0)) . '</td><td class="center">' . $nights . '</td><td class="right">' . money(as_float($booking['amountValue'] ?? 0)) . '</td></tr>';
    }

    $addonRows = '';
    foreach (($booking['addons'] ?? []) as $index => $addon) {
        $addonRows .= '<tr>'
            . '<td class="center">' . ($index + 1) . '</td>'
            . '<td>' . $safe($addon['addonLabel'] ?? '-') . '<br><span class="muted">' . $safe($addon['serviceName'] ?? '-') . '</span></td>'
            . '<td class="center">' . $safe($addon['serviceDateLabel'] ?? ($addon['serviceDate'] ?? '-')) . '</td>'
            . '<td class="center">' . $safe($addon['quantity'] ?? 1) . '</td>'
            . '<td class="right">' . money(as_float($addon['unitPriceValue'] ?? 0)) . '</td>'
            . '<td class="right">' . money(as_float($addon['totalPriceValue'] ?? 0)) . '</td>'
            . '</tr>';
    }

    if ($addonRows === '') {
        $addonRows = '<tr><td class="center">-</td><td colspan="5" class="center muted">No add-ons posted</td></tr>';
    }

    $statusLabel = trim((string) ($booking['invoiceStatus'] ?? $invoice['status'] ?? 'Open'));
    $isPaidStamp = strtolower($statusLabel) === 'paid';
    $issuedDate = substr((string) ($invoice['issued_at'] ?? $invoice['created_at'] ?? today_date()), 0, 10);
    $dueDate = $checkIn !== '' ? $checkIn : $issuedDate;
    $documentTitle = $safe($payload['document']['invoiceTitle'] ?? 'INVOICE');
    $addonFooterNote = $safe($payload['document']['addonFooterNote'] ?? 'Please review this invoice carefully. Payments are considered settled after confirmed receipt.');
    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Invoice ' . $safe($bookingCode) . '</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 24px; background: #dfe3e8; font-family: "Times New Roman", Times, serif; color: #111; }
    .page { width: 148mm; min-height: 210mm; margin: 0 auto 18px; background: #fff; border: 1px solid #111; box-shadow: 0 8px 26px rgba(0,0,0,.14); padding: 10mm 9mm; position: relative; }
    .stamp { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
    .stamp span { transform: rotate(-28deg); border: 3px solid rgba(176,24,24,.18); color: rgba(176,24,24,.14); font-size: 34px; font-weight: 700; letter-spacing: 4px; padding: 6px 18px; }
    .content { position: relative; z-index: 1; }
    table { width: 100%; border-collapse: collapse; }
    .header { table-layout: fixed; margin-bottom: 10px; }
    .header td { vertical-align: top; }
    .title { text-align: center; font-size: 18px; font-weight: 700; letter-spacing: 1px; }
    .meta { font-size: 10.5px; border-left: 1px solid #111; padding-left: 8px; }
    .meta td { padding: 1px 0; }
    .meta .label { width: 38%; font-weight: 700; }
    .meta .colon { width: 4%; text-align: center; }
    .property { padding-right: 8px; }
    .property div { margin-bottom: 4px; font-size: 10.5px; }
    .property .name { font-weight: 700; }
    .customer { margin: 8px 0 10px; font-size: 10.5px; border-top: 1px solid #111; border-bottom: 1px solid #111; }
    .customer td { padding: 2px 0; vertical-align: top; }
    .rule { border-top: 1px dashed #666; margin: 8px 0; }
    .section { font-weight: 700; margin: 10px 0 4px; font-size: 11px; }
    .detail th, .detail td { padding: 4px 3px; font-size: 10px; vertical-align: top; }
    .detail thead th { border-top: 1px solid #111; border-bottom: 1px solid #111; text-align: left; }
    .detail tbody td { border-bottom: 1px dashed #999; }
    .muted { color: #555; font-size: 10px; }
    .summary { margin-top: 12px; font-size: 10.5px; border-top: 1px solid #111; }
    .summary td { padding: 4px 0; }
    .summary .label { width: 32%; font-weight: 700; }
    .summary .colon { width: 4%; text-align: center; }
    .summary .value { text-align: right; font-weight: 700; }
    .footer { margin-top: 14px; padding-top: 5px; border-top: 1px solid #111; font-size: 8.6px; text-align: center; color: #444; }
    @media print {
      body { background: #fff; padding: 0; }
      .page { width: auto; min-height: auto; margin: 0; box-shadow: none; page-break-after: always; }
      .page:last-child { page-break-after: auto; }
    }
  </style>
</head>
<body>
  <section class="page">
    ' . ($isPaidStamp ? '<div class="stamp"><span>PAID</span></div>' : '') . '
    <div class="content">
      <table class="header">
        <tr>
          <td style="width:42%;" class="property">
            <div class="name">' . $safe($propertyName) . '</div>
            <div>' . nl2br($safe($propertyAddress)) . '</div>
            <div>Phone: ' . $safe($propertyPhone) . '</div>
            <div>Email: ' . $safe($propertyEmail) . '</div>
          </td>
          <td style="width:28%;" class="title">' . $documentTitle . '</td>
          <td style="width:30%;">
            <table class="meta">
              <tr><td class="label">Number</td><td class="colon">:</td><td><strong>' . $safe($invoice['invoice_number'] ?? $bookingCode) . '</strong></td></tr>
              <tr><td class="label">Inv. Date</td><td class="colon">:</td><td><strong>' . $safe($issuedDate) . '</strong></td></tr>
              <tr><td class="label">Due Date</td><td class="colon">:</td><td><strong>' . $safe($dueDate) . '</strong></td></tr>
              <tr><td class="label">Booking Ref</td><td class="colon">:</td><td><strong>' . $safe($booking['code'] ?? $bookingCode) . '</strong></td></tr>
              <tr><td class="label">Currency</td><td class="colon">:</td><td><strong>IDR</strong></td></tr>
              <tr><td class="label">Status</td><td class="colon">:</td><td><strong>' . $safe($statusLabel) . '</strong></td></tr>
            </table>
          </td>
        </tr>
      </table>
      <table class="customer">
        <tr><td style="width:18%;"><strong>Customer</strong></td><td style="width:4%;">:</td><td>' . $safe($booking['guest'] ?? '-') . '</td></tr>
        <tr><td><strong>Contact</strong></td><td>:</td><td>' . $safe(($booking['guestPhone'] ?? '-') . ' / ' . ($booking['guestEmail'] ?? '-')) . '</td></tr>
        <tr><td><strong>Stay</strong></td><td>:</td><td>' . $safe($checkIn) . ' to ' . $safe($checkOut) . ' (' . $nights . ' night(s))</td></tr>
      </table>
      <div class="rule"></div>
      <table class="detail">
        <thead>
          <tr>
            <th style="width:7%; text-align:center;">No.</th>
            <th style="width:35%;">Room Description</th>
            <th style="width:14%; text-align:center;">Pax</th>
            <th style="width:16%; text-align:right;">Unit Price</th>
            <th style="width:10%; text-align:center;">Night</th>
            <th style="width:18%; text-align:right;">Net Amount</th>
          </tr>
        </thead>
        <tbody>' . $roomRows . '</tbody>
      </table>
      <table class="summary">
        <tr><td class="label">Total Kamar Hotel</td><td class="colon">:</td><td class="value">' . money(as_float($booking['amountValue'] ?? 0)) . '</td></tr>
      </table>
    </div>
  </section>
  <section class="page">
    ' . ($isPaidStamp ? '<div class="stamp"><span>PAID</span></div>' : '') . '
    <div class="content">
      <table class="header">
        <tr>
          <td style="width:42%;" class="property">
            <div class="name">' . $safe($propertyName) . '</div>
            <div>' . nl2br($safe($propertyAddress)) . '</div>
            <div>Phone: ' . $safe($propertyPhone) . '</div>
            <div>Email: ' . $safe($propertyEmail) . '</div>
          </td>
          <td style="width:28%;" class="title">' . $documentTitle . '</td>
          <td style="width:30%;">
            <table class="meta">
              <tr><td class="label">Number</td><td class="colon">:</td><td><strong>' . $safe($invoice['invoice_number'] ?? $bookingCode) . '</strong></td></tr>
              <tr><td class="label">Inv. Date</td><td class="colon">:</td><td><strong>' . $safe($issuedDate) . '</strong></td></tr>
              <tr><td class="label">Due Date</td><td class="colon">:</td><td><strong>' . $safe($dueDate) . '</strong></td></tr>
              <tr><td class="label">Booking Ref</td><td class="colon">:</td><td><strong>' . $safe($booking['code'] ?? $bookingCode) . '</strong></td></tr>
              <tr><td class="label">Currency</td><td class="colon">:</td><td><strong>IDR</strong></td></tr>
              <tr><td class="label">Status</td><td class="colon">:</td><td><strong>' . $safe($statusLabel) . '</strong></td></tr>
            </table>
          </td>
        </tr>
      </table>
      <div class="section">Add-on Charges</div>
      <table class="detail">
        <thead>
          <tr>
            <th style="width:7%; text-align:center;">No.</th>
            <th style="width:39%;">Service Description</th>
            <th style="width:16%; text-align:center;">Service Date</th>
            <th style="width:10%; text-align:center;">Qty</th>
            <th style="width:13%; text-align:right;">Unit Price</th>
            <th style="width:15%; text-align:right;">Net Amount</th>
          </tr>
        </thead>
        <tbody>' . $addonRows . '</tbody>
      </table>
      <table class="summary">
        <tr><td class="label">Total Add-on</td><td class="colon">:</td><td class="value">' . money(as_float($booking['addonsTotalValue'] ?? 0)) . '</td></tr>
      </table>
      <div class="footer">' . $addonFooterNote . '</div>
    </div>
  </section>
</body>
</html>';
}
function respond_invoice_print_html(PDO $db, string $bookingCode): never {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo invoice_print_html($db, $bookingCode);
    exit;
}
function respond_invoice_pdf(PDO $db, string $bookingCode): never {
    if (!class_exists(\TCPDF::class)) fail('Library TCPDF belum tersedia di server.', 500);
    $inline = in_array((string) ($_GET['inline'] ?? '0'), ['1', 'true', 'yes'], true);
    $payload = invoice_pdf_payload($db, $bookingCode, invoice_print_request_overrides());
    $documentType = strtolower(trim((string) ($_GET['document'] ?? 'invoice')));
    if (!in_array($documentType, ['invoice', 'folio'], true)) {
        $documentType = 'invoice';
    }
    $statusLabel = trim((string) ($payload['booking']['invoiceStatus'] ?? $payload['invoice']['status'] ?? 'Open'));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $size = strtoupper(trim((string) ($_GET['size'] ?? 'HALF-A4')));
    if ($size === 'A4') {
        $orientation = 'P';
        $pageFormat = 'A4';
    } else {
        $orientation = 'L';
        $pageFormat = [210, 148.5];
    }

    $pdf = new \TCPDF($orientation, 'mm', $pageFormat, true, 'UTF-8', false);
    $pdf->SetCreator('HOTEL-BOOK');
    $pdf->SetAuthor('Udara Hideaway Villa');
    $pdf->SetTitle(($documentType === 'folio' ? 'Folio ' : 'Invoice ') . $bookingCode);
    $pdf->SetSubject($documentType === 'folio' ? 'Guest Folio' : 'Booking Invoice');
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetMargins(10, 10, 10, true);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(5);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->SetCellPadding(0);
    $pdf->setImageScale(1.25);
    $pdf->SetFont('times', '', 9);
    $pages = $documentType === 'folio' ? ['room', 'addon', 'folio'] : ['room', 'addon'];
    foreach ($pages as $pageKey) {
        $pdf->AddPage($orientation, $pageFormat);
        tcpdf_invoice_apply_stamp($pdf, strtolower($statusLabel) === 'paid');
        if ($pageKey === 'room') {
            tcpdf_invoice_room_page($pdf, $payload, $documentType);
        } elseif ($pageKey === 'addon') {
            tcpdf_invoice_addon_page($pdf, $payload, $documentType);
        } else {
            tcpdf_invoice_folio_page($pdf, $payload);
        }
    }
    $pdf->Output($documentType . '-' . $bookingCode . '.pdf', $inline ? 'I' : 'D');
    exit;
}
function upsert_generic_journal(PDO $db, string $source, string $referenceType, int $referenceId, string $journalDate, string $description, array $lines): void {
    $journal = db_one($db, "SELECT * FROM journals WHERE source = ? AND reference_type = ? AND reference_id = ? LIMIT 1", [$source, $referenceType, $referenceId]);
    if ($journal) { $journalId = as_int($journal['id']); $db->prepare("UPDATE journals SET journal_date = ?, description = ?, updated_at = ? WHERE id = ?")->execute([$journalDate, $description, now_ts(), $journalId]); $db->prepare("DELETE FROM journal_lines WHERE journal_id = ?")->execute([$journalId]); }
    else { $db->prepare("INSERT INTO journals (journal_number, journal_date, reference_type, reference_id, description, source, posted_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)")->execute([generate_journal_number($db, $journalDate), $journalDate, $referenceType, $referenceId, $description, $source, now_ts(), now_ts()]); $journalId = (int) $db->lastInsertId(); }
    $insert = $db->prepare("INSERT INTO journal_lines (journal_id, coa_code, line_description, debit, credit, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)"); $now = now_ts();
    foreach ($lines as $line) { $debit = round(as_float($line['debit'] ?? 0), 2); $credit = round(as_float($line['credit'] ?? 0), 2); $coaCode = trim((string) ($line['coa_code'] ?? '')); if ($coaCode === '' || ($debit <= 0 && $credit <= 0)) continue; $insert->execute([$journalId, $coaCode, trim((string) ($line['memo'] ?? '')) ?: null, $debit, $credit, $now, $now]); }
}
function inventory_item_snapshot(PDO $db, int $itemId): ?array {
    $item = db_one($db, "SELECT * FROM inventory_items WHERE id = ? LIMIT 1", [$itemId]); if (!$item) return null;
    $purchased = as_float(db_value($db, "SELECT COALESCE(SUM(qty_in),0) FROM inventory_movements WHERE item_id = ?", [$itemId])); $issued = as_float(db_value($db, "SELECT COALESCE(SUM(qty_out),0) FROM inventory_movements WHERE item_id = ?", [$itemId]));
    return ['id' => as_int($item['id']), 'name' => $item['item_name'], 'category' => $item['category'], 'trackingType' => strtolower((string) $item['category']) === 'linen' ? 'Linen' : 'Consumable', 'unit' => $item['unit'], 'onHandQty' => max(0, $purchased - $issued), 'inventoryCoa' => coa_code_only((string) ($item['inventory_coa_code'] ?? '')), 'expenseCoa' => coa_code_only((string) ($item['expense_coa_code'] ?? '')), 'latestCostValue' => as_float($item['standard_cost'])];
}
function ensure_master_units_seed(PDO $db): void {
    $count = (int) db_value($db, "SELECT COUNT(*) FROM master_units");
    if ($count > 0) return;
    $rows = db_all($db, "SELECT DISTINCT unit FROM inventory_items WHERE unit IS NOT NULL AND unit <> '' ORDER BY unit ASC");
    $insert = $db->prepare("INSERT IGNORE INTO master_units (name, is_active, created_at, updated_at) VALUES (?, 1, ?, ?)");
    foreach ($rows as $row) {
        $name = trim((string) ($row['unit'] ?? ''));
        if ($name === '') continue;
        $insert->execute([$name, now_ts(), now_ts()]);
    }
}
function inventory_post_purchase_accounting(PDO $db, int $movementId): void {
    $movement = db_one($db, "SELECT * FROM inventory_movements WHERE id = ? LIMIT 1", [$movementId]); if (!$movement) return; $item = inventory_item_snapshot($db, as_int($movement['item_id'])); if (!$item || $item['inventoryCoa'] === '') return;
    $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $paymentAccount = coa_code_only((string) ($meta['paymentAccount'] ?? '')); if ($paymentAccount === '') return;
    $amount = as_float($movement['qty_in']) * as_float($movement['unit_cost']); if ($amount <= 0) return;
    upsert_generic_journal($db, 'inventory_purchase', 'inventory_purchase', as_int($movement['id']), substr((string) $movement['movement_date'], 0, 10), "Pembelian inventory {$item['name']}", [['coa_code' => $item['inventoryCoa'], 'debit' => $amount, 'credit' => 0, 'memo' => "Pembelian {$item['name']}"], ['coa_code' => $paymentAccount, 'debit' => 0, 'credit' => $amount, 'memo' => "Pembayaran pembelian {$item['name']}"]]);
}
function inventory_post_purchase_return_accounting(PDO $db, int $movementId): void {
    $movement = db_one($db, "SELECT * FROM inventory_movements WHERE id = ? LIMIT 1", [$movementId]); if (!$movement) return; $item = inventory_item_snapshot($db, as_int($movement['item_id'])); if (!$item || $item['inventoryCoa'] === '') return;
    $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $paymentAccount = coa_code_only((string) ($meta['paymentAccount'] ?? '')); if ($paymentAccount === '') return;
    $amount = as_float($movement['qty_out']) * as_float($movement['unit_cost']); if ($amount <= 0) return;
    upsert_generic_journal($db, 'inventory_purchase', 'inventory_purchase_return', as_int($movement['id']), substr((string) $movement['movement_date'], 0, 10), "Retur pembelian inventory {$item['name']}", [['coa_code' => $paymentAccount, 'debit' => $amount, 'credit' => 0, 'memo' => "Penerimaan retur pembelian {$item['name']}"], ['coa_code' => $item['inventoryCoa'], 'debit' => 0, 'credit' => $amount, 'memo' => "Pengurangan persediaan retur {$item['name']}"]]);
}
function inventory_post_issue_accounting(PDO $db, int $movementId): void {
    $movement = db_one($db, "SELECT * FROM inventory_movements WHERE id = ? LIMIT 1", [$movementId]); if (!$movement) return; $item = inventory_item_snapshot($db, as_int($movement['item_id'])); if (!$item || $item['trackingType'] !== 'Consumable' || $item['inventoryCoa'] === '' || $item['expenseCoa'] === '') return;
    $amount = as_float($movement['qty_out']) * as_float($movement['unit_cost']); if ($amount <= 0) return;
    upsert_generic_journal($db, 'inventory_issue', 'inventory_issue', as_int($movement['id']), substr((string) $movement['movement_date'], 0, 10), "Issue inventory {$item['name']} ke kamar", [['coa_code' => $item['expenseCoa'], 'debit' => $amount, 'credit' => 0, 'memo' => "Biaya pemakaian {$item['name']}"], ['coa_code' => $item['inventoryCoa'], 'debit' => 0, 'credit' => $amount, 'memo' => "Pengurangan persediaan {$item['name']}"]]);
}
function inventory_post_issue_return_accounting(PDO $db, int $movementId): void {
    $movement = db_one($db, "SELECT * FROM inventory_movements WHERE id = ? LIMIT 1", [$movementId]); if (!$movement) return; $item = inventory_item_snapshot($db, as_int($movement['item_id'])); if (!$item || $item['trackingType'] !== 'Consumable' || $item['inventoryCoa'] === '' || $item['expenseCoa'] === '') return;
    $amount = as_float($movement['qty_in']) * as_float($movement['unit_cost']); if ($amount <= 0) return;
    upsert_generic_journal($db, 'inventory_issue', 'inventory_issue_return', as_int($movement['id']), substr((string) $movement['movement_date'], 0, 10), "Pengembalian inventory {$item['name']} dari kamar", [['coa_code' => $item['inventoryCoa'], 'debit' => $amount, 'credit' => 0, 'memo' => "Persediaan kembali {$item['name']}"], ['coa_code' => $item['expenseCoa'], 'debit' => 0, 'credit' => $amount, 'memo' => "Pembalik biaya {$item['name']}"]]);
}
function sync_inventory_accounting(PDO $db): array {
    $purchaseIds = db_all($db, "SELECT id FROM inventory_movements WHERE movement_type = 'purchase' ORDER BY id ASC");
    foreach ($purchaseIds as $row) inventory_post_purchase_accounting($db, as_int($row['id']));
    $purchaseReturnIds = db_all($db, "SELECT id FROM inventory_movements WHERE movement_type = 'return' AND reference_type = 'purchase_cancel' ORDER BY id ASC");
    foreach ($purchaseReturnIds as $row) inventory_post_purchase_return_accounting($db, as_int($row['id']));
    $issueIds = db_all($db, "SELECT id FROM inventory_movements WHERE movement_type = 'issue_room' ORDER BY id ASC");
    foreach ($issueIds as $row) inventory_post_issue_accounting($db, as_int($row['id']));
    $issueReturnIds = db_all($db, "SELECT id FROM inventory_movements WHERE movement_type = 'return' AND reference_type = 'room_return' ORDER BY id ASC");
    foreach ($issueReturnIds as $row) inventory_post_issue_return_accounting($db, as_int($row['id']));
    return ['purchases_synced' => count($purchaseIds), 'purchase_returns_synced' => count($purchaseReturnIds), 'issues_synced' => count($issueIds), 'issue_returns_synced' => count($issueReturnIds)];
}
function booking_room_codes(PDO $db, int $bookingId): array {
    $rows = db_all($db, "SELECT r.room_code FROM booking_rooms br INNER JOIN rooms r ON r.id = br.room_id WHERE br.booking_id = ? ORDER BY r.room_code ASC", [$bookingId]);
    return array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['room_code'] ?? '')), $rows)));
}
function booking_checkout_inventory_rows(PDO $db, int $bookingId): array {
    $roomCodes = booking_room_codes($db, $bookingId);
    if ($roomCodes === []) return [];
    $placeholders = implode(', ', array_fill(0, count($roomCodes), '?'));
    $issueMovements = db_all($db, "SELECT * FROM inventory_movements WHERE movement_type = 'issue_room' AND qty_out > 0 AND reference_id IN ({$placeholders}) ORDER BY movement_date ASC, id ASC", $roomCodes);
    if ($issueMovements === []) return [];
    $returnRows = db_all($db, "SELECT * FROM inventory_movements WHERE movement_type = 'return' AND reference_type = 'room_return' ORDER BY movement_date ASC, id ASC");
    $returnMap = [];
    foreach ($returnRows as $movement) {
        $meta = json_decode((string) ($movement['notes'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $issueId = as_int($meta['issueMovementId'] ?? 0);
        if ($issueId > 0) $returnMap[$issueId] = ($returnMap[$issueId] ?? 0.0) + as_float($movement['qty_in']);
    }
    $grouped = [];
    foreach ($issueMovements as $movement) {
        $item = inventory_item_snapshot($db, as_int($movement['item_id']));
        if (!$item || (string) ($item['trackingType'] ?? '') !== 'Consumable') continue;
        $remainingQty = max(0, as_float($movement['qty_out']) - as_float($returnMap[as_int($movement['id'])] ?? 0));
        if ($remainingQty <= 0) continue;
        $roomNo = trim((string) ($movement['reference_id'] ?? ''));
        $itemId = as_int($movement['item_id']);
        $key = $roomNo . ':' . $itemId;
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'id' => $key,
                'roomNo' => $roomNo,
                'itemId' => $itemId,
                'itemName' => (string) ($item['name'] ?? 'Unknown item'),
                'unit' => (string) ($item['unit'] ?? 'pcs'),
                'totalIssuedQty' => 0,
                'outstandingQty' => 0,
                'lastIssueQty' => 0,
                'lastIssueDate' => '',
                'latestUnitCost' => 0,
                'movements' => [],
            ];
        }
        $grouped[$key]['totalIssuedQty'] += as_float($movement['qty_out']);
        $grouped[$key]['outstandingQty'] += $remainingQty;
        $movementDate = substr((string) ($movement['movement_date'] ?? ''), 0, 10);
        if (
            $grouped[$key]['lastIssueDate'] === ''
            || $movementDate > $grouped[$key]['lastIssueDate']
            || ($movementDate === $grouped[$key]['lastIssueDate'] && as_int($movement['id']) > as_int($grouped[$key]['lastIssueId'] ?? 0))
        ) {
            $grouped[$key]['lastIssueQty'] = as_int($movement['qty_out']);
            $grouped[$key]['lastIssueDate'] = $movementDate;
            $grouped[$key]['latestUnitCost'] = as_float($movement['unit_cost']);
            $grouped[$key]['lastIssueId'] = as_int($movement['id']);
        }
        $grouped[$key]['movements'][] = [
            'issueId' => as_int($movement['id']),
            'remainingQty' => $remainingQty,
            'unitCost' => as_float($movement['unit_cost']),
        ];
    }
    $rows = array_values(array_map(static function (array $row): array {
        unset($row['lastIssueId']);
        $row['totalIssuedQty'] = as_int($row['totalIssuedQty']);
        $row['outstandingQty'] = as_int($row['outstandingQty']);
        return $row;
    }, $grouped));
    usort($rows, static fn (array $a, array $b): int => [$a['roomNo'], $a['itemName']] <=> [$b['roomNo'], $b['itemName']]);
    return $rows;
}
function process_booking_checkout_inventory(PDO $db, array $booking, array $usageEntries): void {
    $checkoutRows = booking_checkout_inventory_rows($db, as_int($booking['id']));
    if ($checkoutRows === []) return;
    $usageMap = [];
    foreach ($usageEntries as $entry) {
        if (!is_array($entry)) continue;
        $roomNo = trim((string) ($entry['roomNo'] ?? ''));
        $itemId = as_int($entry['itemId'] ?? 0);
        if ($roomNo === '' || $itemId <= 0) continue;
        $usageMap[$roomNo . ':' . $itemId] = max(0, as_int($entry['usedQty'] ?? 0));
    }
    $businessDate = current_business_date($db);
    foreach ($checkoutRows as $row) {
        $key = (string) $row['id'];
        $usedQty = max(0, min(as_int($usageMap[$key] ?? 0), as_int($row['outstandingQty'])));
        $returnQty = as_int($row['outstandingQty']) - $usedQty;
        if ($returnQty <= 0) continue;
        $remainingToReturn = $returnQty;
        foreach ($row['movements'] as $movement) {
            if ($remainingToReturn <= 0) break;
            $movementReturnQty = min($remainingToReturn, as_int($movement['remainingQty']));
            if ($movementReturnQty <= 0) continue;
            $db->prepare("INSERT INTO inventory_movements (item_id, movement_date, movement_type, qty_in, qty_out, unit_cost, reference_type, reference_id, notes, created_at, updated_at) VALUES (?, ?, 'return', ?, 0, ?, 'room_return', ?, ?, ?, ?)")
                ->execute([
                    as_int($row['itemId']),
                    $businessDate . ' 00:00:00',
                    $movementReturnQty,
                    as_float($movement['unitCost']),
                    $row['roomNo'],
                    json_encode([
                        'issueMovementId' => as_int($movement['issueId']),
                        'bookingCode' => (string) ($booking['booking_code'] ?? ''),
                        'note' => 'Auto return on check-out',
                    ], JSON_UNESCAPED_UNICODE),
                    now_ts(),
                    now_ts(),
                ]);
            inventory_post_issue_return_accounting($db, (int) $db->lastInsertId());
            $remainingToReturn -= $movementReturnQty;
        }
    }
}
function report_coa_balances(PDO $db): array {
    $coas = db_all($db, "SELECT code, account_name, category, normal_balance FROM coa_accounts ORDER BY code ASC");
    $balanceRows = db_all($db, "SELECT coa_code, COALESCE(SUM(debit),0) AS total_debit, COALESCE(SUM(credit),0) AS total_credit FROM journal_lines GROUP BY coa_code"); $lookup = [];
    foreach ($balanceRows as $row) $lookup[(string) $row['coa_code']] = ['debit' => as_float($row['total_debit']), 'credit' => as_float($row['total_credit'])];
    $result = [];
    foreach ($coas as $coa) { $entry = $lookup[$coa['code']] ?? ['debit' => 0.0, 'credit' => 0.0]; $normal = strtolower((string) $coa['normal_balance']); $balance = $normal === 'credit' ? $entry['credit'] - $entry['debit'] : $entry['debit'] - $entry['credit']; $result[] = ['code' => $coa['code'], 'name' => $coa['account_name'], 'category' => ucfirst((string) $coa['category']), 'normal_balance' => ucfirst((string) $coa['normal_balance']), 'debit' => $entry['debit'], 'credit' => $entry['credit'], 'balance' => $balance]; }
    return $result;
}
function dashboard_period_range(PDO $db, string $period, ?string $startDate = null, ?string $endDate = null): array {
    $businessDate = current_business_date($db);
    $normalized = strtolower(trim($period));
    return match ($normalized) {
        'week' => [
            'from' => date('Y-m-d', strtotime('monday this week', strtotime($businessDate))),
            'to' => date('Y-m-d', strtotime('sunday this week', strtotime($businessDate))),
            'label' => 'This week',
        ],
        'month' => [
            'from' => date('Y-m-01', strtotime($businessDate)),
            'to' => date('Y-m-t', strtotime($businessDate)),
            'label' => 'This month',
        ],
        'custom' => [
            'from' => $startDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : $businessDate,
            'to' => $endDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : ($startDate ?: $businessDate),
            'label' => 'Custom',
        ],
        default => [
            'from' => $businessDate,
            'to' => $businessDate,
            'label' => 'Today',
        ],
    };
}
function booking_addon_fee_total_in_range(PDO $db, string $fromDate, string $toDate): float {
    $rows = db_all($db, "
        SELECT ba.*
        FROM booking_addons ba
        INNER JOIN bookings b ON b.id = ba.booking_id
        WHERE b.status NOT IN ('cancelled', 'no_show')
          AND ba.status != 'cancelled'
          AND DATE(b.check_in_at) BETWEEN ? AND ?
    ", [$fromDate, $toDate]);
    $total = 0.0;
    foreach ($rows as $row) $total += booking_addon_fee_amount($db, $row);
    return round($total, 2);
}

$baseDir = __DIR__; $env = env_map($baseDir . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . '.env');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? 'hotel');
try { $db = new PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"]); }
catch (Throwable $e) { fail('Gagal terhubung ke database MySQL.', 500); }
$secret = env_secret($env); $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')); $path = route_path(); $actor = null;
if (!($method === 'POST' && $path === 'login')) ensure_runtime_schema($db);
$permissionRoutes = [
    ['GET', 'coa-accounts', 'coa'], ['POST', 'coa-accounts', 'coa'], ['PUT', 'coa-accounts/{code}', 'coa'], ['GET', 'room-types', 'rooms'], ['GET', 'rooms', 'rooms'], ['POST', 'rooms', 'rooms'], ['PUT', 'rooms/{code}', 'rooms'], ['GET', 'housekeeping/queue', 'rooms'], ['PATCH', 'housekeeping/tasks/{id}', 'rooms'],
    ['GET', 'bookings', 'bookings'], ['GET', 'bookings/{code}', 'bookings'], ['GET', 'bookings/{code}/checkout-inventory', 'bookings'], ['GET', 'bookings/{code}/invoice-pdf', 'bookings'], ['GET', 'bookings/{code}/invoice-print', 'bookings'], ['POST', 'bookings', 'bookings'], ['PUT', 'bookings/{code}', 'bookings'], ['PATCH', 'bookings/{code}/status', 'bookings'], ['POST', 'bookings/{code}/addons', 'bookings'], ['PATCH', 'bookings/{code}/addons/{id}', 'bookings'], ['DELETE', 'bookings/{code}/addons/{id}', 'bookings'],
    ['GET', 'journals', 'journals'], ['POST', 'journals', 'journals'], ['PUT', 'journals/{id}', 'journals'], ['GET', 'payments', 'finance'], ['GET', 'finance/invoices/{code}/pdf', 'finance'], ['GET', 'finance/invoices/{code}/print', 'finance'], ['POST', 'payments', 'finance'], ['POST', 'payments/{id}/refund', 'finance'], ['POST', 'payments/{id}/void', 'finance'], ['GET', 'inventory', 'inventory'], ['POST', 'inventory/items', 'inventory'], ['PUT', 'inventory/items/{id}', 'inventory'], ['GET', 'master-units', 'inventory'], ['POST', 'master-units', 'inventory'], ['PUT', 'master-units/{id}', 'inventory'], ['DELETE', 'master-units/{id}', 'inventory'], ['POST', 'inventory/purchases', 'inventory'], ['POST', 'inventory/purchases/{id}/cancel', 'inventory'], ['POST', 'inventory/issues', 'inventory'], ['POST', 'inventory/issues/{id}/return', 'inventory'],
    ['GET', 'vendors', 'activities'], ['POST', 'vendors', 'activities'], ['PUT', 'vendors/{id}', 'activities'], ['GET', 'vendor-bills', 'activities'], ['POST', 'vendor-bills', 'activities'], ['GET', 'vendor-payments', 'activities'], ['POST', 'vendor-payments', 'activities'],
    ['GET', 'transport-rates', 'transport'], ['POST', 'transport-rates', 'transport'], ['PUT', 'transport-rates/{id}', 'transport'], ['GET', 'activity-catalog', 'activities'], ['POST', 'activity-catalog/scooters', 'activities'], ['PUT', 'activity-catalog/scooters/{id}', 'activities'], ['PATCH', 'activity-catalog/scooters/{id}/toggle', 'activities'], ['POST', 'activity-catalog/operators', 'activities'], ['PUT', 'activity-catalog/operators/{id}', 'activities'], ['PATCH', 'activity-catalog/operators/{id}/toggle', 'activities'], ['POST', 'activity-catalog/island-tours', 'activities'], ['PUT', 'activity-catalog/island-tours/{id}', 'activities'], ['PATCH', 'activity-catalog/island-tours/{id}/toggle', 'activities'], ['POST', 'activity-catalog/boat-tickets', 'activities'], ['PUT', 'activity-catalog/boat-tickets/{id}', 'activities'], ['PATCH', 'activity-catalog/boat-tickets/{id}/toggle', 'activities'],
    ['GET', 'reports/balance-sheet', 'reports'], ['GET', 'reports/profit-loss', 'reports'], ['GET', 'reports/cash-flow', 'reports'], ['GET', 'reports/general-ledger', 'reports'], ['GET', 'reports/reconciliation', 'reports'], ['GET', 'reports/vendor-payables', 'activities'], ['GET', 'audit-trails', 'reports'], ['GET', 'dashboard/owner', 'dashboard'], ['PUT', 'dashboard/owner/policies', 'dashboard'], ['GET', 'night-audit/status', 'dashboard'], ['GET', 'night-audit/history', 'dashboard'], ['GET', 'settings/policies', 'settings'], ['PUT', 'settings/policies', 'settings'], ['POST', 'settings/reset-transactions', 'settings'], ['POST', 'night-audit', 'roles'], ['POST', 'accounting/sync-history', 'roles'],
    ['GET', 'roles', 'roles'], ['PUT', 'roles/{id}/permissions', 'roles'], ['GET', 'users', 'users'], ['POST', 'users', 'users'], ['PUT', 'users/{id}', 'users'], ['PATCH', 'users/{id}/toggle', 'users'],
];
if (!($method === 'POST' && $path === 'login')) {
    $actor = resolve_auth_user($secret); if (!$actor) fail('Sesi login tidak valid atau telah berakhir.', 401);
    foreach ($permissionRoutes as [$rm, $rp, $perm]) if ($rm === $method && match_route($rp, $path) !== null && !can_access($actor, $perm)) fail('Anda tidak memiliki akses ke modul ini.', 403);
}
try {
    if ($method === 'POST' && $path === 'login') {
        $payload = json_input(); require_fields($payload, ['username', 'password']);
        $credential = trim((string) $payload['username']);
        $loginCandidates = db_all($db, "SELECT id, role_id, name, username, email, password, is_active FROM users");
        $userRecord = null;
        foreach ($loginCandidates as $candidate) {
            if ((string) ($candidate['username'] ?? '') === $credential || (string) ($candidate['email'] ?? '') === $credential) {
                $userRecord = $candidate;
                break;
            }
        }
        if ($userRecord && password_verify((string) $payload['password'], (string) $userRecord['password'])) {
            if (!as_int($userRecord['is_active'])) fail('Akun Anda dinonaktifkan.', 403);
            $roleRecord = null;
            if (!empty($userRecord['role_id'])) {
                $roleRecord = db_one($db, "SELECT name, permissions FROM roles WHERE id = ?", [as_int($userRecord['role_id'])]);
            }
            $user = [
                'id' => as_int($userRecord['id']),
                'name' => $userRecord['name'],
                'username' => $userRecord['username'],
                'email' => $userRecord['email'],
                'role' => $roleRecord['name'] ?? 'frontdesk',
                'permissions' => !empty($roleRecord['permissions']) ? (json_decode((string) $roleRecord['permissions'], true) ?: []) : [],
            ];
            audit_log($db, ['module' => 'auth', 'action' => 'login', 'entity_type' => 'user', 'entity_id' => $user['id'], 'entity_label' => $user['username'] ?: $user['email'], 'description' => "User {$user['username']} berhasil login.", 'metadata' => ['permissions' => $user['permissions'], 'source' => 'database']], $user);
            respond(['token' => issue_token($user, $secret), 'user' => $user]);
        }
        $demo = ['admin' => ['General Manager (Demo)', 'admin@sagarabay.com', 'admin123', 'admin', ['dashboard', 'bookings', 'rooms', 'finance', 'journals', 'coa', 'inventory', 'transport', 'activities', 'reports', 'users', 'roles'], 991], 'fo' => ['Resepsionis (Demo)', 'fo@sagarabay.com', 'fo123', 'frontdesk', ['dashboard', 'bookings', 'rooms', 'activities'], 992], 'hk' => ['Housekeeping (Demo)', 'hk@sagarabay.com', 'hk123', 'housekeeping', ['rooms', 'inventory'], 993]];
        if (isset($demo[$credential]) && $demo[$credential][2] === (string) $payload['password']) { [$name, $email, , $role, $permissions, $id] = $demo[$credential]; $user = compact('id', 'name', 'email', 'role', 'permissions'); $user['username'] = $credential; audit_log($db, ['module' => 'auth', 'action' => 'login', 'entity_type' => 'user', 'entity_id' => $id, 'entity_label' => $credential, 'description' => "User demo {$credential} berhasil login.", 'metadata' => ['permissions' => $permissions, 'source' => 'demo']], $user); respond(['token' => issue_token($user, $secret), 'user' => $user]); }
        fail('Username atau Password salah.', 401);
    }

    if ($method === 'GET' && $path === 'coa-accounts') {
        $page = max(1, as_int(q('page', 1))); $perPage = min(max(as_int(q('per_page', 15)), 1), 100); $search = trim((string) q('search', '')); $category = strtolower(trim((string) q('category', '')));
        $where = []; $params = [];
        if ($search !== '') { $like = '%' . $search . '%'; $where[] = "(code LIKE ? OR account_name LIKE ? OR note LIKE ?)"; array_push($params, $like, $like, $like); }
        if ($category !== '') { $where[] = "category = ?"; $params[] = $category; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : ''; $total = (int) db_value($db, "SELECT COUNT(*) FROM coa_accounts {$whereSql}", $params);
        $rows = db_all($db, "SELECT * FROM coa_accounts {$whereSql} ORDER BY code ASC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params);
        respond(['data' => array_map(static fn (array $row): array => ['code' => $row['code'], 'name' => $row['account_name'], 'category' => ucfirst((string) $row['category']), 'normalBalance' => ucfirst((string) $row['normal_balance']), 'note' => $row['note'] ?? '', 'active' => (bool) as_int($row['is_active'])], $rows), 'meta' => paginate_meta($page, $perPage, $total)]);
    }
    if ($method === 'POST' && $path === 'coa-accounts') {
        $payload = json_input(); require_fields($payload, ['code', 'name', 'category', 'normalBalance']); $code = trim((string) $payload['code']);
        if (db_one($db, "SELECT code FROM coa_accounts WHERE code = ?", [$code])) fail('Kode COA sudah ada.', 422, ['code' => ['Kode COA sudah ada.']]);
        $db->prepare("INSERT INTO coa_accounts (code, account_name, category, normal_balance, note, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$code, trim((string) $payload['name']), strtolower(trim((string) $payload['category'])), strtolower(trim((string) $payload['normalBalance'])), trim((string) ($payload['note'] ?? '')) ?: null, !isset($payload['active']) || $payload['active'] ? 1 : 0, now_ts(), now_ts()]);
        respond(['data' => ['code' => $code, 'name' => trim((string) $payload['name']), 'category' => ucfirst(strtolower(trim((string) $payload['category']))), 'normalBalance' => ucfirst(strtolower(trim((string) $payload['normalBalance']))), 'note' => trim((string) ($payload['note'] ?? '')), 'active' => !isset($payload['active']) || (bool) $payload['active']], 'message' => 'COA berhasil ditambahkan.'], 201);
    }
    if ($method === 'PUT' && ($params = match_route('coa-accounts/{code}', $path)) !== null) {
        $payload = json_input(); require_fields($payload, ['name', 'category', 'normalBalance']);
        $db->prepare("UPDATE coa_accounts SET account_name=?, category=?, normal_balance=?, note=?, is_active=?, updated_at=? WHERE code=?")->execute([trim((string) $payload['name']), strtolower(trim((string) $payload['category'])), strtolower(trim((string) $payload['normalBalance'])), trim((string) ($payload['note'] ?? '')) ?: null, !isset($payload['active']) || $payload['active'] ? 1 : 0, now_ts(), $params['code']]);
        respond(['message' => 'COA berhasil diperbarui.']);
    }

    if ($method === 'GET' && $path === 'room-types') { $rows = db_all($db, "SELECT * FROM room_types ORDER BY name ASC"); respond(['data' => array_map(static fn (array $row): array => ['id' => as_int($row['id']), 'code' => $row['code'], 'name' => $row['name'], 'capacity' => as_int($row['capacity']), 'baseRate' => as_float($row['base_rate'])], $rows)]); }
    if ($method === 'GET' && $path === 'rooms') {
        sync_housekeeping_queue($db);
        $page = max(1, as_int(q('page', 1))); $perPage = min(max(as_int(q('per_page', 12)), 1), 100); $search = trim((string) q('search', '')); $where = "WHERE r.status != 'inactive'"; $params = [];
        if ($search !== '') { $like = '%' . $search . '%'; $where .= " AND (r.room_code LIKE ? OR r.room_name LIKE ? OR rt.name LIKE ? OR r.coa_receivable_code LIKE ? OR r.coa_revenue_code LIKE ?)"; $params = [$like, $like, $like, $like, $like]; }
        $total = (int) db_value($db, "SELECT COUNT(*) FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id {$where}", $params);
        $rows = db_all($db, "SELECT r.*, rt.name AS room_type_name, rt.base_rate, ar.account_name AS receivable_name, rv.account_name AS revenue_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id LEFT JOIN coa_accounts ar ON ar.code = r.coa_receivable_code LEFT JOIN coa_accounts rv ON rv.code = r.coa_revenue_code {$where} ORDER BY CAST(r.room_code AS UNSIGNED), r.room_code ASC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params);
        $taskRows = db_all($db, "SELECT room_id, task_type, task_status FROM housekeeping_tasks WHERE business_date = ? AND task_status IN ('pending', 'in_progress')", [today_date()]); $taskMap = [];
        foreach ($taskRows as $taskRow) $taskMap[(int) $taskRow['room_id']] = $taskRow;
        respond(['data' => array_map(static function (array $room) use ($taskMap): array { $task = $taskMap[(int) $room['id']] ?? null; $hkLabel = $task ? (($task['task_status'] === 'in_progress' ? 'In progress: ' : 'Pending: ') . $task['task_type']) : ($room['status'] === 'occupied' ? 'Guest in house' : 'Vacant clean'); return ['id' => as_int($room['id']), 'code' => $room['room_code'], 'name' => $room['room_name'], 'roomTypeId' => as_int($room['room_type_id']), 'type' => $room['room_type_name'], 'rate' => as_float($room['base_rate']), 'coaReceivableCode' => $room['coa_receivable_code'], 'coaReceivable' => $room['coa_receivable_code'] ? $room['coa_receivable_code'] . ' - ' . ($room['receivable_name'] ?? '') : '', 'coaRevenueCode' => $room['coa_revenue_code'], 'coaRevenue' => $room['coa_revenue_code'] ? $room['coa_revenue_code'] . ' - ' . ($room['revenue_name'] ?? '') : '', 'status' => ucfirst((string) $room['status']), 'floor' => $room['floor'], 'note' => $room['notes'] ?? '', 'hk' => $hkLabel, 'no' => $room['room_code']]; }, $rows), 'meta' => paginate_meta($page, $perPage, $total)]);
    }
    if ($method === 'POST' && $path === 'rooms') { $payload = json_input(); require_fields($payload, ['code', 'name']); if (db_one($db, "SELECT id FROM rooms WHERE room_code = ?", [trim((string) $payload['code'])])) fail('Kode kamar sudah dipakai.', 422, ['code' => ['Kode kamar sudah dipakai.']]); $db->prepare("INSERT INTO rooms (room_type_id, room_code, room_name, coa_receivable_code, coa_revenue_code, status, floor, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([as_int($payload['roomTypeId'] ?? standard_room_type_id($db)), trim((string) $payload['code']), trim((string) $payload['name']), trim((string) ($payload['coaReceivableCode'] ?? '')) ?: null, trim((string) ($payload['coaRevenueCode'] ?? '')) ?: null, strtolower(trim((string) ($payload['status'] ?? 'available'))), trim((string) ($payload['floor'] ?? '')) ?: null, trim((string) ($payload['notes'] ?? '')) ?: null, now_ts(), now_ts()]); respond(['message' => 'Master kamar berhasil ditambahkan.'], 201); }
    if ($method === 'PUT' && ($params = match_route('rooms/{code}', $path)) !== null) { $payload = json_input(); require_fields($payload, ['name']); $db->prepare("UPDATE rooms SET room_type_id=?, room_name=?, coa_receivable_code=?, coa_revenue_code=?, status=?, floor=?, notes=?, updated_at=? WHERE room_code=?")->execute([as_int($payload['roomTypeId'] ?? standard_room_type_id($db)), trim((string) $payload['name']), trim((string) ($payload['coaReceivableCode'] ?? '')) ?: null, trim((string) ($payload['coaRevenueCode'] ?? '')) ?: null, strtolower(trim((string) ($payload['status'] ?? 'available'))), trim((string) ($payload['floor'] ?? '')) ?: null, trim((string) ($payload['notes'] ?? '')) ?: null, now_ts(), $params['code']]); respond(['message' => 'Master kamar berhasil diperbarui.']); }
    if ($method === 'GET' && $path === 'housekeeping/queue') { respond(['data' => housekeeping_queue_rows($db)]); }
    if ($method === 'PATCH' && ($params = match_route('housekeeping/tasks/{id}', $path)) !== null) { $payload = json_input(); require_fields($payload, ['status']); $task = update_housekeeping_task_status($db, as_int($params['id']), (string) $payload['status']); respond(['data' => $task, 'message' => "Task housekeeping kamar {$task['room']} berhasil diperbarui."]); }

    if ($method === 'GET' && $path === 'bookings') {
        $page = max(1, as_int(q('page', 1))); $perPage = min(max(as_int(q('per_page', 12)), 1), 100); $search = trim((string) q('search', '')); $where = ''; $params = [];
        if ($search !== '') { $like = '%' . $search . '%'; $where = "WHERE (b.booking_code LIKE ? OR b.source LIKE ? OR b.status LIKE ? OR g.full_name LIKE ? OR g.phone LIKE ? OR g.email LIKE ? OR EXISTS (SELECT 1 FROM booking_rooms br2 JOIN rooms r2 ON r2.id = br2.room_id WHERE br2.booking_id = b.id AND r2.room_code LIKE ?))"; $params = [$like, $like, $like, $like, $like, $like, $like]; }
        $total = (int) db_value($db, "SELECT COUNT(*) FROM bookings b JOIN guests g ON g.id = b.guest_id {$where}", $params);
        $rows = db_all($db, "SELECT b.*, g.full_name AS guest_name, g.phone AS guest_phone, g.email AS guest_email FROM bookings b JOIN guests g ON g.id = b.guest_id {$where} ORDER BY b.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params);
        respond(['data' => array_map(fn (array $row): array => transform_booking($db, $row), $rows), 'meta' => paginate_meta($page, $perPage, $total)]);
    }
    if ($method === 'GET' && ($params = match_route('bookings/{code}', $path)) !== null) { $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); respond(['data' => transform_booking($db, $booking)]); }
    if ($method === 'GET' && ($params = match_route('bookings/{code}/checkout-inventory', $path)) !== null) { $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); respond(['data' => booking_checkout_inventory_rows($db, as_int($booking['id']))]); }
    if ($method === 'GET' && ($params = match_route('bookings/{code}/invoice-pdf', $path)) !== null) { respond_invoice_pdf($db, (string) $params['code']); }
    if ($method === 'GET' && ($params = match_route('bookings/{code}/invoice-print', $path)) !== null) { respond_invoice_print_html($db, (string) $params['code']); }
    if ($method === 'POST' && $path === 'bookings') {
        $payload = json_input(); require_fields($payload, ['guest', 'checkIn', 'checkOut', 'channel', 'status', 'roomDetails']); if (!is_array($payload['roomDetails']) || count($payload['roomDetails']) < 1) fail('Minimal satu kamar wajib dipilih.', 422, ['roomDetails' => ['Minimal satu kamar wajib dipilih.']]);
        ensure_rooms_available($db, $payload['roomDetails'], (string) $payload['checkIn'], (string) $payload['checkOut'], null); $db->beginTransaction();
        try { $guestId = guest_upsert($db, null, $payload); $bookingCode = generate_booking_code($db, (string) $payload['checkIn']); $roomAmount = calculate_room_amount($payload['roomDetails'], (string) $payload['checkIn'], (string) $payload['checkOut']);
            $db->prepare("INSERT INTO bookings (booking_code, guest_id, source, status, check_in_at, check_out_at, room_amount, addon_amount, discount_amount, tax_amount, grand_total, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)")->execute([$bookingCode, $guestId, normalize_source((string) $payload['channel']), normalize_booking_status((string) $payload['status']), $payload['checkIn'], $payload['checkOut'], $roomAmount, $roomAmount, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]);
            $bookingId = (int) $db->lastInsertId(); sync_booking_rooms($db, $bookingId, $payload['roomDetails'], (string) $payload['checkIn'], (string) $payload['checkOut']); sync_booking_financial_state($db, $bookingId); $db->commit(); $booking = load_booking_row($db, $bookingCode);
            audit_log($db, ['module' => 'bookings', 'action' => 'created', 'entity_type' => 'booking', 'entity_id' => $bookingId, 'entity_label' => $bookingCode, 'description' => "Booking {$bookingCode} dibuat untuk " . trim((string) $payload['guest']) . '.', 'metadata' => ['status' => normalize_booking_status((string) $payload['status']), 'source' => normalize_source((string) $payload['channel']), 'grand_total' => $roomAmount]], $actor);
            respond(['data' => transform_booking($db, $booking), 'message' => 'Reservasi berhasil disimpan ke database.'], 201);
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    if ($method === 'PUT' && ($params = match_route('bookings/{code}', $path)) !== null) {
        $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404);
        $payload = json_input(); require_fields($payload, ['guest', 'checkIn', 'checkOut', 'channel', 'status', 'roomDetails']); if (!is_array($payload['roomDetails']) || count($payload['roomDetails']) < 1) fail('Minimal satu kamar wajib dipilih.', 422, ['roomDetails' => ['Minimal satu kamar wajib dipilih.']]);
        $targetStatus = normalize_booking_status((string) $payload['status']); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]);
        $editedAddonTotal = as_float(db_value($db, "SELECT COALESCE(SUM(total_price), 0) FROM booking_addons WHERE booking_id = ? AND status != 'cancelled'", [$booking['id']]));
        $editedRoomAmount = calculate_room_amount($payload['roomDetails'], (string) $payload['checkIn'], (string) $payload['checkOut']);
        $editedPenalty = $targetStatus === 'cancelled' ? round(max(0, $editedRoomAmount + $editedAddonTotal - as_float($booking['discount_amount'])) * (cancellation_penalty_percent($db) / 100), 2) : 0.0;
        if ($targetStatus === 'cancelled' && $invoice && as_float($invoice['paid_amount']) > $editedPenalty) fail('Pembayaran yang sudah diterima melebihi nilai penalti cancel. Lakukan refund atau void terlebih dahulu sebelum membatalkan booking.', 422, ['status' => ['Pembayaran melebihi penalti cancel.']]);
        if ($targetStatus === 'no_show' && $invoice && as_float($invoice['paid_amount']) > 0) fail('Booking yang sudah menerima pembayaran tidak bisa langsung diubah ke no-show. Lakukan refund atau void terlebih dahulu.', 422, ['status' => ['Booking sudah memiliki pembayaran.']]);
        ensure_rooms_available($db, $payload['roomDetails'], (string) $payload['checkIn'], (string) $payload['checkOut'], as_int($booking['id'])); $db->beginTransaction();
        try { $guestId = guest_upsert($db, as_int($booking['guest_id']), $payload); $roomAmount = $editedRoomAmount; $grandTotal = $roomAmount + as_float($booking['addon_amount']) - as_float($booking['discount_amount']) + as_float($booking['tax_amount']);
            $db->prepare("UPDATE bookings SET guest_id=?, source=?, status=?, check_in_at=?, check_out_at=?, room_amount=?, grand_total=?, notes=?, updated_at=? WHERE id=?")->execute([$guestId, normalize_source((string) $payload['channel']), $targetStatus, $payload['checkIn'], $payload['checkOut'], $roomAmount, $grandTotal, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), $booking['id']]);
            sync_booking_rooms($db, as_int($booking['id']), $payload['roomDetails'], (string) $payload['checkIn'], (string) $payload['checkOut']); sync_booking_financial_state($db, as_int($booking['id'])); $db->commit(); $fresh = load_booking_row($db, $params['code']);
            respond(['data' => transform_booking($db, $fresh), 'message' => 'Reservasi berhasil diperbarui.']);
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    if ($method === 'PATCH' && ($params = match_route('bookings/{code}/status', $path)) !== null) {
        $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404);
        $payload = json_input(); require_fields($payload, ['status']); $targetStatus = normalize_booking_status((string) $payload['status']); $currentStatus = (string) $booking['status'];
        if ($targetStatus === 'checked_in' && !in_array($currentStatus, ['draft', 'confirmed'], true)) fail('Hanya booking Tentative atau Confirmed yang bisa di-check-in.', 422, ['status' => ['Hanya booking Tentative atau Confirmed yang bisa di-check-in.']]);
        if ($targetStatus === 'checked_out') { if ($currentStatus !== 'checked_in') fail('Hanya booking Checked-in yang bisa di-check-out.', 422, ['status' => ['Hanya booking Checked-in yang bisa di-check-out.']]); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]); if ($invoice && as_float($invoice['balance_due']) > 0) fail('Tidak bisa Check-out. Tamu belum melunasi tagihan (Sisa: ' . money(as_float($invoice['balance_due'])) . '). Silakan lakukan pembayaran terlebih dahulu di menu Finance / Folio.', 422, ['status' => ['Invoice belum lunas.']]); $checkoutInventory = booking_checkout_inventory_rows($db, as_int($booking['id'])); if ($checkoutInventory !== [] && !array_key_exists('roomInventoryUsage', $payload)) fail('Selesaikan dulu pemakaian item kamar sebelum check-out.', 422, ['inventory' => ['Pemakaian item kamar belum direview.']]); }
        if (in_array($targetStatus, ['cancelled', 'no_show'], true)) { $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]); $cancelPenalty = $targetStatus === 'cancelled' ? booking_cancellation_penalty_amount($db, $booking) : 0.0; if ($targetStatus === 'cancelled' && $invoice && as_float($invoice['paid_amount']) > $cancelPenalty) fail('Pembayaran yang sudah diterima melebihi nilai penalti cancel. Lakukan refund atau void terlebih dahulu sebelum membatalkan booking.', 422, ['status' => ['Pembayaran melebihi penalti cancel.']]); if ($targetStatus === 'no_show' && $invoice && as_float($invoice['paid_amount']) > 0) fail('Booking yang sudah menerima pembayaran tidak bisa langsung diubah ke no-show. Lakukan refund atau void terlebih dahulu.', 422, ['status' => ['Booking sudah memiliki pembayaran.']]); }
        $db->beginTransaction();
        try { if ($targetStatus === 'checked_out') process_booking_checkout_inventory($db, $booking, is_array($payload['roomInventoryUsage'] ?? null) ? $payload['roomInventoryUsage'] : []); $db->prepare("UPDATE bookings SET status=?, updated_at=? WHERE id=?")->execute([$targetStatus, now_ts(), $booking['id']]); if ($targetStatus === 'checked_in' || $targetStatus === 'checked_out') { $roomIds = db_all($db, "SELECT room_id FROM booking_rooms WHERE booking_id = ?", [$booking['id']]); $status = $targetStatus === 'checked_in' ? 'occupied' : 'dirty'; $up = $db->prepare("UPDATE rooms SET status=?, updated_at=? WHERE id=?"); foreach ($roomIds as $row) $up->execute([$status, now_ts(), $row['room_id']]); } sync_booking_financial_state($db, as_int($booking['id'])); $db->commit(); }
        catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $fresh = load_booking_row($db, $params['code']); $statusMessage = match ($targetStatus) { 'checked_in' => 'Tamu berhasil check-in.', 'checked_out' => 'Tamu berhasil check-out.', 'cancelled' => ($fresh ? 'Reservasi berhasil dibatalkan. Penalti cancel ' . cancellation_policy_payload($db)['label'] . '%: ' . money(as_float($fresh['grand_total'])) . '.' : 'Reservasi berhasil dibatalkan.'), 'no_show' => 'Reservasi ditandai no-show.', default => 'Status reservasi berhasil diperbarui.', };
        respond(['data' => transform_booking($db, $fresh), 'message' => $statusMessage]);
    }
    if ($method === 'POST' && ($params = match_route('bookings/{code}/addons', $path)) !== null) {
        $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); $payload = json_input(); require_fields($payload, ['addonType', 'serviceName', 'addonLabel', 'unitPriceValue', 'status']);
        $quantity = addon_quantity($payload); $unitPrice = as_float($payload['unitPriceValue']); $vendorUnitPrice = as_float($payload['vendorUnitPriceValue'] ?? 0); $customerTotalPrice = $unitPrice * $quantity; $vendorTotalPrice = $vendorUnitPrice * $quantity;
        $db->prepare("INSERT INTO booking_addons (booking_id, addon_type, reference_id, service_date, start_date, end_date, qty, unit_price, total_price, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$booking['id'], trim((string) $payload['addonType']), parse_catalog_reference_id($payload['referenceId'] ?? null), $payload['serviceDate'] ?? $payload['startDate'] ?? substr((string) $booking['check_in_at'], 0, 10), $payload['startDate'] ?? $payload['serviceDate'] ?? substr((string) $booking['check_in_at'], 0, 10), $payload['endDate'] ?? null, $quantity, $unitPrice, $customerTotalPrice, normalize_addon_status((string) $payload['status']), json_encode(['note' => trim((string) ($payload['notes'] ?? '')), 'serviceName' => trim((string) $payload['serviceName']), 'addonLabel' => trim((string) $payload['addonLabel']), 'itemRef' => trim((string) ($payload['referenceId'] ?? '')), 'vendorUnitPriceValue' => $vendorUnitPrice, 'vendorTotalPriceValue' => $vendorTotalPrice, 'customerUnitPriceValue' => $unitPrice, 'feeTotalValue' => $customerTotalPrice - $vendorTotalPrice], JSON_UNESCAPED_UNICODE), now_ts(), now_ts()]);
        $addonId = (int) $db->lastInsertId();
        sync_booking_addon_vendor_bill($db, $addonId, $booking, $actor);
        sync_booking_financial_state($db, as_int($booking['id'])); $fresh = load_booking_row($db, $params['code']); respond(['data' => transform_booking($db, $fresh), 'message' => 'Add-on berhasil ditautkan ke reservasi.'], 201);
    }
    if ($method === 'PATCH' && ($params = match_route('bookings/{code}/addons/{id}', $path)) !== null) { $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); $payload = json_input(); require_fields($payload, ['status']); $db->prepare("UPDATE booking_addons SET status=?, updated_at=? WHERE id=? AND booking_id=?")->execute([normalize_addon_status((string) $payload['status']), now_ts(), $params['id'], $booking['id']]); sync_booking_addon_vendor_bill($db, as_int($params['id']), $booking, $actor); sync_booking_financial_state($db, as_int($booking['id'])); $fresh = load_booking_row($db, $params['code']); respond(['data' => transform_booking($db, $fresh), 'message' => 'Status add-on berhasil diperbarui.']); }
    if ($method === 'DELETE' && ($params = match_route('bookings/{code}/addons/{id}', $path)) !== null) { $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); $db->prepare("DELETE FROM booking_addons WHERE id=? AND booking_id=?")->execute([$params['id'], $booking['id']]); sync_booking_addon_vendor_bill($db, as_int($params['id']), $booking, $actor); sync_booking_financial_state($db, as_int($booking['id'])); $fresh = load_booking_row($db, $params['code']); respond(['data' => transform_booking($db, $fresh), 'message' => 'Add-on berhasil dihapus dari reservasi.']); }

    if ($method === 'GET' && $path === 'payments') { $rows = db_all($db, "SELECT * FROM payments ORDER BY id DESC"); respond(['data' => array_map(fn (array $row): array => payment_transform($db, $row), $rows)]); }
    if ($method === 'GET' && ($params = match_route('finance/invoices/{code}/pdf', $path)) !== null) { respond_invoice_pdf($db, (string) $params['code']); }
    if ($method === 'GET' && ($params = match_route('finance/invoices/{code}/print', $path)) !== null) { respond_invoice_print_html($db, (string) $params['code']); }
    if ($method === 'POST' && $path === 'payments') {
        $payload = json_input(); require_fields($payload, ['bookingCode', 'amountValue', 'method', 'paymentDate']); $booking = load_booking_row($db, trim((string) $payload['bookingCode'])); if (!$booking) fail('Booking tidak ditemukan.', 422, ['bookingCode' => ['Booking tidak ditemukan.']]);
        assert_open_transaction_date($db, trim((string) $payload['paymentDate']), 'Payment date');
        sync_booking_financial_state($db, as_int($booking['id'])); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]); if (!$invoice) fail('Invoice untuk reservasi ini tidak ditemukan.', 422, ['bookingCode' => ['Invoice untuk reservasi ini tidak ditemukan.']]);
        $amount = as_float($payload['amountValue']); if ($amount > as_float($invoice['balance_due'])) fail('Nominal pembayaran melebihi saldo terutang invoice.', 422, ['amountValue' => ['Nominal pembayaran melebihi saldo terutang invoice.']]);
        $db->beginTransaction();
        try { $db->prepare("INSERT INTO payments (payment_number, guest_id, payment_date, payment_method, amount, transaction_type, parent_payment_id, cash_bank_coa_code, reference_number, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'payment', NULL, ?, ?, ?, ?, ?)")->execute([generate_payment_code($db, 'payment'), $booking['guest_id'], $payload['paymentDate'], normalize_payment_method((string) $payload['method']), $amount, resolve_cash_bank_coa($db, (string) $payload['method']), trim((string) ($payload['referenceNo'] ?? '')) ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]);
            $paymentId = (int) $db->lastInsertId(); $db->prepare("INSERT INTO payment_allocations (payment_id, invoice_id, allocated_amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")->execute([$paymentId, $invoice['id'], $amount, now_ts(), now_ts()]);
            sync_payment_accounting($db, $paymentId); sync_booking_financial_state($db, as_int($booking['id'])); $db->commit(); $payment = db_one($db, "SELECT * FROM payments WHERE id = ?", [$paymentId]); respond(['data' => payment_transform($db, $payment), 'message' => 'Pembayaran berhasil disimpan.'], 201);
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    if ($method === 'POST' && ($params = match_route('payments/{id}/refund', $path)) !== null) { $result = reverse_payment_entry($db, as_int($params['id']), 'refund', json_input()); respond(['data' => $result['payment'], 'message' => "Refund payment berhasil diposting untuk {$result['booking_code']}."]); }
    if ($method === 'POST' && ($params = match_route('payments/{id}/void', $path)) !== null) { $result = reverse_payment_entry($db, as_int($params['id']), 'void', json_input()); respond(['data' => $result['payment'], 'message' => "Void payment berhasil diposting untuk {$result['booking_code']}."]); }

    if ($method === 'GET' && $path === 'vendors') {
        $rows = db_all($db, "SELECT v.*, (SELECT GROUP_CONCAT(DISTINCT vs2.service_type ORDER BY vs2.service_type SEPARATOR ',') FROM vendor_services vs2 WHERE vs2.vendor_id = v.id) AS service_types, COUNT(b.id) AS bill_count, COALESCE(SUM(CASE WHEN b.status != 'void' THEN b.balance_due ELSE 0 END), 0) AS outstanding, COALESCE(SUM(CASE WHEN b.status != 'void' AND b.balance_due > 0 AND b.due_date < CURDATE() THEN b.balance_due ELSE 0 END), 0) AS overdue FROM vendors v LEFT JOIN vendor_bills b ON b.vendor_id = v.id GROUP BY v.id ORDER BY v.vendor_name ASC");
        respond(['data' => array_map(static fn (array $row): array => transform_vendor($row, $row), $rows)]);
    }
    if (($method === 'POST' && $path === 'vendors') || ($method === 'PUT' && ($params = match_route('vendors/{id}', $path)) !== null)) {
        $payload = json_input(); require_fields($payload, ['vendorName']); $vendorCode = trim((string) ($payload['vendorCode'] ?? '')); $now = now_ts();
        $serviceTypes = normalize_service_types($payload['serviceTypes'] ?? ($payload['vendorType'] ?? null));
        $primaryType = $serviceTypes[0] ?? 'general';
        if ($method === 'POST') {
            if ($vendorCode === '') $vendorCode = generate_vendor_code($db);
            $db->prepare("INSERT INTO vendors (vendor_code, vendor_name, vendor_type, phone, email, address, contact_person, payment_terms_days, opening_balance, is_active, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$vendorCode, trim((string) $payload['vendorName']), $primaryType, trim((string) ($payload['phone'] ?? '')) ?: null, trim((string) ($payload['email'] ?? '')) ?: null, trim((string) ($payload['address'] ?? '')) ?: null, trim((string) ($payload['contactPerson'] ?? '')) ?: null, as_int($payload['paymentTermsDays'] ?? 0), as_float($payload['openingBalanceValue'] ?? 0), !empty($payload['isActive']) ? 1 : 0, trim((string) ($payload['notes'] ?? '')) ?: null, $now, $now]);
            respond(['message' => 'Activity vendor berhasil ditambahkan.'], 201);
        }
        $db->prepare("UPDATE vendors SET vendor_name=?, vendor_type=?, phone=?, email=?, address=?, contact_person=?, payment_terms_days=?, opening_balance=?, is_active=?, notes=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['vendorName']), $primaryType, trim((string) ($payload['phone'] ?? '')) ?: null, trim((string) ($payload['email'] ?? '')) ?: null, trim((string) ($payload['address'] ?? '')) ?: null, trim((string) ($payload['contactPerson'] ?? '')) ?: null, as_int($payload['paymentTermsDays'] ?? 0), as_float($payload['openingBalanceValue'] ?? 0), !empty($payload['isActive']) ? 1 : 0, trim((string) ($payload['notes'] ?? '')) ?: null, $now, as_int($params['id'])]);
        respond(['message' => 'Activity vendor berhasil diperbarui.']);
    }
    if ($method === 'GET' && $path === 'vendor-bills') {
        $vendorId = as_int(q('vendor_id', 0)); $status = trim((string) q('status', '')); $sql = "SELECT b.*, v.vendor_name FROM vendor_bills b INNER JOIN vendors v ON v.id = b.vendor_id WHERE 1=1"; $params = [];
        if ($vendorId > 0) { $sql .= " AND b.vendor_id = ?"; $params[] = $vendorId; }
        if ($status !== '') { $sql .= " AND b.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY b.due_date ASC, b.id DESC";
        $rows = db_all($db, $sql, $params);
        respond(['data' => array_map(static fn (array $row): array => transform_vendor_bill($row), $rows)]);
    }
    if ($method === 'POST' && $path === 'vendor-bills') {
        $payload = json_input(); require_fields($payload, ['vendorId', 'billDate', 'description', 'grandTotalValue']); $vendor = db_one($db, "SELECT * FROM vendors WHERE id = ?", [as_int($payload['vendorId'])]); if (!$vendor) fail('Vendor tidak ditemukan.', 422);
        $sourceModule = trim((string) ($payload['sourceModule'] ?? 'activity')) ?: 'activity';
        if (!is_activity_source_module($sourceModule)) fail('Sumber activity tidak valid.', 422, ['sourceModule' => ['Sumber activity tidak valid.']]);
        $billDate = trim((string) $payload['billDate']); $dueDate = trim((string) ($payload['dueDate'] ?? '')) ?: date('Y-m-d', strtotime($billDate . ' +' . max(0, as_int($vendor['payment_terms_days'])) . ' day')); $grandTotal = as_float($payload['grandTotalValue']); $subtotal = as_float($payload['subtotalValue'] ?? $grandTotal); $taxAmount = as_float($payload['taxAmountValue'] ?? 0); $discountAmount = as_float($payload['discountAmountValue'] ?? 0); $billNumber = trim((string) ($payload['billNumber'] ?? '')) ?: generate_vendor_bill_number($db, $billDate); $expenseCoaCode = coa_code_only((string) ($payload['expenseCoaCode'] ?? '')); $payableCoaCode = coa_code_only((string) ($payload['payableCoaCode'] ?? '')) ?: resolve_payable_coa($db);
        $db->prepare("INSERT INTO vendor_bills (bill_number, vendor_id, bill_date, due_date, source_module, source_reference, description, subtotal, tax_amount, discount_amount, grand_total, paid_amount, balance_due, expense_coa_code, payable_coa_code, status, created_by, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'unpaid', ?, ?, ?, ?)")->execute([$billNumber, as_int($payload['vendorId']), $billDate, $dueDate, $sourceModule, trim((string) ($payload['sourceReference'] ?? '')) ?: null, trim((string) $payload['description']), $subtotal, $taxAmount, $discountAmount, $grandTotal, $grandTotal, $expenseCoaCode ?: null, $payableCoaCode ?: null, as_int($actor['id'] ?? 0) ?: null, trim((string) ($payload['notes'] ?? '')) ?: null, now_ts(), now_ts()]);
        sync_vendor_bill_accounting($db, (int) $db->lastInsertId());
        respond(['message' => 'Tagihan activity vendor berhasil dibuat.'], 201);
    }
    if ($method === 'GET' && $path === 'vendor-payments') {
        $vendorId = as_int(q('vendor_id', 0)); $params = []; if ($vendorId > 0) $params[] = $vendorId;
        $rows = db_all($db, "SELECT p.*, v.vendor_name FROM vendor_payments p INNER JOIN vendors v ON v.id = p.vendor_id WHERE 1=1" . ($vendorId > 0 ? " AND p.vendor_id = ?" : "") . " ORDER BY p.payment_date DESC, p.id DESC", $params); $result = [];
        foreach ($rows as $row) { $allocRows = db_all($db, "SELECT a.*, b.bill_number FROM vendor_payment_allocations a INNER JOIN vendor_bills b ON b.id = a.vendor_bill_id WHERE a.vendor_payment_id = ? ORDER BY a.id ASC", [as_int($row['id'])]); $result[] = transform_vendor_payment($row, array_map(static fn (array $alloc): array => ['billId' => as_int($alloc['vendor_bill_id']), 'billNumber' => (string) $alloc['bill_number'], 'allocatedAmountValue' => as_float($alloc['allocated_amount']), 'allocatedAmount' => money(as_float($alloc['allocated_amount']))], $allocRows)); }
        respond(['data' => $result]);
    }
    if ($method === 'POST' && $path === 'vendor-payments') {
        $payload = json_input(); require_fields($payload, ['vendorId', 'paymentDate', 'amountValue', 'paymentMethod']); $vendorId = as_int($payload['vendorId']); $vendor = db_one($db, "SELECT * FROM vendors WHERE id = ?", [$vendorId]); if (!$vendor) fail('Vendor tidak ditemukan.', 422);
        $amount = as_float($payload['amountValue']); if ($amount <= 0) fail('Nominal pembayaran vendor harus lebih besar dari nol.', 422, ['amountValue' => ['Nominal pembayaran vendor harus lebih besar dari nol.']]); $paymentDate = trim((string) $payload['paymentDate']); $paymentNumber = trim((string) ($payload['paymentNumber'] ?? '')) ?: generate_vendor_payment_number($db, $paymentDate);
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO vendor_payments (payment_number, vendor_id, payment_date, payment_method, amount, reference_number, cash_bank_coa_code, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$paymentNumber, $vendorId, $paymentDate, normalize_payment_method((string) $payload['paymentMethod']), $amount, trim((string) ($payload['referenceNumber'] ?? '')) ?: null, trim((string) ($payload['cashBankCoaCode'] ?? '')) ?: null, trim((string) ($payload['notes'] ?? '')) ?: null, as_int($actor['id'] ?? 0) ?: null, now_ts(), now_ts()]);
            $paymentId = (int) $db->lastInsertId(); $remaining = $amount; $allocations = is_array($payload['allocations'] ?? null) ? $payload['allocations'] : []; $hasExplicitAllocations = $allocations !== [];
            if ($allocations !== []) {
                foreach ($allocations as $alloc) {
                    $billId = as_int($alloc['billId'] ?? 0); $allocated = min($remaining, as_float($alloc['allocatedAmountValue'] ?? 0)); if ($billId <= 0 || $allocated <= 0) continue;
                    $bill = db_one($db, "SELECT * FROM vendor_bills WHERE id = ? AND vendor_id = ? AND status != 'void'", [$billId, $vendorId]); if (!$bill) continue; $allocated = min($allocated, as_float($bill['balance_due'])); if ($allocated <= 0) continue;
                    $db->prepare("INSERT INTO vendor_payment_allocations (vendor_payment_id, vendor_bill_id, allocated_amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")->execute([$paymentId, $billId, $allocated, now_ts(), now_ts()]); vendor_refresh_bill($db, $billId); $remaining -= $allocated; if ($remaining <= 0) break;
                }
            }
            if (!$hasExplicitAllocations && $remaining > 0) {
                $openBills = db_all($db, "SELECT * FROM vendor_bills WHERE vendor_id = ? AND status IN ('unpaid', 'partial') AND balance_due > 0 ORDER BY due_date ASC, id ASC", [$vendorId]);
                foreach ($openBills as $bill) { if ($remaining <= 0) break; $allocated = min($remaining, as_float($bill['balance_due'])); if ($allocated <= 0) continue; $db->prepare("INSERT INTO vendor_payment_allocations (vendor_payment_id, vendor_bill_id, allocated_amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")->execute([$paymentId, as_int($bill['id']), $allocated, now_ts(), now_ts()]); vendor_refresh_bill($db, as_int($bill['id'])); $remaining -= $allocated; }
            }
            $allocatedTotal = $amount - $remaining;
            if ($allocatedTotal <= 0) fail('Nilai 0 dianggap belum dibayar. Masukkan nominal pada hutang yang benar-benar dibayar.', 422, ['allocations' => ['Belum ada hutang yang dibayar.']]);
            if ($remaining > 0) $db->prepare("UPDATE vendor_payments SET amount = ?, updated_at = ? WHERE id = ?")->execute([$allocatedTotal, now_ts(), $paymentId]);
            sync_vendor_payment_accounting($db, $paymentId);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        respond(['message' => 'Pembayaran activity vendor berhasil diposting.'], 201);
    }
    if ($method === 'GET' && $path === 'reports/vendor-payables') { $vendorId = as_int(q('vendor_id', 0)); respond(['data' => vendor_payables_report($db, $vendorId > 0 ? $vendorId : null)]); }

    if ($method === 'GET' && $path === 'journals') {
        $page = max(1, as_int(q('page', 1))); $perPage = min(max(as_int(q('per_page', 15)), 1), 100); $search = trim((string) q('search', '')); $where = ''; $params = [];
        if ($search !== '') { $like = '%' . $search . '%'; $where = "WHERE (journal_number LIKE ? OR description LIKE ? OR reference_type LIKE ?)"; $params = [$like, $like, $like]; }
        $total = (int) db_value($db, "SELECT COUNT(*) FROM journals {$where}", $params); $rows = db_all($db, "SELECT * FROM journals {$where} ORDER BY journal_date DESC, journal_number DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params); $data = [];
        foreach ($rows as $journal) { $lines = db_all($db, "SELECT * FROM journal_lines WHERE journal_id = ? ORDER BY id ASC", [$journal['id']]); $debit = array_sum(array_map(static fn (array $row): float => as_float($row['debit']), $lines)); $credit = array_sum(array_map(static fn (array $row): float => as_float($row['credit']), $lines)); $data[] = ['id' => as_int($journal['id']), 'journalNo' => $journal['journal_number'], 'journalDate' => $journal['journal_date'], 'referenceNo' => $journal['reference_type'] ?? '', 'description' => $journal['description'], 'debitTotalValue' => $debit, 'creditTotalValue' => $credit, 'lineCount' => count($lines), 'lines' => array_map(static fn (array $line): array => ['id' => as_int($line['id']), 'coaCode' => $line['coa_code'], 'debitValue' => as_float($line['debit']), 'creditValue' => as_float($line['credit']), 'memo' => $line['line_description'] ?? ''], $lines)]; }
        respond(['data' => $data, 'meta' => paginate_meta($page, $perPage, $total)]);
    }
    if (($method === 'POST' && $path === 'journals') || ($method === 'PUT' && ($params = match_route('journals/{id}', $path)) !== null)) {
        $payload = json_input(); require_fields($payload, ['journalDate', 'description', 'lines']); if (!is_array($payload['lines']) || count($payload['lines']) < 2) fail('Minimal dua baris jurnal yang valid wajib diisi.', 422, ['lines' => ['Minimal dua baris jurnal yang valid wajib diisi.']]);
        assert_open_transaction_date($db, trim((string) $payload['journalDate']), 'Journal date');
        $lines = []; foreach ($payload['lines'] as $line) { $account = trim((string) ($line['account'] ?? '')); $coaCode = trim((string) strtok($account, '-')); $debit = round(as_float($line['debitValue'] ?? 0), 2); $credit = round(as_float($line['creditValue'] ?? 0), 2); if ($account === '' || ($debit <= 0 && $credit <= 0)) continue; if ($debit > 0 && $credit > 0) fail('Satu baris jurnal hanya boleh berisi debit atau kredit.', 422, ['lines' => ['Satu baris jurnal hanya boleh berisi debit atau kredit.']]); $lines[] = ['coa_code' => $coaCode, 'debit' => $debit, 'credit' => $credit, 'memo' => trim((string) ($line['memo'] ?? ''))]; }
        if (count($lines) < 2) fail('Minimal dua baris jurnal yang valid wajib diisi.', 422, ['lines' => ['Minimal dua baris jurnal yang valid wajib diisi.']]); $debitTotal = array_sum(array_map(static fn (array $line): float => $line['debit'], $lines)); $creditTotal = array_sum(array_map(static fn (array $line): float => $line['credit'], $lines));
        if ($debitTotal <= 0 || $creditTotal <= 0 || abs($debitTotal - $creditTotal) > 0.000001) fail('Total debit dan kredit harus seimbang.', 422, ['lines' => ['Total debit dan kredit harus seimbang.']]);
        $db->beginTransaction();
        try { if ($method === 'POST') { $db->prepare("INSERT INTO journals (journal_number, journal_date, reference_type, description, source, posted_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'manual', NULL, ?, ?)")->execute([generate_journal_number($db, (string) $payload['journalDate']), $payload['journalDate'], trim((string) ($payload['referenceNo'] ?? '')) ?: null, trim((string) $payload['description']), now_ts(), now_ts()]); $journalId = (int) $db->lastInsertId(); }
            else { $journalId = as_int($params['id']); $db->prepare("UPDATE journals SET journal_date=?, reference_type=?, description=?, source='manual', updated_at=? WHERE id=?")->execute([$payload['journalDate'], trim((string) ($payload['referenceNo'] ?? '')) ?: null, trim((string) $payload['description']), now_ts(), $journalId]); $db->prepare("DELETE FROM journal_lines WHERE journal_id=?")->execute([$journalId]); }
            $insert = $db->prepare("INSERT INTO journal_lines (journal_id, coa_code, line_description, debit, credit, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)"); foreach ($lines as $line) $insert->execute([$journalId, $line['coa_code'], $line['memo'] ?: null, $line['debit'], $line['credit'], now_ts(), now_ts()]); $db->commit(); $journal = db_one($db, "SELECT * FROM journals WHERE id = ?", [$journalId]);
            respond(['data' => ['id' => as_int($journalId), 'journalNo' => $journal['journal_number'], 'journalDate' => $journal['journal_date'], 'referenceNo' => $journal['reference_type'] ?? '', 'description' => $journal['description'], 'debitTotalValue' => $debitTotal, 'creditTotalValue' => $creditTotal, 'lineCount' => count($lines), 'lines' => array_map(static fn (array $line): array => ['coaCode' => $line['coa_code'], 'debitValue' => $line['debit'], 'creditValue' => $line['credit'], 'memo' => $line['memo']], $lines)], 'message' => $method === 'POST' ? "Jurnal umum {$journal['journal_number']} berhasil diposting." : "Jurnal umum {$journal['journal_number']} berhasil diperbarui."], $method === 'POST' ? 201 : 200);
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    if ($method === 'GET' && $path === 'inventory') {
        $items = db_all($db, "SELECT * FROM inventory_items ORDER BY id DESC"); $itemRows = [];
        foreach ($items as $item) { $purchased = as_float(db_value($db, "SELECT COALESCE(SUM(qty_in),0) FROM inventory_movements WHERE item_id = ?", [$item['id']])); $issued = as_float(db_value($db, "SELECT COALESCE(SUM(qty_out),0) FROM inventory_movements WHERE item_id = ?", [$item['id']])); $itemRows[] = ['id' => as_int($item['id']), 'name' => $item['item_name'], 'category' => $item['category'], 'trackingType' => strtolower((string) $item['category']) === 'linen' ? 'Linen' : 'Consumable', 'unit' => $item['unit'], 'purchasedQty' => as_int($purchased), 'issuedQty' => as_int($issued), 'onHandQty' => as_int(max($purchased - $issued, 0)), 'inventoryCoa' => $item['inventory_coa_code'], 'expenseCoa' => $item['expense_coa_code'], 'reorderLevel' => as_int($item['min_stock']), 'latestCostValue' => as_float($item['standard_cost']), 'latestCost' => money(as_float($item['standard_cost']))]; }
        $itemLookup = []; foreach ($itemRows as $row) $itemLookup[$row['id']] = $row;
        $purchaseReturnRows = db_all($db, "SELECT * FROM inventory_movements WHERE movement_type = 'return' AND reference_type = 'purchase_cancel' ORDER BY movement_date DESC, id DESC"); $purchaseReturnMap = [];
        foreach ($purchaseReturnRows as $movement) { $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $purchaseId = as_int($meta['purchaseMovementId'] ?? 0); if ($purchaseId > 0) $purchaseReturnMap[$purchaseId] = ($purchaseReturnMap[$purchaseId] ?? 0.0) + as_float($movement['qty_out']); }
        $roomReturnRows = db_all($db, "SELECT * FROM inventory_movements WHERE movement_type = 'return' AND reference_type = 'room_return' ORDER BY movement_date DESC, id DESC"); $roomReturnMap = [];
        foreach ($roomReturnRows as $movement) { $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $issueId = as_int($meta['issueMovementId'] ?? 0); if ($issueId > 0) $roomReturnMap[$issueId] = ($roomReturnMap[$issueId] ?? 0.0) + as_float($movement['qty_in']); }
        $purchaseMovements = db_all($db, "SELECT * FROM inventory_movements WHERE qty_in > 0 ORDER BY movement_date DESC, id DESC"); $purchaseRows = [];
        foreach ($purchaseMovements as $movement) { $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $item = $itemLookup[as_int($movement['item_id'])] ?? null; $returnedQty = as_float($purchaseReturnMap[as_int($movement['id'])] ?? 0); $remainingQty = max(0, as_float($movement['qty_in']) - $returnedQty); $total = as_float($movement['qty_in']) * as_float($movement['unit_cost']); $purchaseRows[] = ['id' => 'PUR-' . as_int($movement['id']), 'dbId' => as_int($movement['id']), 'purchaseDate' => substr((string) $movement['movement_date'], 0, 10), 'supplier' => trim((string) ($meta['supplier'] ?? $movement['reference_type'] ?? 'Supplier')), 'itemId' => as_int($movement['item_id']), 'itemName' => $item['name'] ?? 'Unknown item', 'quantity' => as_int($movement['qty_in']), 'returnedQty' => as_int($returnedQty), 'remainingQty' => as_int($remainingQty), 'unit' => $item['unit'] ?? 'pcs', 'totalCostValue' => $total, 'totalCost' => money($total), 'paymentAccount' => trim((string) ($meta['paymentAccount'] ?? '-')), 'note' => trim((string) ($meta['note'] ?? '')), 'status' => $remainingQty <= 0 ? 'Diretur' : 'Aktif', 'canCancel' => $remainingQty > 0 && (($item['onHandQty'] ?? 0) >= $remainingQty)]; }
        $issueMovements = db_all($db, "SELECT * FROM inventory_movements WHERE qty_out > 0 ORDER BY movement_date DESC, id DESC"); $issueRows = [];
        foreach ($issueMovements as $movement) { $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $item = $itemLookup[as_int($movement['item_id'])] ?? null; $trackingType = $item['trackingType'] ?? 'Consumable'; $returnedQty = as_float($roomReturnMap[as_int($movement['id'])] ?? 0); $remainingQty = max(0, as_float($movement['qty_out']) - $returnedQty); $total = as_float($movement['qty_out']) * as_float($movement['unit_cost']); $issueRows[] = ['id' => 'ISS-' . as_int($movement['id']), 'dbId' => as_int($movement['id']), 'issueDate' => substr((string) $movement['movement_date'], 0, 10), 'roomNo' => (string) ($movement['reference_id'] ?? ''), 'itemId' => as_int($movement['item_id']), 'itemName' => $item['name'] ?? 'Unknown item', 'quantity' => as_int($movement['qty_out']), 'returnedQty' => as_int($returnedQty), 'remainingQty' => as_int($remainingQty), 'unit' => $item['unit'] ?? 'pcs', 'trackingType' => $trackingType, 'totalValueValue' => $total, 'totalValueLabel' => money($total), 'inventoryCoa' => $item['inventoryCoa'] ?? '', 'expenseCoa' => $item['expenseCoa'] ?? '', 'note' => trim((string) ($meta['note'] ?? '')), 'status' => $remainingQty <= 0 ? 'Dikembalikan' : 'Masih di kamar', 'canReturn' => $remainingQty > 0]; }
        $journalRows = [];
        foreach ($purchaseRows as $entry) { $journalRows[] = ['id' => $entry['id'] . '-dr', 'entryDate' => $entry['purchaseDate'], 'source' => $entry['id'], 'transactionType' => 'Purchase', 'account' => 'Inventory', 'position' => 'Debit', 'amount' => $entry['totalCost'], 'memo' => 'Pembelian ' . $entry['itemName']]; $journalRows[] = ['id' => $entry['id'] . '-cr', 'entryDate' => $entry['purchaseDate'], 'source' => $entry['id'], 'transactionType' => 'Purchase', 'account' => $entry['paymentAccount'], 'position' => 'Credit', 'amount' => $entry['totalCost'], 'memo' => 'Pembayaran pembelian ' . $entry['itemName']]; }
        foreach ($issueRows as $entry) { if ($entry['trackingType'] === 'Linen') { $journalRows[] = ['id' => $entry['id'] . '-memo', 'entryDate' => $entry['issueDate'], 'source' => $entry['id'], 'transactionType' => 'Room issue', 'account' => 'Mutasi internal', 'position' => 'Memo', 'amount' => 'Mutasi internal', 'memo' => 'Issue linen ke kamar ' . $entry['roomNo']]; continue; } $journalRows[] = ['id' => $entry['id'] . '-dr', 'entryDate' => $entry['issueDate'], 'source' => $entry['id'], 'transactionType' => 'Room issue', 'account' => $entry['expenseCoa'], 'position' => 'Debit', 'amount' => $entry['totalValueLabel'], 'memo' => 'Issue ' . $entry['itemName'] . ' ke kamar ' . $entry['roomNo']]; $journalRows[] = ['id' => $entry['id'] . '-cr', 'entryDate' => $entry['issueDate'], 'source' => $entry['id'], 'transactionType' => 'Room issue', 'account' => $entry['inventoryCoa'], 'position' => 'Credit', 'amount' => $entry['totalValueLabel'], 'memo' => 'Pengurangan persediaan ' . $entry['itemName']]; }
        foreach ($purchaseReturnRows as $movement) { $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $item = $itemLookup[as_int($movement['item_id'])] ?? null; $total = as_float($movement['qty_out']) * as_float($movement['unit_cost']); $account = trim((string) ($meta['paymentAccount'] ?? 'Kas / Bank')); $label = $item['name'] ?? 'Unknown item'; $source = 'RET-PUR-' . as_int($movement['id']); $journalRows[] = ['id' => $source . '-dr', 'entryDate' => substr((string) $movement['movement_date'], 0, 10), 'source' => $source, 'transactionType' => 'Purchase return', 'account' => $account, 'position' => 'Debit', 'amount' => money($total), 'memo' => 'Retur pembelian ' . $label]; $journalRows[] = ['id' => $source . '-cr', 'entryDate' => substr((string) $movement['movement_date'], 0, 10), 'source' => $source, 'transactionType' => 'Purchase return', 'account' => $item['inventoryCoa'] ?? 'Inventory', 'position' => 'Credit', 'amount' => money($total), 'memo' => 'Pengurangan persediaan retur pembelian ' . $label]; }
        foreach ($roomReturnRows as $movement) { $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $item = $itemLookup[as_int($movement['item_id'])] ?? null; $label = $item['name'] ?? 'Unknown item'; $source = 'RET-ROOM-' . as_int($movement['id']); $entryDate = substr((string) $movement['movement_date'], 0, 10); if (($item['trackingType'] ?? 'Consumable') === 'Linen') { $journalRows[] = ['id' => $source . '-memo', 'entryDate' => $entryDate, 'source' => $source, 'transactionType' => 'Room return', 'account' => 'Mutasi internal', 'position' => 'Memo', 'amount' => 'Mutasi internal', 'memo' => 'Pengembalian linen dari kamar ' . ($movement['reference_id'] ?? '')]; continue; } $total = as_float($movement['qty_in']) * as_float($movement['unit_cost']); $journalRows[] = ['id' => $source . '-dr', 'entryDate' => $entryDate, 'source' => $source, 'transactionType' => 'Room return', 'account' => $item['inventoryCoa'] ?? 'Inventory', 'position' => 'Debit', 'amount' => money($total), 'memo' => 'Barang kembali dari kamar untuk ' . $label]; $journalRows[] = ['id' => $source . '-cr', 'entryDate' => $entryDate, 'source' => $source, 'transactionType' => 'Room return', 'account' => $item['expenseCoa'] ?? 'Expense', 'position' => 'Credit', 'amount' => money($total), 'memo' => 'Pembalik biaya pemakaian kamar ' . ($movement['reference_id'] ?? '')]; }
        usort($journalRows, static fn (array $a, array $b): int => strcmp((string) $b['entryDate'], (string) $a['entryDate']));
        respond(['data' => ['items' => $itemRows, 'purchases' => $purchaseRows, 'issues' => $issueRows, 'journalEntries' => $journalRows, 'summary' => ['itemCount' => count($itemRows), 'lowStockCount' => count(array_filter($itemRows, static fn (array $row): bool => $row['onHandQty'] <= $row['reorderLevel'])), 'consumableIssueCount' => count(array_filter($issueRows, static fn (array $row): bool => $row['trackingType'] === 'Consumable')), 'linenIssueCount' => count(array_filter($issueRows, static fn (array $row): bool => $row['trackingType'] === 'Linen'))]]]);
    }
    if ($method === 'POST' && $path === 'inventory/items') { $payload = json_input(); require_fields($payload, ['name', 'category', 'trackingType', 'unit', 'inventoryCoa', 'expenseCoa']); $db->prepare("INSERT INTO inventory_items (sku, item_name, category, unit, standard_cost, min_stock, inventory_coa_code, expense_coa_code, notes, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?, 'Created from inventory UI.', 1, ?, ?)")->execute(['SKU-' . date('YmdHis'), trim((string) $payload['name']), strtolower(trim((string) $payload['category'])), trim((string) $payload['unit']), as_int($payload['reorderLevel'] ?? 0), trim((string) $payload['inventoryCoa']), trim((string) $payload['expenseCoa']), now_ts(), now_ts()]); respond(['message' => "Item {$payload['name']} berhasil ditambahkan ke inventory master."], 201); }
    if ($method === 'PUT' && ($params = match_route('inventory/items/{id}', $path)) !== null) { $payload = json_input(); require_fields($payload, ['name', 'category', 'trackingType', 'unit', 'inventoryCoa', 'expenseCoa']); $item = db_one($db, "SELECT * FROM inventory_items WHERE id = ? LIMIT 1", [as_int($params['id'])]); if (!$item) fail('Item inventory tidak ditemukan.', 404); $db->prepare("UPDATE inventory_items SET item_name = ?, category = ?, unit = ?, min_stock = ?, inventory_coa_code = ?, expense_coa_code = ?, updated_at = ? WHERE id = ?")->execute([trim((string) $payload['name']), strtolower(trim((string) $payload['category'])), trim((string) $payload['unit']), as_int($payload['reorderLevel'] ?? 0), trim((string) $payload['inventoryCoa']), trim((string) $payload['expenseCoa']), now_ts(), as_int($params['id'])]); respond(['message' => "Item {$payload['name']} berhasil diperbarui."]); }
    if ($method === 'GET' && $path === 'master-units') { ensure_master_units_seed($db); $rows = db_all($db, "SELECT * FROM master_units ORDER BY name ASC"); $data = array_map(static fn (array $row): array => ['id' => as_int($row['id']), 'name' => trim((string) ($row['name'] ?? '')), 'itemCount' => (int) db_value($db, "SELECT COUNT(*) FROM inventory_items WHERE unit = ?", [trim((string) ($row['name'] ?? ''))]), 'isActive' => as_int($row['is_active'] ?? 1) === 1], $rows); respond(['data' => $data]); }
    if ($method === 'POST' && $path === 'master-units') { ensure_master_units_seed($db); $payload = json_input(); require_fields($payload, ['name']); $name = trim((string) $payload['name']); if ($name === '') fail('Nama satuan wajib diisi.', 422, ['name' => ['Nama satuan wajib diisi.']]); if ((int) db_value($db, "SELECT COUNT(*) FROM master_units WHERE LOWER(name) = LOWER(?)", [$name]) > 0) fail('Satuan sudah ada.', 422, ['name' => ['Satuan sudah ada.']]); $db->prepare("INSERT INTO master_units (name, is_active, created_at, updated_at) VALUES (?, 1, ?, ?)")->execute([$name, now_ts(), now_ts()]); respond(['message' => "Satuan {$name} berhasil ditambahkan."], 201); }
    if ($method === 'PUT' && ($params = match_route('master-units/{id}', $path)) !== null) { ensure_master_units_seed($db); $payload = json_input(); require_fields($payload, ['name']); $name = trim((string) $payload['name']); $unit = db_one($db, "SELECT * FROM master_units WHERE id = ? LIMIT 1", [as_int($params['id'])]); if (!$unit) fail('Satuan tidak ditemukan.', 404); if ($name === '') fail('Nama satuan wajib diisi.', 422, ['name' => ['Nama satuan wajib diisi.']]); if ((int) db_value($db, "SELECT COUNT(*) FROM master_units WHERE LOWER(name) = LOWER(?) AND id <> ?", [$name, as_int($params['id'])]) > 0) fail('Satuan sudah ada.', 422, ['name' => ['Satuan sudah ada.']]); $db->beginTransaction(); try { $db->prepare("UPDATE master_units SET name = ?, updated_at = ? WHERE id = ?")->execute([$name, now_ts(), as_int($params['id'])]); $db->prepare("UPDATE inventory_items SET unit = ?, updated_at = ? WHERE unit = ?")->execute([$name, now_ts(), trim((string) ($unit['name'] ?? ''))]); $db->commit(); respond(['message' => "Satuan {$name} berhasil diperbarui."]); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }
    if ($method === 'DELETE' && ($params = match_route('master-units/{id}', $path)) !== null) { ensure_master_units_seed($db); $unit = db_one($db, "SELECT * FROM master_units WHERE id = ? LIMIT 1", [as_int($params['id'])]); if (!$unit) fail('Satuan tidak ditemukan.', 404); $name = trim((string) ($unit['name'] ?? '')); if ((int) db_value($db, "SELECT COUNT(*) FROM inventory_items WHERE unit = ?", [$name]) > 0) fail('Satuan tidak bisa dihapus karena masih dipakai oleh barang.', 422, ['name' => ['Satuan masih dipakai oleh barang.']]); $db->prepare("DELETE FROM master_units WHERE id = ?")->execute([as_int($params['id'])]); respond(['message' => "Satuan {$name} berhasil dihapus."]); }
    if ($method === 'POST' && $path === 'inventory/purchases') {
        $payload = json_input();
        require_fields($payload, ['purchaseDate', 'supplier', 'paymentAccount']);
        assert_open_transaction_date($db, trim((string) $payload['purchaseDate']), 'Purchase date');

        $items = [];
        if (isset($payload['items']) && is_array($payload['items'])) {
            foreach ($payload['items'] as $entry) {
                $itemId = as_int($entry['itemId'] ?? 0);
                $quantity = as_int($entry['quantity'] ?? 0);
                $unitCostValue = as_float($entry['unitCostValue'] ?? 0);
                if ($itemId <= 0 || $quantity <= 0 || $unitCostValue <= 0) continue;
                $items[] = [
                    'itemId' => $itemId,
                    'quantity' => $quantity,
                    'unitCostValue' => $unitCostValue,
                    'note' => trim((string) ($entry['note'] ?? '')),
                ];
            }
        } else {
            require_fields($payload, ['itemId', 'quantity', 'unitCostValue']);
            $items[] = [
                'itemId' => as_int($payload['itemId']),
                'quantity' => as_int($payload['quantity']),
                'unitCostValue' => as_float($payload['unitCostValue']),
                'note' => trim((string) ($payload['note'] ?? '')),
            ];
        }

        if ($items === []) fail('Minimal satu detail barang wajib diisi.', 422, ['items' => ['Minimal satu detail barang wajib diisi.']]);

        $db->beginTransaction();
        try {
            $insert = $db->prepare("INSERT INTO inventory_movements (item_id, movement_date, movement_type, qty_in, qty_out, unit_cost, reference_type, reference_id, notes, created_at, updated_at) VALUES (?, ?, 'purchase', ?, 0, ?, 'purchase', ?, ?, ?, ?)");
            $updateItem = $db->prepare("UPDATE inventory_items SET standard_cost=?, updated_at=? WHERE id=?");
            foreach ($items as $entry) {
                $referenceId = random_int(100000, 999999);
                $insert->execute([
                    $entry['itemId'],
                    $payload['purchaseDate'] . ' 00:00:00',
                    $entry['quantity'],
                    $entry['unitCostValue'],
                    $referenceId,
                    json_encode([
                        'supplier' => trim((string) $payload['supplier']),
                        'paymentAccount' => trim((string) ($payload['paymentAccount'] ?? '')),
                        'note' => $entry['note'] !== '' ? $entry['note'] : trim((string) ($payload['note'] ?? '')),
                    ], JSON_UNESCAPED_UNICODE),
                    now_ts(),
                    now_ts(),
                ]);
                $movementId = (int) $db->lastInsertId();
                $updateItem->execute([$entry['unitCostValue'], now_ts(), $entry['itemId']]);
                inventory_post_purchase_accounting($db, $movementId);
            }
            $db->commit();
            respond(['message' => 'Pembelian inventory berhasil diposting.'], 201);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
    if ($method === 'POST' && ($params = match_route('inventory/purchases/{id}/cancel', $path)) !== null) { $payload = json_input(); $movement = db_one($db, "SELECT * FROM inventory_movements WHERE id = ? AND movement_type = 'purchase' LIMIT 1", [as_int($params['id'])]); if (!$movement) fail('Pembelian inventory tidak ditemukan.', 404); assert_open_transaction_date($db, current_business_date($db), 'Return date'); $meta = json_decode((string) ($movement['notes'] ?? ''), true); $meta = is_array($meta) ? $meta : []; $returned = as_float(db_value($db, "SELECT COALESCE(SUM(qty_out),0) FROM inventory_movements WHERE movement_type = 'return' AND reference_type = 'purchase_cancel' AND notes LIKE ?", ['%"purchaseMovementId":' . as_int($movement['id']) . '%'])); $remaining = max(0, as_float($movement['qty_in']) - $returned); if ($remaining <= 0) fail('Pembelian ini sudah diretur seluruhnya.', 422); $purchased = as_float(db_value($db, "SELECT COALESCE(SUM(qty_in),0) FROM inventory_movements WHERE item_id=?", [$movement['item_id']])); $issued = as_float(db_value($db, "SELECT COALESCE(SUM(qty_out),0) FROM inventory_movements WHERE item_id=?", [$movement['item_id']])); $onHand = $purchased - $issued; if ($onHand < $remaining) fail('Pembelian tidak bisa diretur karena sebagian barang sudah dipindahkan ke kamar. Kembalikan dulu barang dari kamar sebelum retur pembelian.', 422); $db->beginTransaction(); try { $businessDate = current_business_date($db); $db->prepare("INSERT INTO inventory_movements (item_id, movement_date, movement_type, qty_in, qty_out, unit_cost, reference_type, reference_id, notes, created_at, updated_at) VALUES (?, ?, 'return', 0, ?, ?, 'purchase_cancel', ?, ?, ?, ?)")->execute([$movement['item_id'], $businessDate . ' 00:00:00', $remaining, as_float($movement['unit_cost']), as_int($movement['id']), json_encode(['purchaseMovementId' => as_int($movement['id']), 'supplier' => trim((string) ($meta['supplier'] ?? 'Supplier')), 'paymentAccount' => trim((string) ($meta['paymentAccount'] ?? '')), 'note' => trim((string) ($payload['note'] ?? 'Retur pembelian'))], JSON_UNESCAPED_UNICODE), now_ts(), now_ts()]); $returnId = (int) $db->lastInsertId(); inventory_post_purchase_return_accounting($db, $returnId); $db->commit(); respond(['message' => 'Retur pembelian berhasil diposting.']); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }
    if ($method === 'POST' && $path === 'inventory/issues') { $payload = json_input(); require_fields($payload, ['issueDate', 'roomNo', 'itemId', 'quantity']); assert_open_transaction_date($db, trim((string) $payload['issueDate']), 'Issue date'); $item = db_one($db, "SELECT * FROM inventory_items WHERE id = ?", [as_int($payload['itemId'])]); $room = db_one($db, "SELECT * FROM rooms WHERE room_code = ?", [trim((string) $payload['roomNo'])]); if (!$item || !$room) fail('Item inventory atau kamar tidak ditemukan.', 422); $purchased = as_float(db_value($db, "SELECT COALESCE(SUM(qty_in),0) FROM inventory_movements WHERE item_id=?", [$item['id']])); $issued = as_float(db_value($db, "SELECT COALESCE(SUM(qty_out),0) FROM inventory_movements WHERE item_id=?", [$item['id']])); if (($purchased - $issued) < as_int($payload['quantity'])) fail('Stok tidak cukup untuk di-issue ke kamar.', 422); $db->beginTransaction(); try { $db->prepare("INSERT INTO inventory_movements (item_id, movement_date, movement_type, qty_in, qty_out, unit_cost, reference_type, reference_id, notes, created_at, updated_at) VALUES (?, ?, 'issue_room', 0, ?, ?, 'room_issue', ?, ?, ?, ?)")->execute([$item['id'], $payload['issueDate'] . ' 00:00:00', as_int($payload['quantity']), as_float($item['standard_cost']), trim((string) $room['room_code']), json_encode(['note' => trim((string) ($payload['note'] ?? ''))], JSON_UNESCAPED_UNICODE), now_ts(), now_ts()]); $movementId = (int) $db->lastInsertId(); inventory_post_issue_accounting($db, $movementId); $db->commit(); respond(['message' => "Item berhasil issue ke kamar {$room['room_code']}."], 201); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }
    if ($method === 'POST' && ($params = match_route('inventory/issues/{id}/return', $path)) !== null) { $payload = json_input(); $movement = db_one($db, "SELECT * FROM inventory_movements WHERE id = ? AND movement_type = 'issue_room' LIMIT 1", [as_int($params['id'])]); if (!$movement) fail('Distribusi item ke kamar tidak ditemukan.', 404); assert_open_transaction_date($db, current_business_date($db), 'Return date'); $returned = as_float(db_value($db, "SELECT COALESCE(SUM(qty_in),0) FROM inventory_movements WHERE movement_type = 'return' AND reference_type = 'room_return' AND notes LIKE ?", ['%"issueMovementId":' . as_int($movement['id']) . '%'])); $remaining = max(0, as_float($movement['qty_out']) - $returned); if ($remaining <= 0) fail('Item dari kamar ini sudah dikembalikan seluruhnya.', 422); $db->beginTransaction(); try { $businessDate = current_business_date($db); $db->prepare("INSERT INTO inventory_movements (item_id, movement_date, movement_type, qty_in, qty_out, unit_cost, reference_type, reference_id, notes, created_at, updated_at) VALUES (?, ?, 'return', ?, 0, ?, 'room_return', ?, ?, ?, ?)")->execute([$movement['item_id'], $businessDate . ' 00:00:00', $remaining, as_float($movement['unit_cost']), trim((string) $movement['reference_id']), json_encode(['issueMovementId' => as_int($movement['id']), 'note' => trim((string) ($payload['note'] ?? 'Barang dikembalikan dari kamar'))], JSON_UNESCAPED_UNICODE), now_ts(), now_ts()]); $returnId = (int) $db->lastInsertId(); inventory_post_issue_return_accounting($db, $returnId); $db->commit(); respond(['message' => 'Barang berhasil dikembalikan dari kamar ke stok.']); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }

    if ($method === 'GET' && $path === 'transport-rates') { $rows = db_all($db, "SELECT t.*, COALESCE(v.vendor_name, '') AS vendor_name FROM transport_rates t LEFT JOIN vendors v ON v.id = t.vendor_id ORDER BY t.id DESC"); respond(['data' => array_map(static function (array $row): array { $vendorPickupPriceValue = as_float($row['vendor_pickup_price_value'] ?? $row['pickup_price_value']); $vendorDropOffPriceValue = as_float($row['vendor_drop_off_price_value'] ?? $row['drop_off_price_value']); $customerPickupPriceValue = as_float($row['customer_pickup_price_value'] ?? $row['pickup_price_value']); $customerDropOffPriceValue = as_float($row['customer_drop_off_price_value'] ?? $row['drop_off_price_value']); $feeCoaCode = (string) ($row['fee_coa_code'] ?? $row['expense_coa_code'] ?? ''); return ['id' => 'TRF-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'driver' => $row['driver'], 'vendorPickupPriceValue' => $vendorPickupPriceValue, 'vendorPickupPrice' => money($vendorPickupPriceValue), 'vendorDropOffPriceValue' => $vendorDropOffPriceValue, 'vendorDropOffPrice' => money($vendorDropOffPriceValue), 'customerPickupPriceValue' => $customerPickupPriceValue, 'customerPickupPrice' => money($customerPickupPriceValue), 'customerDropOffPriceValue' => $customerDropOffPriceValue, 'customerDropOffPrice' => money($customerDropOffPriceValue), 'pickupPriceValue' => $customerPickupPriceValue, 'pickupPrice' => money($customerPickupPriceValue), 'dropOffPriceValue' => $customerDropOffPriceValue, 'dropOffPrice' => money($customerDropOffPriceValue), 'vehicle' => $row['vehicle'], 'vendorId' => as_int($row['vendor_id'] ?? 0), 'vendor' => (string) ($row['vendor_name'] ?? ''), 'feeCoaCode' => $feeCoaCode, 'expenseCoaCode' => $feeCoaCode, 'payableCoaCode' => (string) ($row['payable_coa_code'] ?? ''), 'note' => $row['note']]; }, $rows)]); }
    if (($method === 'POST' && $path === 'transport-rates') || ($method === 'PUT' && ($params = match_route('transport-rates/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['driver', 'vendorPickupPriceValue', 'vendorDropOffPriceValue', 'customerPickupPriceValue', 'customerDropOffPriceValue', 'payableCoaCode', 'feeCoaCode']); $vendorId = as_int($payload['vendorId'] ?? 0); if ($vendorId > 0) resolve_activity_vendor($db, $vendorId, 'transport'); $feeCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['feeCoaCode'] ?? $payload['expenseCoaCode'] ?? '')), 'revenue', 'Fee COA'); $payableCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['payableCoaCode'] ?? '')), 'liability', 'Hutang COA'); $vendorPickupPriceValue = as_float($payload['vendorPickupPriceValue']); $vendorDropOffPriceValue = as_float($payload['vendorDropOffPriceValue']); $customerPickupPriceValue = as_float($payload['customerPickupPriceValue']); $customerDropOffPriceValue = as_float($payload['customerDropOffPriceValue']); if ($customerPickupPriceValue < $vendorPickupPriceValue) fail('Harga customer pickup tidak boleh lebih kecil dari harga vendor pickup.', 422, ['customerPickupPriceValue' => ['Harga customer pickup tidak boleh lebih kecil dari harga vendor pickup.']]); if ($customerDropOffPriceValue < $vendorDropOffPriceValue) fail('Harga customer drop off tidak boleh lebih kecil dari harga vendor drop off.', 422, ['customerDropOffPriceValue' => ['Harga customer drop off tidak boleh lebih kecil dari harga vendor drop off.']]); if ($method === 'POST') { $db->prepare("INSERT INTO transport_rates (driver, pickup_price_value, drop_off_price_value, vendor_pickup_price_value, vendor_drop_off_price_value, customer_pickup_price_value, customer_drop_off_price_value, vehicle, vendor_id, fee_coa_code, payable_coa_code, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([trim((string) $payload['driver']), $customerPickupPriceValue, $customerDropOffPriceValue, $vendorPickupPriceValue, $vendorDropOffPriceValue, $customerPickupPriceValue, $customerDropOffPriceValue, trim((string) ($payload['vehicle'] ?? '')) ?: null, $vendorId ?: null, $feeCoaCode ?: null, $payableCoaCode ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]); respond(['message' => "Driver {$payload['driver']} berhasil ditambahkan."], 201); } $db->prepare("UPDATE transport_rates SET driver=?, pickup_price_value=?, drop_off_price_value=?, vendor_pickup_price_value=?, vendor_drop_off_price_value=?, customer_pickup_price_value=?, customer_drop_off_price_value=?, vehicle=?, vendor_id=?, fee_coa_code=?, payable_coa_code=?, note=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['driver']), $customerPickupPriceValue, $customerDropOffPriceValue, $vendorPickupPriceValue, $vendorDropOffPriceValue, $customerPickupPriceValue, $customerDropOffPriceValue, trim((string) ($payload['vehicle'] ?? '')) ?: null, $vendorId ?: null, $feeCoaCode ?: null, $payableCoaCode ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), as_int($params['id'])]); respond(['message' => "Driver {$payload['driver']} berhasil diperbarui."]); }

    if ($method === 'GET' && $path === 'activity-catalog') { $scooters = db_all($db, "SELECT s.*, COALESCE(v.vendor_name, s.vendor) AS vendor_name FROM scooter_catalog s LEFT JOIN vendors v ON v.id = s.vendor_id ORDER BY s.id DESC"); $operators = db_all($db, "SELECT o.*, COALESCE(v.vendor_name, o.vendor) AS vendor_name FROM activity_operator_catalog o LEFT JOIN vendors v ON v.id = o.vendor_id ORDER BY o.id DESC"); $tours = db_all($db, "SELECT i.*, COALESCE(v.vendor_name, i.vendor) AS vendor_name FROM island_tour_catalog i LEFT JOIN vendors v ON v.id = i.vendor_id ORDER BY i.id DESC"); $boats = db_all($db, "SELECT b.*, COALESCE(v.vendor_name, b.vendor) AS vendor_name FROM boat_ticket_catalog b LEFT JOIN vendors v ON v.id = b.vendor_id ORDER BY b.id DESC"); respond(['data' => ['scooters' => array_map(static fn (array $row): array => ['id' => 'SCT-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'vendorId' => as_int($row['vendor_id'] ?? 0), 'startDate' => $row['start_date'], 'endDate' => $row['end_date'], 'scooterType' => $row['scooter_type'], 'vendor' => (string) ($row['vendor_name'] ?? ''), 'customerPriceValue' => as_float($row['customer_price_value'] ?? $row['price_value']), 'customerPrice' => money(as_float($row['customer_price_value'] ?? $row['price_value'])), 'vendorPriceValue' => as_float($row['vendor_price_value'] ?? $row['price_value']), 'vendorPrice' => money(as_float($row['vendor_price_value'] ?? $row['price_value'])), 'feeValue' => as_float($row['customer_price_value'] ?? $row['price_value']) - as_float($row['vendor_price_value'] ?? $row['price_value']), 'fee' => money(as_float($row['customer_price_value'] ?? $row['price_value']) - as_float($row['vendor_price_value'] ?? $row['price_value'])), 'priceValue' => as_float($row['customer_price_value'] ?? $row['price_value']), 'price' => money(as_float($row['customer_price_value'] ?? $row['price_value'])), 'payableCoaCode' => (string) ($row['payable_coa_code'] ?? ''), 'feeCoaCode' => (string) ($row['fee_coa_code'] ?? ''), 'isActive' => (bool) as_int($row['is_active'] ?? 1)], $scooters), 'operators' => array_map(static fn (array $row): array => ['id' => 'OPR-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'vendorId' => as_int($row['vendor_id'] ?? 0), 'operator' => $row['operator'], 'vendor' => (string) ($row['vendor_name'] ?? ''), 'customerPriceValue' => as_float($row['customer_price_value'] ?? $row['price_value']), 'customerPrice' => money(as_float($row['customer_price_value'] ?? $row['price_value'])), 'vendorPriceValue' => as_float($row['vendor_price_value'] ?? $row['price_value']), 'vendorPrice' => money(as_float($row['vendor_price_value'] ?? $row['price_value'])), 'feeValue' => as_float($row['customer_price_value'] ?? $row['price_value']) - as_float($row['vendor_price_value'] ?? $row['price_value']), 'fee' => money(as_float($row['customer_price_value'] ?? $row['price_value']) - as_float($row['vendor_price_value'] ?? $row['price_value'])), 'priceValue' => as_float($row['customer_price_value'] ?? $row['price_value']), 'price' => money(as_float($row['customer_price_value'] ?? $row['price_value'])), 'payableCoaCode' => (string) ($row['payable_coa_code'] ?? ''), 'feeCoaCode' => (string) ($row['fee_coa_code'] ?? ''), 'note' => $row['note'], 'isActive' => (bool) as_int($row['is_active'] ?? 1)], $operators), 'islandTours' => array_map(static fn (array $row): array => ['id' => 'TOUR-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'vendorId' => as_int($row['vendor_id'] ?? 0), 'destination' => $row['destination'], 'vendor' => (string) ($row['vendor_name'] ?? ''), 'driver' => $row['driver'], 'customerPriceValue' => as_float($row['customer_price_value'] ?? $row['cost_value']), 'customerPrice' => money(as_float($row['customer_price_value'] ?? $row['cost_value'])), 'vendorPriceValue' => as_float($row['vendor_price_value'] ?? $row['cost_value']), 'vendorPrice' => money(as_float($row['vendor_price_value'] ?? $row['cost_value'])), 'feeValue' => as_float($row['customer_price_value'] ?? $row['cost_value']) - as_float($row['vendor_price_value'] ?? $row['cost_value']), 'fee' => money(as_float($row['customer_price_value'] ?? $row['cost_value']) - as_float($row['vendor_price_value'] ?? $row['cost_value'])), 'costValue' => as_float($row['customer_price_value'] ?? $row['cost_value']), 'cost' => money(as_float($row['customer_price_value'] ?? $row['cost_value'])), 'payableCoaCode' => (string) ($row['payable_coa_code'] ?? ''), 'feeCoaCode' => (string) ($row['fee_coa_code'] ?? ''), 'note' => $row['note'], 'isActive' => (bool) as_int($row['is_active'] ?? 1)], $tours), 'boatTickets' => array_map(static fn (array $row): array => ['id' => 'BOT-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'vendorId' => as_int($row['vendor_id'] ?? 0), 'company' => $row['company'], 'vendor' => (string) ($row['vendor_name'] ?? ''), 'destination' => $row['destination'], 'customerPriceValue' => as_float($row['customer_price_value'] ?? $row['price_value']), 'customerPrice' => money(as_float($row['customer_price_value'] ?? $row['price_value'])), 'vendorPriceValue' => as_float($row['vendor_price_value'] ?? $row['price_value']), 'vendorPrice' => money(as_float($row['vendor_price_value'] ?? $row['price_value'])), 'feeValue' => as_float($row['customer_price_value'] ?? $row['price_value']) - as_float($row['vendor_price_value'] ?? $row['price_value']), 'fee' => money(as_float($row['customer_price_value'] ?? $row['price_value']) - as_float($row['vendor_price_value'] ?? $row['price_value'])), 'priceValue' => as_float($row['customer_price_value'] ?? $row['price_value']), 'price' => money(as_float($row['customer_price_value'] ?? $row['price_value'])), 'payableCoaCode' => (string) ($row['payable_coa_code'] ?? ''), 'feeCoaCode' => (string) ($row['fee_coa_code'] ?? ''), 'isActive' => (bool) as_int($row['is_active'] ?? 1)], $boats)]]); }
    if (($method === 'POST' && $path === 'activity-catalog/scooters') || ($method === 'PUT' && ($params = match_route('activity-catalog/scooters/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['scooterType', 'vendorId', 'vendorPriceValue', 'customerPriceValue', 'payableCoaCode', 'feeCoaCode']); $vendor = resolve_activity_vendor($db, $payload['vendorId'] ?? 0, 'scooter', (string) ($payload['vendor'] ?? '')); $startDate = trim((string) ($payload['startDate'] ?? '')) ?: null; $endDate = trim((string) ($payload['endDate'] ?? '')) ?: null; $payableCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['payableCoaCode'] ?? '')), 'liability', 'Hutang COA'); $feeCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['feeCoaCode'] ?? '')), 'revenue', 'Fee COA'); $vendorPriceValue = as_float($payload['vendorPriceValue']); $customerPriceValue = as_float($payload['customerPriceValue']); if ($customerPriceValue < $vendorPriceValue) fail('Harga customer tidak boleh lebih kecil dari harga vendor.', 422, ['customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.']]); if ($method === 'POST') { $db->prepare("INSERT INTO scooter_catalog (start_date, end_date, scooter_type, vendor, vendor_id, price_value, vendor_price_value, customer_price_value, payable_coa_code, fee_coa_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$startDate, $endDate, trim((string) $payload['scooterType']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, now_ts(), now_ts()]); respond(['message' => 'Data scooter berhasil ditambahkan.'], 201); } $db->prepare("UPDATE scooter_catalog SET start_date=?, end_date=?, scooter_type=?, vendor=?, vendor_id=?, price_value=?, vendor_price_value=?, customer_price_value=?, payable_coa_code=?, fee_coa_code=?, updated_at=? WHERE id=?")->execute([$startDate, $endDate, trim((string) $payload['scooterType']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, now_ts(), as_int($params['id'])]); respond(['message' => 'Data scooter berhasil diperbarui.']); }
    if ($method === 'PATCH' && ($params = match_route('activity-catalog/scooters/{id}/toggle', $path)) !== null) { $payload = json_input(); set_activity_catalog_entry_active($db, 'scooter', as_int($params['id']), !empty($payload['isActive'])); respond(['message' => !empty($payload['isActive']) ? 'Data scooter berhasil diaktifkan.' : 'Data scooter berhasil dinonaktifkan.']); }
    if (($method === 'POST' && $path === 'activity-catalog/operators') || ($method === 'PUT' && ($params = match_route('activity-catalog/operators/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['operator', 'vendorId', 'vendorPriceValue', 'customerPriceValue', 'payableCoaCode', 'feeCoaCode']); $vendor = resolve_activity_vendor($db, $payload['vendorId'] ?? 0, 'operator', (string) ($payload['vendor'] ?? '')); $payableCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['payableCoaCode'] ?? '')), 'liability', 'Hutang COA'); $feeCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['feeCoaCode'] ?? '')), 'revenue', 'Fee COA'); $vendorPriceValue = as_float($payload['vendorPriceValue']); $customerPriceValue = as_float($payload['customerPriceValue']); if ($customerPriceValue < $vendorPriceValue) fail('Harga customer tidak boleh lebih kecil dari harga vendor.', 422, ['customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.']]); if ($method === 'POST') { $db->prepare("INSERT INTO activity_operator_catalog (operator, vendor, vendor_id, price_value, vendor_price_value, customer_price_value, payable_coa_code, fee_coa_code, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([trim((string) $payload['operator']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]); respond(['message' => 'Data operator berhasil ditambahkan.'], 201); } $db->prepare("UPDATE activity_operator_catalog SET operator=?, vendor=?, vendor_id=?, price_value=?, vendor_price_value=?, customer_price_value=?, payable_coa_code=?, fee_coa_code=?, note=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['operator']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), as_int($params['id'])]); respond(['message' => 'Data operator berhasil diperbarui.']); }
    if ($method === 'PATCH' && ($params = match_route('activity-catalog/operators/{id}/toggle', $path)) !== null) { $payload = json_input(); set_activity_catalog_entry_active($db, 'operator', as_int($params['id']), !empty($payload['isActive'])); respond(['message' => !empty($payload['isActive']) ? 'Data operator berhasil diaktifkan.' : 'Data operator berhasil dinonaktifkan.']); }
    if (($method === 'POST' && $path === 'activity-catalog/island-tours') || ($method === 'PUT' && ($params = match_route('activity-catalog/island-tours/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['destination', 'vendorId', 'driver', 'vendorPriceValue', 'customerPriceValue', 'payableCoaCode', 'feeCoaCode']); $vendor = resolve_activity_vendor($db, $payload['vendorId'] ?? 0, 'island_tour', (string) ($payload['vendor'] ?? '')); $payableCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['payableCoaCode'] ?? '')), 'liability', 'Hutang COA'); $feeCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['feeCoaCode'] ?? '')), 'revenue', 'Fee COA'); $vendorPriceValue = as_float($payload['vendorPriceValue']); $customerPriceValue = as_float($payload['customerPriceValue']); if ($customerPriceValue < $vendorPriceValue) fail('Harga customer tidak boleh lebih kecil dari harga vendor.', 422, ['customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.']]); if ($method === 'POST') { $db->prepare("INSERT INTO island_tour_catalog (destination, vendor, vendor_id, driver, cost_value, vendor_price_value, customer_price_value, payable_coa_code, fee_coa_code, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([trim((string) $payload['destination']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), trim((string) $payload['driver']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]); respond(['message' => 'Data island tour berhasil ditambahkan.'], 201); } $db->prepare("UPDATE island_tour_catalog SET destination=?, vendor=?, vendor_id=?, driver=?, cost_value=?, vendor_price_value=?, customer_price_value=?, payable_coa_code=?, fee_coa_code=?, note=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['destination']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), trim((string) $payload['driver']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), as_int($params['id'])]); respond(['message' => 'Data island tour berhasil diperbarui.']); }
    if ($method === 'PATCH' && ($params = match_route('activity-catalog/island-tours/{id}/toggle', $path)) !== null) { $payload = json_input(); set_activity_catalog_entry_active($db, 'island_tour', as_int($params['id']), !empty($payload['isActive'])); respond(['message' => !empty($payload['isActive']) ? 'Data island tour berhasil diaktifkan.' : 'Data island tour berhasil dinonaktifkan.']); }
    if (($method === 'POST' && $path === 'activity-catalog/boat-tickets') || ($method === 'PUT' && ($params = match_route('activity-catalog/boat-tickets/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['company', 'vendorId', 'destination', 'vendorPriceValue', 'customerPriceValue', 'payableCoaCode', 'feeCoaCode']); $vendor = resolve_activity_vendor($db, $payload['vendorId'] ?? 0, 'boat_ticket', (string) ($payload['vendor'] ?? '')); $payableCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['payableCoaCode'] ?? '')), 'liability', 'Hutang COA'); $feeCoaCode = validate_coa_category($db, coa_code_only((string) ($payload['feeCoaCode'] ?? '')), 'revenue', 'Fee COA'); $vendorPriceValue = as_float($payload['vendorPriceValue']); $customerPriceValue = as_float($payload['customerPriceValue']); if ($customerPriceValue < $vendorPriceValue) fail('Harga customer tidak boleh lebih kecil dari harga vendor.', 422, ['customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.']]); if ($method === 'POST') { $db->prepare("INSERT INTO boat_ticket_catalog (company, vendor, vendor_id, destination, price_value, vendor_price_value, customer_price_value, payable_coa_code, fee_coa_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([trim((string) $payload['company']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), trim((string) $payload['destination']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, now_ts(), now_ts()]); respond(['message' => 'Data boat ticket berhasil ditambahkan.'], 201); } $db->prepare("UPDATE boat_ticket_catalog SET company=?, vendor=?, vendor_id=?, destination=?, price_value=?, vendor_price_value=?, customer_price_value=?, payable_coa_code=?, fee_coa_code=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['company']), trim((string) $vendor['vendor_name']), as_int($vendor['id']), trim((string) $payload['destination']), $customerPriceValue, $vendorPriceValue, $customerPriceValue, $payableCoaCode ?: null, $feeCoaCode ?: null, now_ts(), as_int($params['id'])]); respond(['message' => 'Data boat ticket berhasil diperbarui.']); }
    if ($method === 'PATCH' && ($params = match_route('activity-catalog/boat-tickets/{id}/toggle', $path)) !== null) { $payload = json_input(); set_activity_catalog_entry_active($db, 'boat_ticket', as_int($params['id']), !empty($payload['isActive'])); respond(['message' => !empty($payload['isActive']) ? 'Data boat ticket berhasil diaktifkan.' : 'Data boat ticket berhasil dinonaktifkan.']); }

    if ($method === 'PUT' && $path === 'dashboard/owner/policies') { $payload = json_input(); require_fields($payload, ['cancellationPenaltyPercent']); $percent = max(0, min(100, as_float($payload['cancellationPenaltyPercent']))); upsert_hotel_setting($db, 'booking_cancel_penalty_percent', $percent); respond(['message' => 'Kebijakan penalti cancel berhasil diperbarui.', 'data' => ['cancellationPolicy' => cancellation_policy_payload($db)]]); }
    if ($method === 'GET' && $path === 'settings/policies') { respond(['data' => ['cancellationPolicy' => cancellation_policy_payload($db)]]); }
    if ($method === 'PUT' && $path === 'settings/policies') { $payload = json_input(); require_fields($payload, ['cancellationPenaltyPercent']); $percent = max(0, min(100, as_float($payload['cancellationPenaltyPercent']))); upsert_hotel_setting($db, 'booking_cancel_penalty_percent', $percent); respond(['message' => 'Booking policy settings updated successfully.', 'data' => ['cancellationPolicy' => cancellation_policy_payload($db)]]); }
    if ($method === 'POST' && $path === 'settings/reset-transactions') { $payload = json_input(); require_fields($payload, ['confirmation']); if (trim((string) $payload['confirmation']) !== 'RESET') fail('Ketik RESET terlebih dahulu untuk menghapus semua transaksi.', 422, ['confirmation' => ['Ketik RESET terlebih dahulu untuk menghapus semua transaksi.']]); $db->beginTransaction(); try { foreach (['vendor_payment_allocations', 'vendor_payments', 'vendor_bills', 'payment_allocations', 'payments', 'journal_lines', 'journals', 'booking_addons', 'booking_rooms', 'invoices', 'bookings', 'guests', 'inventory_movements', 'housekeeping_tasks', 'night_audit_runs', 'audit_trails'] as $table) { if ((int) db_value($db, "SELECT COUNT(*) FROM information_schema.tables WHERE BINARY table_schema = BINARY DATABASE() AND BINARY table_name = BINARY ?", [$table]) > 0) $db->exec("DELETE FROM `{$table}`"); } $db->exec("UPDATE rooms SET status='available' WHERE status IN ('occupied','dirty','cleaning')"); $db->commit(); $db->prepare("INSERT INTO audit_trails (user_id, user_name, user_email, user_role, module, action, entity_type, entity_id, entity_label, description, metadata, ip_address, user_agent, created_at, updated_at) VALUES (?, ?, ?, ?, 'settings', 'transactions_reset', 'system', 'transactions', 'All Transactions', ?, ?, ?, ?, ?, ?)")->execute([as_int($actor['id'] ?? 0) ?: null, trim((string) ($actor['name'] ?? '')) ?: null, trim((string) ($actor['email'] ?? '')) ?: null, trim((string) ($actor['role'] ?? '')) ?: null, 'Semua transaksi operasional direset dari halaman Settings.', json_encode(['confirmation' => 'RESET'], JSON_UNESCAPED_UNICODE), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null, now_ts(), now_ts()]); respond(['message' => 'Semua transaksi operasional berhasil direset. Master data tetap dipertahankan.']); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }
    if ($method === 'GET' && $path === 'dashboard/owner') { $today = current_business_date($db); $period = trim((string) q('period', 'today')) ?: 'today'; $range = dashboard_period_range($db, $period, trim((string) q('start_date', '')) ?: null, trim((string) q('end_date', '')) ?: null); $fromDate = (string) $range['from']; $toDate = (string) $range['to']; if ($fromDate > $toDate) { [$fromDate, $toDate] = [$toDate, $fromDate]; } $roomsTotal = (int) db_value($db, "SELECT COUNT(*) FROM rooms"); $availableRooms = (int) db_value($db, "SELECT COUNT(*) FROM rooms WHERE status='available'"); $inHouse = db_all($db, "SELECT * FROM bookings WHERE status='checked_in' AND DATE(check_in_at) <= ? AND DATE(check_out_at) > ?", [$today, $today]); $roomRevenue = as_float(db_value($db, "SELECT COALESCE(SUM(room_amount),0) FROM bookings WHERE status NOT IN ('cancelled', 'no_show') AND DATE(check_in_at) BETWEEN ? AND ?", [$fromDate, $toDate])); $addonRevenue = booking_addon_fee_total_in_range($db, $fromDate, $toDate); $openFolios = db_all($db, "SELECT * FROM invoices WHERE balance_due > 0 ORDER BY balance_due DESC LIMIT 6"); $outstanding = array_sum(array_map(static fn (array $row): float => as_float($row['balance_due']), $openFolios)); $adrBaseCount = (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status NOT IN ('cancelled', 'no_show') AND DATE(check_in_at) BETWEEN ? AND ?", [$fromDate, $toDate]); $adr = $adrBaseCount > 0 ? $roomRevenue / $adrBaseCount : 0; $paymentSummary = daily_payment_summary($db, $today); $ownerFinancials = owner_daily_financial_snapshot($db, $today); $annualRevenueSeries = owner_annual_revenue_series($db, $today); $rangeLabel = $fromDate === $toDate ? date('d M Y', strtotime($fromDate)) : date('d M Y', strtotime($fromDate)) . ' - ' . date('d M Y', strtotime($toDate)); respond(['data' => ['period' => $period, 'periodLabel' => (string) $range['label'], 'rangeLabel' => $rangeLabel, 'businessDate' => $today, 'currentDateLabel' => build_business_date_label($today), 'generatedAt' => date('d M Y H:i'), 'cancellationPolicy' => cancellation_policy_payload($db), 'closingSummary' => $paymentSummary, 'ownerFinancials' => [['label' => 'Pendapatan hari ini', 'value' => $ownerFinancials['todayRevenue'], 'note' => 'Jurnal revenue pada tanggal bisnis aktif'], ['label' => 'Beban hari ini', 'value' => $ownerFinancials['todayExpense'], 'note' => 'Jurnal expense pada tanggal bisnis aktif'], ['label' => 'Hutang outstanding', 'value' => $ownerFinancials['outstandingPayables'], 'note' => 'Saldo vendor bills yang belum lunas'], ['label' => 'Jatuh tempo hari ini', 'value' => $ownerFinancials['dueTodayPayables'], 'note' => 'Hutang vendor yang harus dibayar hari ini'], ['label' => 'Hutang overdue', 'value' => $ownerFinancials['overduePayables'], 'note' => 'Hutang vendor yang sudah lewat jatuh tempo'], ['label' => 'Bayar vendor hari ini', 'value' => $ownerFinancials['paidTodayToVendors'], 'note' => 'Pembayaran vendor yang diposting hari ini']], 'overview' => [['label' => 'Occupancy', 'value' => ($roomsTotal > 0 ? (int) round((count($inHouse) / $roomsTotal) * 100) : 0) . '%', 'note' => count($inHouse) . " room occupied | {$availableRooms} sellable room"], ['label' => 'Arrivals', 'value' => (string) (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_in_at) BETWEEN ? AND ?", [$fromDate, $toDate]), 'note' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_out_at) BETWEEN ? AND ?", [$fromDate, $toDate]) . ' departure in period'], ['label' => 'Outstanding', 'value' => money($outstanding), 'note' => count($openFolios) . ' folio still open'], ['label' => 'ADR', 'value' => money($adr), 'note' => 'Room revenue ' . money($roomRevenue)]], 'dailyControl' => [['label' => 'Arrival in range', 'value' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_in_at) BETWEEN ? AND ?", [$fromDate, $toDate])], ['label' => 'Departure in range', 'value' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_out_at) BETWEEN ? AND ?", [$fromDate, $toDate])], ['label' => 'Net cash closing', 'value' => $paymentSummary['netCollectionsLabel']], ['label' => 'Cash on hand', 'value' => $paymentSummary['cashLabel']], ['label' => 'Net daily result', 'value' => $ownerFinancials['todayNet']], ['label' => 'Room still sellable', 'value' => $availableRooms]], 'revenueMix' => [['label' => 'Room revenue', 'value' => money($roomRevenue), 'progress' => 100], ['label' => 'Add-on fee revenue', 'value' => money($addonRevenue), 'progress' => 100], ['label' => 'Pendapatan periode (room + fee add-on)', 'value' => money($roomRevenue + $addonRevenue), 'progress' => 100]], 'arrivalWatch' => [], 'cashierQueue' => [['guest' => 'Gross collection', 'folio' => $paymentSummary['grossCollectionsLabel'], 'due' => 'Refund/Void ' . $paymentSummary['refundsVoidsLabel']], ['guest' => 'Cash', 'folio' => $paymentSummary['cashLabel'], 'due' => 'Transfer ' . $paymentSummary['bankTransferLabel']], ['guest' => 'Card', 'folio' => $paymentSummary['cardLabel'], 'due' => 'QRIS ' . $paymentSummary['qrisLabel']]], 'channelPerformance' => [], 'roomTypePerformance' => [], 'liveMovement' => [], 'departmentNotes' => [], 'annualRevenueSeries' => $annualRevenueSeries]]); }
    if ($method === 'GET' && $path === 'night-audit/status') { $today = current_business_date($db); $lastRun = db_one($db, "SELECT * FROM night_audit_runs ORDER BY business_date DESC LIMIT 1"); $paymentSummary = daily_payment_summary($db, $today); respond(['pending_checkouts' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND DATE(check_out_at) <= ?", [$today]), 'unresolved_arrivals' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','draft') AND DATE(check_in_at) <= ?", [$today]), 'active_in_house' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND check_in_at <= ? AND check_out_at > ?", [$today . ' 23:59:59', $today . ' 00:00:00']), 'audit_date' => date('d M Y', strtotime($today)), 'business_date' => $today, 'next_business_date' => next_business_date($today), 'business_date_label' => build_business_date_label($today), 'next_business_date_label' => build_business_date_label(next_business_date($today)), 'last_closed_date' => last_closed_business_date($db), 'closing_summary' => $paymentSummary, 'last_run' => $lastRun ? ['businessDate' => $lastRun['business_date'], 'nextBusinessDate' => $lastRun['next_business_date'], 'foliosProcessed' => as_int($lastRun['folios_processed']), 'closedBy' => $lastRun['closed_by_name'] ?: 'System', 'closedAt' => $lastRun['created_at'], 'closingAmount' => (json_decode((string) ($lastRun['summary_json'] ?? ''), true)['closingSummary']['netCollectionsLabel'] ?? null)] : null]); }
    if ($method === 'GET' && $path === 'night-audit/history') { $rows = db_all($db, "SELECT * FROM night_audit_runs ORDER BY business_date DESC, id DESC LIMIT 20"); respond(['data' => array_map(static function (array $row): array { $summary = json_decode((string) ($row['summary_json'] ?? ''), true); $summary = is_array($summary) ? $summary : []; $closingSummary = is_array($summary['closingSummary'] ?? null) ? $summary['closingSummary'] : []; return ['id' => as_int($row['id']), 'businessDate' => $row['business_date'], 'nextBusinessDate' => $row['next_business_date'], 'pendingCheckouts' => as_int($row['pending_checkouts']), 'unresolvedArrivals' => as_int($row['unresolved_arrivals']), 'activeInHouse' => as_int($row['active_in_house']), 'foliosProcessed' => as_int($row['folios_processed']), 'closingAmount' => $closingSummary['netCollectionsLabel'] ?? money(0), 'cashAmount' => $closingSummary['cashLabel'] ?? money(0), 'closedBy' => $row['closed_by_name'] ?: 'System', 'closedAt' => $row['created_at']]; }, $rows)]); }
    if ($method === 'POST' && $path === 'night-audit') { $today = current_business_date($db); if (db_one($db, "SELECT id FROM night_audit_runs WHERE business_date = ? LIMIT 1", [$today])) fail("Business date {$today} sudah pernah ditutup.", 422, ['businessDate' => ['Tanggal bisnis ini sudah pernah di-close.']]); $pending = (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND DATE(check_out_at) <= ?", [$today]); if ($pending > 0) respond(['message' => 'Proses Night Audit Ditangguhkan! Selesaikan Anomali Pra-Audit ini (Overstay) terlebih dahulu:', 'errors' => ["Terdapat {$pending} tamu overstay yang seharusnya Check-Out hari ini namun sistemnya belum tutup buku (Folio menggantung)."]], 422); $unresolved = (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','draft') AND DATE(check_in_at) <= ?", [$today]); $activeInHouse = (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND check_in_at <= ? AND check_out_at > ?", [$today . ' 23:59:59', $today . ' 00:00:00']); $paymentSummary = daily_payment_summary($db, $today); $candidates = db_all($db, "SELECT b.id, b.room_amount, b.discount_amount, b.status, COALESCE(i.paid_amount, 0) AS paid_amount FROM bookings b LEFT JOIN invoices i ON i.booking_id = b.id WHERE b.status IN ('confirmed','draft') AND DATE(b.check_in_at) <= ?", [$today]); $processed = 0; $db->beginTransaction(); try { foreach ($candidates as $row) { $booking = db_one($db, "SELECT * FROM bookings WHERE id = ?", [as_int($row['id'])]); if (!$booking) continue; $penalty = booking_cancellation_penalty_amount($db, $booking); if (as_float($row['paid_amount']) > $penalty) continue; $db->prepare("UPDATE bookings SET status='cancelled', updated_at=? WHERE id=?")->execute([now_ts(), $booking['id']]); sync_booking_financial_state($db, as_int($booking['id'])); $processed++; } $nextDate = next_business_date($today); $summary = ['businessDate' => $today, 'nextBusinessDate' => $nextDate, 'pendingCheckouts' => $pending, 'unresolvedArrivals' => $unresolved, 'activeInHouse' => $activeInHouse, 'foliosProcessed' => $processed, 'closingSummary' => $paymentSummary]; $db->prepare("INSERT INTO night_audit_runs (business_date, next_business_date, pending_checkouts, unresolved_arrivals, active_in_house, folios_processed, summary_json, closed_by_user_id, closed_by_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$today, $nextDate, $pending, $unresolved, $activeInHouse, $processed, json_encode($summary, JSON_UNESCAPED_UNICODE), isset($authUser['id']) ? as_int($authUser['id']) : null, trim((string) ($authUser['name'] ?? 'System')) ?: 'System', now_ts(), now_ts()]); upsert_hotel_setting($db, 'last_closed_business_date', $today); upsert_hotel_setting($db, 'current_business_date', $nextDate); $db->commit(); respond(['message' => 'Daily closing completed successfully.', 'details' => "Business date {$today} berhasil ditutup dan digeser ke {$nextDate}.", 'data' => ['folios_processed' => $processed, 'businessDate' => $today, 'nextBusinessDate' => $nextDate, 'currentDateLabel' => build_business_date_label($nextDate), 'summary' => $summary]]); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }

    if ($method === 'GET' && $path === 'roles') { $rows = db_all($db, "SELECT * FROM roles ORDER BY id ASC"); respond(array_map(static fn (array $row): array => ['id' => as_int($row['id']), 'name' => $row['name'], 'permissions' => $row['permissions'] ? (json_decode((string) $row['permissions'], true) ?: []) : []], $rows)); }
    if ($method === 'PUT' && ($params = match_route('roles/{id}/permissions', $path)) !== null) { $payload = json_input(); if (!isset($payload['permissions']) || !is_array($payload['permissions'])) fail('Permissions wajib berupa array.', 422, ['permissions' => ['Permissions wajib berupa array.']]); $db->prepare("UPDATE roles SET permissions=?, updated_at=? WHERE id=?")->execute([json_encode(array_values($payload['permissions']), JSON_UNESCAPED_UNICODE), now_ts(), as_int($params['id'])]); respond(['message' => 'Hak akses berhasil diperbarui!']); }
    if ($method === 'GET' && $path === 'users') { $rows = db_all($db, "SELECT u.id, u.name, u.username, u.email, u.is_active, u.role_id, r.name AS role FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.id DESC"); respond(array_map(static fn (array $row): array => ['id' => as_int($row['id']), 'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'], 'is_active' => (bool) as_int($row['is_active']), 'role_id' => as_int($row['role_id']), 'role' => $row['role'] ?: 'frontdesk'], $rows)); }
    if ($method === 'POST' && $path === 'users') { $payload = json_input(); require_fields($payload, ['name', 'username', 'password', 'role_id']); if (db_one($db, "SELECT id FROM users WHERE username COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci", [trim((string) $payload['username'])])) fail('Username sudah digunakan.', 422, ['username' => ['Username sudah digunakan.']]); if (trim((string) ($payload['email'] ?? '')) !== '' && db_one($db, "SELECT id FROM users WHERE email COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci", [trim((string) $payload['email'])])) fail('Email sudah digunakan.', 422, ['email' => ['Email sudah digunakan.']]); $db->prepare("INSERT INTO users (name, username, email, password, role_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)")->execute([trim((string) $payload['name']), trim((string) $payload['username']), trim((string) ($payload['email'] ?? '')) ?: null, password_hash((string) $payload['password'], PASSWORD_BCRYPT), as_int($payload['role_id']), now_ts(), now_ts()]); respond(['message' => 'Akun staf berhasil dibuat!', 'user_id' => (int) $db->lastInsertId()], 201); }
    if ($method === 'PUT' && ($params = match_route('users/{id}', $path)) !== null) { $payload = json_input(); require_fields($payload, ['name', 'username', 'role_id']); $existing = db_one($db, "SELECT id FROM users WHERE username COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND id != ?", [trim((string) $payload['username']), as_int($params['id'])]); if ($existing) fail('Username sudah digunakan.', 422, ['username' => ['Username sudah digunakan.']]); if (trim((string) ($payload['email'] ?? '')) !== '') { $existingEmail = db_one($db, "SELECT id FROM users WHERE email COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND id != ?", [trim((string) $payload['email']), as_int($params['id'])]); if ($existingEmail) fail('Email sudah digunakan.', 422, ['email' => ['Email sudah digunakan.']]); } $fields = ['name = ?', 'username = ?', 'email = ?', 'role_id = ?', 'updated_at = ?']; $values = [trim((string) $payload['name']), trim((string) $payload['username']), trim((string) ($payload['email'] ?? '')) ?: null, as_int($payload['role_id']), now_ts()]; if (array_key_exists('is_active', $payload)) { $fields[] = 'is_active = ?'; $values[] = $payload['is_active'] ? 1 : 0; } if (trim((string) ($payload['password'] ?? '')) !== '') { $fields[] = 'password = ?'; $values[] = password_hash((string) $payload['password'], PASSWORD_BCRYPT); } $values[] = as_int($params['id']); $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values); respond(['message' => 'Akun staf berhasil diperbarui!']); }
    if ($method === 'PATCH' && ($params = match_route('users/{id}/toggle', $path)) !== null) { $user = db_one($db, "SELECT * FROM users WHERE id = ?", [as_int($params['id'])]); if (!$user) fail('Akun staf tidak ditemukan.', 404); $newStatus = as_int($user['is_active']) ? 0 : 1; $db->prepare("UPDATE users SET is_active=?, updated_at=? WHERE id=?")->execute([$newStatus, now_ts(), $user['id']]); respond(['message' => $newStatus ? 'Akun staf berhasil diaktifkan!' : 'Akun staf berhasil dinonaktifkan!']); }

    if ($method === 'GET' && $path === 'reports/profit-loss') { $coaRows = report_coa_balances($db); $revenues = array_values(array_filter($coaRows, static fn (array $row): bool => strtolower((string) $row['category']) === 'revenue' && abs(as_float($row['balance'])) > 0.000001)); $expenses = array_values(array_filter($coaRows, static fn (array $row): bool => strtolower((string) $row['category']) === 'expense' && abs(as_float($row['balance'])) > 0.000001)); $totalRevenue = array_sum(array_map(static fn (array $row): float => as_float($row['balance']), $revenues)); $totalExpense = array_sum(array_map(static fn (array $row): float => as_float($row['balance']), $expenses)); respond(['data' => ['revenue' => ['room' => 0, 'addon' => 0, 'total' => $totalRevenue], 'expense' => ['total' => $totalExpense], 'netProfit' => $totalRevenue - $totalExpense, 'revenues' => $revenues, 'expenses' => $expenses, 'total_revenue' => $totalRevenue, 'total_expense' => $totalExpense, 'net_profit' => $totalRevenue - $totalExpense]]); }
    if ($method === 'GET' && $path === 'reports/balance-sheet') { $coaRows = report_coa_balances($db); $assets = array_values(array_filter($coaRows, static fn (array $row): bool => strtolower((string) $row['category']) === 'asset' && abs(as_float($row['balance'])) > 0.000001)); $liabilities = array_values(array_filter($coaRows, static fn (array $row): bool => strtolower((string) $row['category']) === 'liability' && abs(as_float($row['balance'])) > 0.000001)); $equities = array_values(array_filter($coaRows, static fn (array $row): bool => strtolower((string) $row['category']) === 'equity' && abs(as_float($row['balance'])) > 0.000001)); $revenues = array_values(array_filter($coaRows, static fn (array $row): bool => strtolower((string) $row['category']) === 'revenue')); $expenses = array_values(array_filter($coaRows, static fn (array $row): bool => strtolower((string) $row['category']) === 'expense')); $currentYearEarnings = array_sum(array_map(static fn (array $row): float => as_float($row['balance']), $revenues)) - array_sum(array_map(static fn (array $row): float => as_float($row['balance']), $expenses)); if (abs($currentYearEarnings) > 0.000001) $equities[] = ['code' => 'CURRENT-YEAR', 'name' => 'Laba Ditahan Berjalan', 'category' => 'Equity', 'normal_balance' => 'Credit', 'debit' => 0, 'credit' => max(0, $currentYearEarnings), 'balance' => $currentYearEarnings]; $totalAssets = array_sum(array_map(static fn (array $row): float => as_float($row['balance']), $assets)); $totalLiabilityEquity = array_sum(array_map(static fn (array $row): float => as_float($row['balance']), $liabilities)) + array_sum(array_map(static fn (array $row): float => as_float($row['balance']), $equities)); respond(['data' => ['assets' => $assets, 'liabilities' => $liabilities, 'equities' => $equities, 'total_asset' => $totalAssets, 'total_liability_and_equity' => $totalLiabilityEquity]]); }
    if ($method === 'GET' && $path === 'reports/cash-flow') { $cashCodes = array_map(static fn (array $row): string => (string) $row['code'], db_all($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'asset' AND code LIKE '111%' ORDER BY code ASC")); if ($cashCodes === []) respond(['data' => ['inflow' => ['guest_payments' => 0, 'manual_journals' => 0, 'total' => 0], 'outflow' => ['expenses' => 0, 'total' => 0], 'netCashFlow' => 0, 'inflows' => [], 'outflows' => [], 'total_inflow' => 0, 'total_outflow' => 0, 'net_cash_flow' => 0]]); $placeholders = implode(',', array_fill(0, count($cashCodes), '?')); $inflowRows = db_all($db, "SELECT jl.*, j.journal_date, j.description, j.source FROM journal_lines jl JOIN journals j ON j.id = jl.journal_id WHERE jl.coa_code IN ({$placeholders}) AND jl.debit > 0 ORDER BY j.journal_date DESC, jl.id DESC LIMIT 100", $cashCodes); $outflowRows = db_all($db, "SELECT jl.*, j.journal_date, j.description, j.source FROM journal_lines jl JOIN journals j ON j.id = jl.journal_id WHERE jl.coa_code IN ({$placeholders}) AND jl.credit > 0 ORDER BY j.journal_date DESC, jl.id DESC LIMIT 100", $cashCodes); $inflows = array_map(static fn (array $row): array => ['date' => $row['journal_date'], 'description' => $row['line_description'] ?: ($row['description'] ?: 'Cash inflow'), 'amount' => as_float($row['debit']), 'coa' => $row['coa_code']], $inflowRows); $outflows = array_map(static fn (array $row): array => ['date' => $row['journal_date'], 'description' => $row['line_description'] ?: ($row['description'] ?: 'Cash outflow'), 'amount' => as_float($row['credit']), 'coa' => $row['coa_code']], $outflowRows); $totalInflow = array_sum(array_map(static fn (array $row): float => as_float($row['amount']), $inflows)); $totalOutflow = array_sum(array_map(static fn (array $row): float => as_float($row['amount']), $outflows)); respond(['data' => ['inflow' => ['guest_payments' => 0, 'manual_journals' => $totalInflow, 'total' => $totalInflow], 'outflow' => ['expenses' => $totalOutflow, 'total' => $totalOutflow], 'netCashFlow' => $totalInflow - $totalOutflow, 'inflows' => $inflows, 'outflows' => $outflows, 'total_inflow' => $totalInflow, 'total_outflow' => $totalOutflow, 'net_cash_flow' => $totalInflow - $totalOutflow]]); }
    if ($method === 'GET' && $path === 'reports/general-ledger') {
        $coaCode = trim((string) q('coa_code', ''));
        if ($coaCode === '') fail('COA wajib dipilih untuk melihat buku besar.', 422, ['coa_code' => ['COA wajib dipilih.']]);
        $coa = db_one($db, "SELECT code, account_name, category, normal_balance FROM coa_accounts WHERE code = ? LIMIT 1", [$coaCode]);
        if (!$coa) fail('COA tidak ditemukan.', 404);
        $fromDate = trim((string) q('from_date', ''));
        $toDate = trim((string) q('to_date', ''));
        if ($fromDate === '') $fromDate = (string) db_value($db, "SELECT COALESCE(MIN(journal_date), CURDATE()) FROM journals");
        if ($toDate === '') $toDate = today_date();
        if ($fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];
        $openingRow = db_one($db, "SELECT COALESCE(SUM(jl.debit),0) AS total_debit, COALESCE(SUM(jl.credit),0) AS total_credit FROM journal_lines jl JOIN journals j ON j.id = jl.journal_id WHERE jl.coa_code = ? AND j.journal_date < ?", [$coaCode, $fromDate]);
        $normalBalance = strtolower((string) ($coa['normal_balance'] ?? 'debit'));
        $openingDebit = as_float($openingRow['total_debit'] ?? 0);
        $openingCredit = as_float($openingRow['total_credit'] ?? 0);
        $openingBalance = $normalBalance === 'credit' ? $openingCredit - $openingDebit : $openingDebit - $openingCredit;
        $rows = db_all($db, "SELECT j.journal_date, j.journal_number, j.description AS journal_description, j.source, jl.id AS line_id, jl.line_description, jl.debit, jl.credit FROM journal_lines jl JOIN journals j ON j.id = jl.journal_id WHERE jl.coa_code = ? AND j.journal_date BETWEEN ? AND ? ORDER BY j.journal_date ASC, j.journal_number ASC, jl.id ASC", [$coaCode, $fromDate, $toDate]);
        $runningBalance = $openingBalance;
        $entries = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($rows as $row) {
            $debit = as_float($row['debit']);
            $credit = as_float($row['credit']);
            $runningBalance += $normalBalance === 'credit' ? ($credit - $debit) : ($debit - $credit);
            $totalDebit += $debit;
            $totalCredit += $credit;
            $entries[] = [
                'id' => as_int($row['line_id']),
                'date' => $row['journal_date'],
                'journalNo' => $row['journal_number'],
                'description' => $row['line_description'] ?: ($row['journal_description'] ?: '-'),
                'source' => $row['source'] ?: '-',
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
            ];
        }
        respond(['data' => [
            'account' => [
                'code' => $coa['code'],
                'name' => $coa['account_name'],
                'category' => ucfirst((string) $coa['category']),
                'normal_balance' => ucfirst((string) $coa['normal_balance']),
            ],
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'opening_balance' => $openingBalance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $runningBalance,
            'entries' => $entries,
        ]]);
    }
    if ($method === 'GET' && $path === 'reports/reconciliation') { $bookings = db_all($db, "SELECT * FROM bookings ORDER BY id ASC"); $bookingRows = []; foreach ($bookings as $booking) { $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]); $journalExists = (int) db_value($db, "SELECT COUNT(*) FROM journals WHERE source = 'invoice' AND reference_type = 'booking' AND reference_id = ?", [$booking['id']]) > 0; $issues = []; if (!$invoice) { $issues[] = 'Invoice belum dibuat'; } else { if (abs(as_float($booking['grand_total']) - as_float($invoice['grand_total'])) > 0.000001) $issues[] = 'Total booking dan invoice tidak cocok'; if (abs((as_float($invoice['grand_total']) - as_float($invoice['paid_amount'])) - as_float($invoice['balance_due'])) > 0.000001) $issues[] = 'Paid amount dan balance due tidak konsisten'; if (!$journalExists) $issues[] = 'Jurnal invoice belum ada'; } $bookingRows[] = ['bookingCode' => $booking['booking_code'], 'invoiceNo' => $invoice['invoice_number'] ?? '', 'bookingGrandTotal' => as_float($booking['grand_total']), 'invoiceGrandTotal' => $invoice ? as_float($invoice['grand_total']) : null, 'invoicePaidAmount' => $invoice ? as_float($invoice['paid_amount']) : null, 'invoiceBalanceDue' => $invoice ? as_float($invoice['balance_due']) : null, 'hasInvoiceJournal' => $journalExists, 'issues' => $issues]; } $payments = db_all($db, "SELECT * FROM payments ORDER BY id ASC"); $paymentRows = []; foreach ($payments as $payment) { $allocation = db_one($db, "SELECT * FROM payment_allocations WHERE payment_id = ? LIMIT 1", [$payment['id']]); $invoice = $allocation ? db_one($db, "SELECT * FROM invoices WHERE id = ?", [$allocation['invoice_id']]) : null; $booking = $invoice ? db_one($db, "SELECT booking_code FROM bookings WHERE id = ?", [$invoice['booking_id']]) : null; $journalExists = (int) db_value($db, "SELECT COUNT(*) FROM journals WHERE reference_type = 'payment' AND reference_id = ?", [$payment['id']]) > 0; $issues = []; $signedAmount = payment_signed_amount($payment); if (!$allocation) $issues[] = 'Alokasi payment belum ada'; if ($allocation && abs($signedAmount - as_float($allocation['allocated_amount'])) > 0.000001) $issues[] = 'Signed amount payment dan allocated amount tidak cocok'; if (!$journalExists) $issues[] = 'Jurnal payment belum ada'; $paymentRows[] = ['paymentNumber' => $payment['payment_number'], 'transactionType' => payment_transaction_type($payment['transaction_type'] ?? 'payment'), 'bookingCode' => $booking['booking_code'] ?? '', 'invoiceNo' => $invoice['invoice_number'] ?? '', 'amount' => as_float($payment['amount']), 'signedAmount' => $signedAmount, 'allocatedAmount' => as_float($allocation['allocated_amount'] ?? 0), 'hasPaymentJournal' => $journalExists, 'issues' => $issues]; } respond(['data' => ['summary' => ['booking_issue_count' => count(array_filter($bookingRows, static fn (array $row): bool => $row['issues'] !== [])), 'payment_issue_count' => count(array_filter($paymentRows, static fn (array $row): bool => $row['issues'] !== [])), 'bookings_checked' => count($bookingRows), 'payments_checked' => count($paymentRows)], 'bookingRows' => $bookingRows, 'paymentRows' => $paymentRows]]); }
    if ($method === 'POST' && $path === 'accounting/sync-history') { $bookingIds = db_all($db, "SELECT id FROM bookings ORDER BY id ASC"); foreach ($bookingIds as $row) sync_booking_financial_state($db, as_int($row['id'])); $paymentIds = db_all($db, "SELECT id FROM payments ORDER BY id ASC"); foreach ($paymentIds as $row) sync_payment_accounting($db, as_int($row['id'])); $inventorySync = sync_inventory_accounting($db); respond(['message' => 'Sinkronisasi accounting historis berhasil dijalankan.', 'data' => ['bookings_synced' => count($bookingIds), 'payments_synced' => count($paymentIds), 'inventory' => $inventorySync]]); }
    if ($method === 'GET' && $path === 'audit-trails') { $page = max(1, as_int(q('page', 1))); $perPage = min(max(as_int(q('per_page', 20)), 1), 100); $search = trim((string) q('search', '')); $module = trim((string) q('module', '')); $where = []; $params = []; if ($module !== '') { $where[] = "module = ?"; $params[] = $module; } if ($search !== '') { $like = '%' . $search . '%'; $where[] = "(user_name LIKE ? OR user_email LIKE ? OR entity_label LIKE ? OR description LIKE ?)"; array_push($params, $like, $like, $like, $like); } $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : ''; $total = (int) db_value($db, "SELECT COUNT(*) FROM audit_trails {$whereSql}", $params); $rows = db_all($db, "SELECT * FROM audit_trails {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params); respond(['data' => array_map(static fn (array $row): array => ['id' => as_int($row['id']), 'createdAt' => $row['created_at'], 'module' => $row['module'], 'action' => $row['action'], 'userName' => $row['user_name'] ?: 'System', 'userEmail' => $row['user_email'] ?: '-', 'userRole' => $row['user_role'] ?: '-', 'entityType' => $row['entity_type'] ?: '-', 'entityId' => $row['entity_id'] ?: '-', 'entityLabel' => $row['entity_label'] ?: '-', 'description' => $row['description'], 'metadata' => $row['metadata'] ? (json_decode((string) $row['metadata'], true) ?: []) : [], 'ipAddress' => $row['ip_address'] ?: '-'], $rows), 'meta' => paginate_meta($page, $perPage, $total)]); }

    fail('Endpoint tidak ditemukan.', 404);
} catch (PDOException $e) {
    fail("Terjadi kesalahan database:\nMessage: {$e->getMessage()}\nFile: {$e->getFile()}\nLine: {$e->getLine()}\nTrace:\n{$e->getTraceAsString()}", 500);
}
catch (Throwable $e) {
    fail("Terjadi kesalahan server:\nMessage: {$e->getMessage()}\nFile: {$e->getFile()}\nLine: {$e->getLine()}\nTrace:\n{$e->getTraceAsString()}", 500);
}
