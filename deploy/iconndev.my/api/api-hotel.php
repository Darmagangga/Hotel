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
    $userIndexCheck = (int) db_value($db, "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'users_username_unique'");
    if ($userIndexCheck === 0) $db->exec("ALTER TABLE users ADD UNIQUE KEY users_username_unique (username)");
    $missingUsernameRows = db_all($db, "SELECT id, name, email FROM users WHERE username IS NULL OR username = '' ORDER BY id ASC");
    foreach ($missingUsernameRows as $row) {
        $seed = trim((string) ($row['email'] ?: $row['name'] ?: ('user' . $row['id'])));
        $seed = strtolower(preg_replace('/[^a-z0-9]+/i', '', $seed) ?: ('user' . $row['id']));
        if ($seed === '') $seed = 'user' . $row['id'];
        $candidate = $seed;
        $suffix = 1;
        while ((int) db_value($db, "SELECT COUNT(*) FROM users WHERE username = ? AND id != ?", [$candidate, $row['id']]) > 0) {
            $candidate = $seed . $suffix;
            $suffix++;
        }
        $db->prepare("UPDATE users SET username = ?, updated_at = ? WHERE id = ?")->execute([$candidate, now_ts(), $row['id']]);
    }
    $paymentColumns = []; foreach ($db->query("SHOW COLUMNS FROM payments") as $row) $paymentColumns[$row['Field']] = true;
    if (!isset($paymentColumns['transaction_type'])) $db->exec("ALTER TABLE payments ADD COLUMN transaction_type VARCHAR(30) NOT NULL DEFAULT 'payment' AFTER amount");
    if (!isset($paymentColumns['parent_payment_id'])) $db->exec("ALTER TABLE payments ADD COLUMN parent_payment_id BIGINT UNSIGNED NULL AFTER transaction_type");
    $exists = function (string $table) use ($db): bool { $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"); $stmt->execute([$table]); return (int) $stmt->fetchColumn() > 0; };
    if (!$exists('transport_rates')) $db->exec("CREATE TABLE transport_rates (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, driver VARCHAR(255) NOT NULL, pickup_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, drop_off_price_value DECIMAL(15,2) NOT NULL DEFAULT 0, vehicle VARCHAR(255) NULL, note TEXT NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('activity_operator_catalog')) $db->exec("CREATE TABLE activity_operator_catalog (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, operator VARCHAR(255) NOT NULL, price_value DECIMAL(15,2) NOT NULL DEFAULT 0, note TEXT NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('island_tour_catalog')) $db->exec("CREATE TABLE island_tour_catalog (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, destination VARCHAR(255) NOT NULL, driver VARCHAR(255) NOT NULL, cost_value DECIMAL(15,2) NOT NULL DEFAULT 0, note TEXT NULL, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$exists('boat_ticket_catalog')) $db->exec("CREATE TABLE boat_ticket_catalog (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company VARCHAR(255) NOT NULL, destination VARCHAR(255) NOT NULL, price_value DECIMAL(15,2) NOT NULL DEFAULT 0, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($exists('scooter_catalog')) {
        $cols = []; foreach ($db->query("SHOW COLUMNS FROM scooter_catalog") as $row) $cols[$row['Field']] = true;
        if (!isset($cols['start_date'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN start_date DATE NULL");
        if (!isset($cols['end_date'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN end_date DATE NULL");
        if (!isset($cols['vendor'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN vendor VARCHAR(255) NULL");
        if (!isset($cols['price_value'])) $db->exec("ALTER TABLE scooter_catalog ADD COLUMN price_value DECIMAL(15,2) NOT NULL DEFAULT 0");
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
    $normalized = trim((string) $date);
    if ($normalized === '') return;
    $lastClosed = last_closed_business_date($db);
    if ($lastClosed !== null && $normalized <= $lastClosed) {
        fail("{$label} {$normalized} sudah masuk periode yang ditutup. Gunakan tanggal setelah {$lastClosed} atau lakukan koreksi terotorisasi.", 422, [
            'businessDate' => ["Tanggal {$normalized} sudah ditutup pada daily closing."],
        ]);
    }
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
function resolve_revenue_coa(PDO $db, array $preferredCodes, string $prefix): string {
    foreach ($preferredCodes as $code) if ((int) db_value($db, "SELECT COUNT(*) FROM coa_accounts WHERE code = ?", [$code]) > 0) return $code;
    $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'revenue' AND code LIKE ? ORDER BY code ASC LIMIT 1", [$prefix . '%']); if ($fallback) return (string) $fallback;
    $fallback = db_value($db, "SELECT code FROM coa_accounts WHERE LOWER(category) = 'revenue' ORDER BY code ASC LIMIT 1"); return (string) ($fallback ?: $preferredCodes[0]);
}
function resolve_room_revenue_coa(PDO $db, string $source): string { $preferred = match ($source) { 'airbnb' => '411018', 'booking.com' => '411023', default => '411021', }; return resolve_revenue_coa($db, [$preferred, '411021', '411018', '411023'], '411'); }
function resolve_addon_revenue_coa(PDO $db, string $addonType): string { $preferred = match ($addonType) { 'transport' => '510001', 'scooter' => '510002', 'boat_ticket' => '510004', 'island_tour' => '510005', default => '411021', }; return resolve_revenue_coa($db, [$preferred, '411021', '510001'], str_starts_with($preferred, '510') ? '510' : '411'); }
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
        return ['id' => as_int($row['id']), 'addonType' => $row['addon_type'], 'addonLabel' => $meta['addonLabel'] ?? ucfirst(str_replace('_', ' ', (string) $row['addon_type'])), 'serviceName' => $meta['serviceName'] ?? 'Add-on service', 'itemRef' => $meta['itemRef'] ?? '', 'serviceDate' => $serviceDate, 'startDate' => $startDate, 'endDate' => $endDate, 'serviceDateLabel' => $row['addon_type'] === 'scooter' && $startDate && $endDate ? "{$startDate} to {$endDate}" : $serviceDate, 'quantity' => as_int($row['qty']), 'unitPriceValue' => as_float($row['unit_price']), 'unitPrice' => money(as_float($row['unit_price'])), 'totalPriceValue' => as_float($row['total_price']), 'totalPrice' => money(as_float($row['total_price'])), 'status' => addon_status_label((string) $row['status']), 'notes' => $meta['note'] ?? ''];
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
    $roomRevenue = as_float($booking['room_amount']); $discountAmount = as_float($booking['discount_amount']); $receivableTotal = as_float($invoice['grand_total']); $addonRevenueRows = db_all($db, "SELECT addon_type, SUM(total_price) AS total_price FROM booking_addons WHERE booking_id = ? AND status != 'cancelled' GROUP BY addon_type", [$bookingId]);
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
    foreach ($addonRevenueRows as $row) { $value = as_float($row['total_price']); if ($value <= 0) continue; $insert->execute([$journalId, resolve_addon_revenue_coa($db, (string) $row['addon_type']), "Add-on revenue {$booking['booking_code']} ({$row['addon_type']})", 0, $value, $now, $now]); }
    if ($discountAmount > 0) $insert->execute([$journalId, resolve_discount_coa($db, (string) $booking['source']), "Discount {$booking['booking_code']}", $discountAmount, 0, $now, $now]);
}
function sync_booking_financial_state(PDO $db, int $bookingId): void {
    $booking = db_one($db, "SELECT * FROM bookings WHERE id = ?", [$bookingId]); if (!$booking) return; $status = (string) $booking['status']; $addonTotal = as_float(db_value($db, "SELECT COALESCE(SUM(total_price), 0) FROM booking_addons WHERE booking_id = ? AND status != 'cancelled'", [$bookingId])); $chargeBase = booking_charge_base($db, $booking, $addonTotal); $isCancelled = in_array($status, ['cancelled', 'no_show'], true); $grandTotal = match ($status) { 'cancelled' => booking_cancellation_penalty_amount($db, $booking, $addonTotal), 'no_show' => 0.0, default => $chargeBase, };
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
function invoice_pdf_payload(PDO $db, string $bookingCode): array {
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
    return [
        'booking' => $bookingData,
        'invoice' => $invoice,
        'property' => [
            'name' => trim((string) hotel_setting_value($db, 'property_legal_name', 'Udara Hideaway Villa')),
            'address' => trim((string) hotel_setting_value($db, 'property_address', 'Jl. Udara Hideaway No. 8, Indonesia')),
            'phone' => trim((string) hotel_setting_value($db, 'property_phone', '+62 000 0000 0000')),
            'email' => trim((string) hotel_setting_value($db, 'property_email', 'hello@udarahideawayvilla.com')),
        ],
        'payments' => array_map(static fn (array $payment): array => payment_transform($db, $payment), $payments),
    ];
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

    $documentTitle = $documentType === 'folio' ? 'FOLIO' : 'INVOICE';
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
    $pdf->Cell($contentWidth, 4, 'Please review this invoice carefully. Payments are considered settled after confirmed receipt.', 0, 1, 'C');
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
    $pdf->Cell($contentWidth, 4, 'Guest folio includes settlement movements and is intended for operational reconciliation.', 0, 1, 'C');
}
function invoice_print_html(PDO $db, string $bookingCode): string {

    $payload = invoice_pdf_payload($db, $bookingCode);
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
          <td style="width:28%;" class="title">INVOICE</td>
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
          <td style="width:28%;" class="title">INVOICE</td>
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
      <div class="footer">Please review this invoice carefully. Payments are considered settled after confirmed receipt.</div>
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
    $payload = invoice_pdf_payload($db, $bookingCode);
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
function report_coa_balances(PDO $db): array {
    $coas = db_all($db, "SELECT code, account_name, category, normal_balance FROM coa_accounts ORDER BY code ASC");
    $balanceRows = db_all($db, "SELECT coa_code, COALESCE(SUM(debit),0) AS total_debit, COALESCE(SUM(credit),0) AS total_credit FROM journal_lines GROUP BY coa_code"); $lookup = [];
    foreach ($balanceRows as $row) $lookup[(string) $row['coa_code']] = ['debit' => as_float($row['total_debit']), 'credit' => as_float($row['total_credit'])];
    $result = [];
    foreach ($coas as $coa) { $entry = $lookup[$coa['code']] ?? ['debit' => 0.0, 'credit' => 0.0]; $normal = strtolower((string) $coa['normal_balance']); $balance = $normal === 'credit' ? $entry['credit'] - $entry['debit'] : $entry['debit'] - $entry['credit']; $result[] = ['code' => $coa['code'], 'name' => $coa['account_name'], 'category' => ucfirst((string) $coa['category']), 'normal_balance' => ucfirst((string) $coa['normal_balance']), 'debit' => $entry['debit'], 'credit' => $entry['credit'], 'balance' => $balance]; }
    return $result;
}

$baseDir = __DIR__; $env = env_map($baseDir . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . '.env');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? 'hotel');
try { $db = new PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
catch (Throwable $e) { fail('Gagal terhubung ke database MySQL.', 500); }
ensure_runtime_schema($db); $secret = env_secret($env); $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')); $path = route_path(); $actor = null;
$permissionRoutes = [
    ['GET', 'coa-accounts', 'coa'], ['POST', 'coa-accounts', 'coa'], ['PUT', 'coa-accounts/{code}', 'coa'], ['GET', 'room-types', 'rooms'], ['GET', 'rooms', 'rooms'], ['POST', 'rooms', 'rooms'], ['PUT', 'rooms/{code}', 'rooms'], ['GET', 'housekeeping/queue', 'rooms'], ['PATCH', 'housekeeping/tasks/{id}', 'rooms'],
    ['GET', 'bookings', 'bookings'], ['GET', 'bookings/{code}', 'bookings'], ['GET', 'bookings/{code}/invoice-pdf', 'bookings'], ['GET', 'bookings/{code}/invoice-print', 'bookings'], ['POST', 'bookings', 'bookings'], ['PUT', 'bookings/{code}', 'bookings'], ['PATCH', 'bookings/{code}/status', 'bookings'], ['POST', 'bookings/{code}/addons', 'bookings'], ['PATCH', 'bookings/{code}/addons/{id}', 'bookings'], ['DELETE', 'bookings/{code}/addons/{id}', 'bookings'],
    ['GET', 'journals', 'journals'], ['POST', 'journals', 'journals'], ['PUT', 'journals/{id}', 'journals'], ['GET', 'payments', 'finance'], ['GET', 'finance/invoices/{code}/pdf', 'finance'], ['GET', 'finance/invoices/{code}/print', 'finance'], ['POST', 'payments', 'finance'], ['POST', 'payments/{id}/refund', 'finance'], ['POST', 'payments/{id}/void', 'finance'], ['GET', 'inventory', 'inventory'], ['POST', 'inventory/items', 'inventory'], ['POST', 'inventory/purchases', 'inventory'], ['POST', 'inventory/purchases/{id}/cancel', 'inventory'], ['POST', 'inventory/issues', 'inventory'], ['POST', 'inventory/issues/{id}/return', 'inventory'],
    ['GET', 'transport-rates', 'transport'], ['POST', 'transport-rates', 'transport'], ['PUT', 'transport-rates/{id}', 'transport'], ['GET', 'activity-catalog', 'activities'], ['POST', 'activity-catalog/scooters', 'activities'], ['PUT', 'activity-catalog/scooters/{id}', 'activities'], ['POST', 'activity-catalog/operators', 'activities'], ['PUT', 'activity-catalog/operators/{id}', 'activities'], ['POST', 'activity-catalog/island-tours', 'activities'], ['PUT', 'activity-catalog/island-tours/{id}', 'activities'], ['POST', 'activity-catalog/boat-tickets', 'activities'], ['PUT', 'activity-catalog/boat-tickets/{id}', 'activities'],
    ['GET', 'reports/balance-sheet', 'reports'], ['GET', 'reports/profit-loss', 'reports'], ['GET', 'reports/cash-flow', 'reports'], ['GET', 'reports/general-ledger', 'reports'], ['GET', 'reports/reconciliation', 'reports'], ['GET', 'audit-trails', 'reports'], ['GET', 'dashboard/owner', 'dashboard'], ['PUT', 'dashboard/owner/policies', 'dashboard'], ['GET', 'night-audit/status', 'dashboard'], ['GET', 'night-audit/history', 'dashboard'], ['GET', 'settings/policies', 'settings'], ['PUT', 'settings/policies', 'settings'], ['POST', 'night-audit', 'roles'], ['POST', 'accounting/sync-history', 'roles'],
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
        $userRecord = db_one($db, "SELECT u.id, u.name, u.username, u.email, u.password, u.is_active, r.name AS role, r.permissions FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.username = ? OR u.email = ? LIMIT 1", [$credential, $credential]);
        if ($userRecord && password_verify((string) $payload['password'], (string) $userRecord['password'])) {
            if (!as_int($userRecord['is_active'])) fail('Akun Anda dinonaktifkan.', 403);
            $user = ['id' => as_int($userRecord['id']), 'name' => $userRecord['name'], 'username' => $userRecord['username'], 'email' => $userRecord['email'], 'role' => $userRecord['role'] ?: 'frontdesk', 'permissions' => $userRecord['permissions'] ? (json_decode((string) $userRecord['permissions'], true) ?: []) : []];
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
        if ($targetStatus === 'checked_out') { if ($currentStatus !== 'checked_in') fail('Hanya booking Checked-in yang bisa di-check-out.', 422, ['status' => ['Hanya booking Checked-in yang bisa di-check-out.']]); $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]); if ($invoice && as_float($invoice['balance_due']) > 0) fail('Tidak bisa Check-out. Tamu belum melunasi tagihan (Sisa: ' . money(as_float($invoice['balance_due'])) . '). Silakan lakukan pembayaran terlebih dahulu di menu Finance / Folio.', 422, ['status' => ['Invoice belum lunas.']]); }
        if (in_array($targetStatus, ['cancelled', 'no_show'], true)) { $invoice = db_one($db, "SELECT * FROM invoices WHERE booking_id = ? LIMIT 1", [$booking['id']]); $cancelPenalty = $targetStatus === 'cancelled' ? booking_cancellation_penalty_amount($db, $booking) : 0.0; if ($targetStatus === 'cancelled' && $invoice && as_float($invoice['paid_amount']) > $cancelPenalty) fail('Pembayaran yang sudah diterima melebihi nilai penalti cancel. Lakukan refund atau void terlebih dahulu sebelum membatalkan booking.', 422, ['status' => ['Pembayaran melebihi penalti cancel.']]); if ($targetStatus === 'no_show' && $invoice && as_float($invoice['paid_amount']) > 0) fail('Booking yang sudah menerima pembayaran tidak bisa langsung diubah ke no-show. Lakukan refund atau void terlebih dahulu.', 422, ['status' => ['Booking sudah memiliki pembayaran.']]); }
        $db->beginTransaction();
        try { $db->prepare("UPDATE bookings SET status=?, updated_at=? WHERE id=?")->execute([$targetStatus, now_ts(), $booking['id']]); if ($targetStatus === 'checked_in' || $targetStatus === 'checked_out') { $roomIds = db_all($db, "SELECT room_id FROM booking_rooms WHERE booking_id = ?", [$booking['id']]); $status = $targetStatus === 'checked_in' ? 'occupied' : 'dirty'; $up = $db->prepare("UPDATE rooms SET status=?, updated_at=? WHERE id=?"); foreach ($roomIds as $row) $up->execute([$status, now_ts(), $row['room_id']]); } sync_booking_financial_state($db, as_int($booking['id'])); $db->commit(); }
        catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        $fresh = load_booking_row($db, $params['code']); $statusMessage = match ($targetStatus) { 'checked_in' => 'Tamu berhasil check-in.', 'checked_out' => 'Tamu berhasil check-out.', 'cancelled' => ($fresh ? 'Reservasi berhasil dibatalkan. Penalti cancel ' . cancellation_policy_payload($db)['label'] . '%: ' . money(as_float($fresh['grand_total'])) . '.' : 'Reservasi berhasil dibatalkan.'), 'no_show' => 'Reservasi ditandai no-show.', default => 'Status reservasi berhasil diperbarui.', };
        respond(['data' => transform_booking($db, $fresh), 'message' => $statusMessage]);
    }
    if ($method === 'POST' && ($params = match_route('bookings/{code}/addons', $path)) !== null) {
        $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); $payload = json_input(); require_fields($payload, ['addonType', 'serviceName', 'addonLabel', 'unitPriceValue', 'status']);
        $quantity = max(1, as_int($payload['quantity'] ?? 1)); $unitPrice = as_float($payload['unitPriceValue']);
        $db->prepare("INSERT INTO booking_addons (booking_id, addon_type, reference_id, service_date, start_date, end_date, qty, unit_price, total_price, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$booking['id'], trim((string) $payload['addonType']), is_numeric($payload['referenceId'] ?? null) ? as_int($payload['referenceId']) : null, $payload['serviceDate'] ?? $payload['startDate'] ?? substr((string) $booking['check_in_at'], 0, 10), $payload['startDate'] ?? $payload['serviceDate'] ?? substr((string) $booking['check_in_at'], 0, 10), $payload['endDate'] ?? null, $quantity, $unitPrice, $unitPrice * $quantity, normalize_addon_status((string) $payload['status']), json_encode(['note' => trim((string) ($payload['notes'] ?? '')), 'serviceName' => trim((string) $payload['serviceName']), 'addonLabel' => trim((string) $payload['addonLabel']), 'itemRef' => trim((string) ($payload['referenceId'] ?? ''))], JSON_UNESCAPED_UNICODE), now_ts(), now_ts()]);
        sync_booking_financial_state($db, as_int($booking['id'])); $fresh = load_booking_row($db, $params['code']); respond(['data' => transform_booking($db, $fresh), 'message' => 'Add-on berhasil ditautkan ke reservasi.'], 201);
    }
    if ($method === 'PATCH' && ($params = match_route('bookings/{code}/addons/{id}', $path)) !== null) { $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); $payload = json_input(); require_fields($payload, ['status']); $db->prepare("UPDATE booking_addons SET status=?, updated_at=? WHERE id=? AND booking_id=?")->execute([normalize_addon_status((string) $payload['status']), now_ts(), $params['id'], $booking['id']]); sync_booking_financial_state($db, as_int($booking['id'])); $fresh = load_booking_row($db, $params['code']); respond(['data' => transform_booking($db, $fresh), 'message' => 'Status add-on berhasil diperbarui.']); }
    if ($method === 'DELETE' && ($params = match_route('bookings/{code}/addons/{id}', $path)) !== null) { $booking = load_booking_row($db, $params['code']); if (!$booking) fail('Booking tidak ditemukan.', 404); $db->prepare("DELETE FROM booking_addons WHERE id=? AND booking_id=?")->execute([$params['id'], $booking['id']]); sync_booking_financial_state($db, as_int($booking['id'])); $fresh = load_booking_row($db, $params['code']); respond(['data' => transform_booking($db, $fresh), 'message' => 'Add-on berhasil dihapus dari reservasi.']); }

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

    if ($method === 'GET' && $path === 'transport-rates') { $rows = db_all($db, "SELECT * FROM transport_rates ORDER BY id DESC"); respond(['data' => array_map(static fn (array $row): array => ['id' => 'TRF-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'driver' => $row['driver'], 'pickupPriceValue' => as_float($row['pickup_price_value']), 'pickupPrice' => money(as_float($row['pickup_price_value'])), 'dropOffPriceValue' => as_float($row['drop_off_price_value']), 'dropOffPrice' => money(as_float($row['drop_off_price_value'])), 'vehicle' => $row['vehicle'], 'note' => $row['note']], $rows)]); }
    if (($method === 'POST' && $path === 'transport-rates') || ($method === 'PUT' && ($params = match_route('transport-rates/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['driver', 'pickupPriceValue', 'dropOffPriceValue']); if ($method === 'POST') { $db->prepare("INSERT INTO transport_rates (driver, pickup_price_value, drop_off_price_value, vehicle, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([trim((string) $payload['driver']), as_float($payload['pickupPriceValue']), as_float($payload['dropOffPriceValue']), trim((string) ($payload['vehicle'] ?? '')) ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]); respond(['message' => "Driver {$payload['driver']} berhasil ditambahkan."], 201); } $db->prepare("UPDATE transport_rates SET driver=?, pickup_price_value=?, drop_off_price_value=?, vehicle=?, note=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['driver']), as_float($payload['pickupPriceValue']), as_float($payload['dropOffPriceValue']), trim((string) ($payload['vehicle'] ?? '')) ?: null, trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), as_int($params['id'])]); respond(['message' => "Driver {$payload['driver']} berhasil diperbarui."]); }

    if ($method === 'GET' && $path === 'activity-catalog') { $scooters = db_all($db, "SELECT * FROM scooter_catalog ORDER BY id DESC"); $operators = db_all($db, "SELECT * FROM activity_operator_catalog ORDER BY id DESC"); $tours = db_all($db, "SELECT * FROM island_tour_catalog ORDER BY id DESC"); $boats = db_all($db, "SELECT * FROM boat_ticket_catalog ORDER BY id DESC"); respond(['data' => ['scooters' => array_map(static fn (array $row): array => ['id' => 'SCT-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'startDate' => $row['start_date'], 'endDate' => $row['end_date'], 'scooterType' => $row['scooter_type'], 'vendor' => $row['vendor'], 'priceValue' => as_float($row['price_value']), 'price' => money(as_float($row['price_value']))], $scooters), 'operators' => array_map(static fn (array $row): array => ['id' => 'OPR-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'operator' => $row['operator'], 'priceValue' => as_float($row['price_value']), 'price' => money(as_float($row['price_value'])), 'note' => $row['note']], $operators), 'islandTours' => array_map(static fn (array $row): array => ['id' => 'TOUR-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'destination' => $row['destination'], 'driver' => $row['driver'], 'costValue' => as_float($row['cost_value']), 'cost' => money(as_float($row['cost_value'])), 'note' => $row['note']], $tours), 'boatTickets' => array_map(static fn (array $row): array => ['id' => 'BOT-' . str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT), 'dbId' => as_int($row['id']), 'company' => $row['company'], 'destination' => $row['destination'], 'priceValue' => as_float($row['price_value']), 'price' => money(as_float($row['price_value']))], $boats)]]); }
    if (($method === 'POST' && $path === 'activity-catalog/scooters') || ($method === 'PUT' && ($params = match_route('activity-catalog/scooters/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['startDate', 'endDate', 'scooterType', 'vendor', 'priceValue']); if ($method === 'POST') { $db->prepare("INSERT INTO scooter_catalog (start_date, end_date, scooter_type, vendor, price_value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$payload['startDate'], $payload['endDate'], trim((string) $payload['scooterType']), trim((string) $payload['vendor']), as_float($payload['priceValue']), now_ts(), now_ts()]); respond(['message' => 'Data scooter berhasil ditambahkan.'], 201); } $db->prepare("UPDATE scooter_catalog SET start_date=?, end_date=?, scooter_type=?, vendor=?, price_value=?, updated_at=? WHERE id=?")->execute([$payload['startDate'], $payload['endDate'], trim((string) $payload['scooterType']), trim((string) $payload['vendor']), as_float($payload['priceValue']), now_ts(), as_int($params['id'])]); respond(['message' => 'Data scooter berhasil diperbarui.']); }
    if (($method === 'POST' && $path === 'activity-catalog/operators') || ($method === 'PUT' && ($params = match_route('activity-catalog/operators/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['operator', 'priceValue']); if ($method === 'POST') { $db->prepare("INSERT INTO activity_operator_catalog (operator, price_value, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")->execute([trim((string) $payload['operator']), as_float($payload['priceValue']), trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]); respond(['message' => 'Data operator berhasil ditambahkan.'], 201); } $db->prepare("UPDATE activity_operator_catalog SET operator=?, price_value=?, note=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['operator']), as_float($payload['priceValue']), trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), as_int($params['id'])]); respond(['message' => 'Data operator berhasil diperbarui.']); }
    if (($method === 'POST' && $path === 'activity-catalog/island-tours') || ($method === 'PUT' && ($params = match_route('activity-catalog/island-tours/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['destination', 'driver', 'costValue']); if ($method === 'POST') { $db->prepare("INSERT INTO island_tour_catalog (destination, driver, cost_value, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)")->execute([trim((string) $payload['destination']), trim((string) $payload['driver']), as_float($payload['costValue']), trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), now_ts()]); respond(['message' => 'Data island tour berhasil ditambahkan.'], 201); } $db->prepare("UPDATE island_tour_catalog SET destination=?, driver=?, cost_value=?, note=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['destination']), trim((string) $payload['driver']), as_float($payload['costValue']), trim((string) ($payload['note'] ?? '')) ?: null, now_ts(), as_int($params['id'])]); respond(['message' => 'Data island tour berhasil diperbarui.']); }
    if (($method === 'POST' && $path === 'activity-catalog/boat-tickets') || ($method === 'PUT' && ($params = match_route('activity-catalog/boat-tickets/{id}', $path)) !== null)) { $payload = json_input(); require_fields($payload, ['company', 'destination', 'priceValue']); if ($method === 'POST') { $db->prepare("INSERT INTO boat_ticket_catalog (company, destination, price_value, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")->execute([trim((string) $payload['company']), trim((string) $payload['destination']), as_float($payload['priceValue']), now_ts(), now_ts()]); respond(['message' => 'Data boat ticket berhasil ditambahkan.'], 201); } $db->prepare("UPDATE boat_ticket_catalog SET company=?, destination=?, price_value=?, updated_at=? WHERE id=?")->execute([trim((string) $payload['company']), trim((string) $payload['destination']), as_float($payload['priceValue']), now_ts(), as_int($params['id'])]); respond(['message' => 'Data boat ticket berhasil diperbarui.']); }

    if ($method === 'PUT' && $path === 'dashboard/owner/policies') { $payload = json_input(); require_fields($payload, ['cancellationPenaltyPercent']); $percent = max(0, min(100, as_float($payload['cancellationPenaltyPercent']))); upsert_hotel_setting($db, 'booking_cancel_penalty_percent', $percent); respond(['message' => 'Kebijakan penalti cancel berhasil diperbarui.', 'data' => ['cancellationPolicy' => cancellation_policy_payload($db)]]); }
    if ($method === 'GET' && $path === 'settings/policies') { respond(['data' => ['cancellationPolicy' => cancellation_policy_payload($db)]]); }
    if ($method === 'PUT' && $path === 'settings/policies') { $payload = json_input(); require_fields($payload, ['cancellationPenaltyPercent']); $percent = max(0, min(100, as_float($payload['cancellationPenaltyPercent']))); upsert_hotel_setting($db, 'booking_cancel_penalty_percent', $percent); respond(['message' => 'Booking policy settings updated successfully.', 'data' => ['cancellationPolicy' => cancellation_policy_payload($db)]]); }
    if ($method === 'GET' && $path === 'dashboard/owner') { $today = current_business_date($db); $roomsTotal = (int) db_value($db, "SELECT COUNT(*) FROM rooms"); $availableRooms = (int) db_value($db, "SELECT COUNT(*) FROM rooms WHERE status='available'"); $inHouse = db_all($db, "SELECT * FROM bookings WHERE status='checked_in' AND DATE(check_in_at) <= ? AND DATE(check_out_at) > ?", [$today, $today]); $roomRevenue = as_float(db_value($db, "SELECT COALESCE(SUM(room_amount),0) FROM bookings WHERE DATE(check_in_at)=?", [$today])); $addonRevenue = as_float(db_value($db, "SELECT COALESCE(SUM(addon_amount),0) FROM bookings WHERE DATE(check_in_at)=?", [$today])); $openFolios = db_all($db, "SELECT * FROM invoices WHERE balance_due > 0 ORDER BY balance_due DESC LIMIT 6"); $outstanding = array_sum(array_map(static fn (array $row): float => as_float($row['balance_due']), $openFolios)); $adr = count($inHouse) > 0 ? $roomRevenue / count($inHouse) : 0; $paymentSummary = daily_payment_summary($db, $today); respond(['data' => ['period' => 'today', 'periodLabel' => 'Today', 'rangeLabel' => date('d M Y', strtotime($today)), 'businessDate' => $today, 'currentDateLabel' => build_business_date_label($today), 'generatedAt' => date('d M Y H:i'), 'cancellationPolicy' => cancellation_policy_payload($db), 'closingSummary' => $paymentSummary, 'overview' => [['label' => 'Occupancy', 'value' => ($roomsTotal > 0 ? (int) round((count($inHouse) / $roomsTotal) * 100) : 0) . '%', 'note' => count($inHouse) . " room occupied | {$availableRooms} sellable room"], ['label' => 'Arrivals', 'value' => (string) (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_in_at)=?", [$today]), 'note' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_out_at)=?", [$today]) . ' departure today'], ['label' => 'Outstanding', 'value' => money($outstanding), 'note' => count($openFolios) . ' folio still open'], ['label' => 'ADR', 'value' => money($adr), 'note' => 'Room revenue ' . money($roomRevenue)]], 'dailyControl' => [['label' => 'Arrival in range', 'value' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_in_at)=?", [$today])], ['label' => 'Departure in range', 'value' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE DATE(check_out_at)=?", [$today])], ['label' => 'Net cash closing', 'value' => $paymentSummary['netCollectionsLabel']], ['label' => 'Cash on hand', 'value' => $paymentSummary['cashLabel']], ['label' => 'In house snapshot', 'value' => count($inHouse)], ['label' => 'Room still sellable', 'value' => $availableRooms]], 'revenueMix' => [['label' => 'Room revenue', 'value' => money($roomRevenue), 'progress' => 100], ['label' => 'Add-on revenue', 'value' => money($addonRevenue), 'progress' => 100], ['label' => 'Total revenue', 'value' => money($roomRevenue + $addonRevenue), 'progress' => 100], ['label' => 'Open folios', 'value' => count($openFolios) . ' booking(s)', 'progress' => min(count($openFolios) * 12, 100)]], 'arrivalWatch' => [], 'cashierQueue' => [['guest' => 'Gross collection', 'folio' => $paymentSummary['grossCollectionsLabel'], 'due' => 'Refund/Void ' . $paymentSummary['refundsVoidsLabel']], ['guest' => 'Cash', 'folio' => $paymentSummary['cashLabel'], 'due' => 'Transfer ' . $paymentSummary['bankTransferLabel']], ['guest' => 'Card', 'folio' => $paymentSummary['cardLabel'], 'due' => 'QRIS ' . $paymentSummary['qrisLabel']]], 'channelPerformance' => [], 'roomTypePerformance' => [], 'liveMovement' => [], 'departmentNotes' => []]]); }
    if ($method === 'GET' && $path === 'night-audit/status') { $today = current_business_date($db); $lastRun = db_one($db, "SELECT * FROM night_audit_runs ORDER BY business_date DESC LIMIT 1"); $paymentSummary = daily_payment_summary($db, $today); respond(['pending_checkouts' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND DATE(check_out_at) <= ?", [$today]), 'unresolved_arrivals' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','draft') AND DATE(check_in_at) <= ?", [$today]), 'active_in_house' => (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND check_in_at <= ? AND check_out_at > ?", [$today . ' 23:59:59', $today . ' 00:00:00']), 'audit_date' => date('d M Y', strtotime($today)), 'business_date' => $today, 'next_business_date' => next_business_date($today), 'business_date_label' => build_business_date_label($today), 'next_business_date_label' => build_business_date_label(next_business_date($today)), 'last_closed_date' => last_closed_business_date($db), 'closing_summary' => $paymentSummary, 'last_run' => $lastRun ? ['businessDate' => $lastRun['business_date'], 'nextBusinessDate' => $lastRun['next_business_date'], 'foliosProcessed' => as_int($lastRun['folios_processed']), 'closedBy' => $lastRun['closed_by_name'] ?: 'System', 'closedAt' => $lastRun['created_at'], 'closingAmount' => (json_decode((string) ($lastRun['summary_json'] ?? ''), true)['closingSummary']['netCollectionsLabel'] ?? null)] : null]); }
    if ($method === 'GET' && $path === 'night-audit/history') { $rows = db_all($db, "SELECT * FROM night_audit_runs ORDER BY business_date DESC, id DESC LIMIT 20"); respond(['data' => array_map(static function (array $row): array { $summary = json_decode((string) ($row['summary_json'] ?? ''), true); $summary = is_array($summary) ? $summary : []; $closingSummary = is_array($summary['closingSummary'] ?? null) ? $summary['closingSummary'] : []; return ['id' => as_int($row['id']), 'businessDate' => $row['business_date'], 'nextBusinessDate' => $row['next_business_date'], 'pendingCheckouts' => as_int($row['pending_checkouts']), 'unresolvedArrivals' => as_int($row['unresolved_arrivals']), 'activeInHouse' => as_int($row['active_in_house']), 'foliosProcessed' => as_int($row['folios_processed']), 'closingAmount' => $closingSummary['netCollectionsLabel'] ?? money(0), 'cashAmount' => $closingSummary['cashLabel'] ?? money(0), 'closedBy' => $row['closed_by_name'] ?: 'System', 'closedAt' => $row['created_at']]; }, $rows)]); }
    if ($method === 'POST' && $path === 'night-audit') { $today = current_business_date($db); if (db_one($db, "SELECT id FROM night_audit_runs WHERE business_date = ? LIMIT 1", [$today])) fail("Business date {$today} sudah pernah ditutup.", 422, ['businessDate' => ['Tanggal bisnis ini sudah pernah di-close.']]); $pending = (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND DATE(check_out_at) <= ?", [$today]); if ($pending > 0) respond(['message' => 'Proses Night Audit Ditangguhkan! Selesaikan Anomali Pra-Audit ini (Overstay) terlebih dahulu:', 'errors' => ["Terdapat {$pending} tamu overstay yang seharusnya Check-Out hari ini namun sistemnya belum tutup buku (Folio menggantung)."]], 422); $unresolved = (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','draft') AND DATE(check_in_at) <= ?", [$today]); $activeInHouse = (int) db_value($db, "SELECT COUNT(*) FROM bookings WHERE status='checked_in' AND check_in_at <= ? AND check_out_at > ?", [$today . ' 23:59:59', $today . ' 00:00:00']); $paymentSummary = daily_payment_summary($db, $today); $candidates = db_all($db, "SELECT b.id, b.room_amount, b.discount_amount, b.status, COALESCE(i.paid_amount, 0) AS paid_amount FROM bookings b LEFT JOIN invoices i ON i.booking_id = b.id WHERE b.status IN ('confirmed','draft') AND DATE(b.check_in_at) <= ?", [$today]); $processed = 0; $db->beginTransaction(); try { foreach ($candidates as $row) { $booking = db_one($db, "SELECT * FROM bookings WHERE id = ?", [as_int($row['id'])]); if (!$booking) continue; $penalty = booking_cancellation_penalty_amount($db, $booking); if (as_float($row['paid_amount']) > $penalty) continue; $db->prepare("UPDATE bookings SET status='cancelled', updated_at=? WHERE id=?")->execute([now_ts(), $booking['id']]); sync_booking_financial_state($db, as_int($booking['id'])); $processed++; } $nextDate = next_business_date($today); $summary = ['businessDate' => $today, 'nextBusinessDate' => $nextDate, 'pendingCheckouts' => $pending, 'unresolvedArrivals' => $unresolved, 'activeInHouse' => $activeInHouse, 'foliosProcessed' => $processed, 'closingSummary' => $paymentSummary]; $db->prepare("INSERT INTO night_audit_runs (business_date, next_business_date, pending_checkouts, unresolved_arrivals, active_in_house, folios_processed, summary_json, closed_by_user_id, closed_by_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$today, $nextDate, $pending, $unresolved, $activeInHouse, $processed, json_encode($summary, JSON_UNESCAPED_UNICODE), isset($authUser['id']) ? as_int($authUser['id']) : null, trim((string) ($authUser['name'] ?? 'System')) ?: 'System', now_ts(), now_ts()]); upsert_hotel_setting($db, 'last_closed_business_date', $today); upsert_hotel_setting($db, 'current_business_date', $nextDate); $db->commit(); respond(['message' => 'Daily closing completed successfully.', 'details' => "Business date {$today} berhasil ditutup dan digeser ke {$nextDate}.", 'data' => ['folios_processed' => $processed, 'businessDate' => $today, 'nextBusinessDate' => $nextDate, 'currentDateLabel' => build_business_date_label($nextDate), 'summary' => $summary]]); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; } }

    if ($method === 'GET' && $path === 'roles') { $rows = db_all($db, "SELECT * FROM roles ORDER BY id ASC"); respond(array_map(static fn (array $row): array => ['id' => as_int($row['id']), 'name' => $row['name'], 'permissions' => $row['permissions'] ? (json_decode((string) $row['permissions'], true) ?: []) : []], $rows)); }
    if ($method === 'PUT' && ($params = match_route('roles/{id}/permissions', $path)) !== null) { $payload = json_input(); if (!isset($payload['permissions']) || !is_array($payload['permissions'])) fail('Permissions wajib berupa array.', 422, ['permissions' => ['Permissions wajib berupa array.']]); $db->prepare("UPDATE roles SET permissions=?, updated_at=? WHERE id=?")->execute([json_encode(array_values($payload['permissions']), JSON_UNESCAPED_UNICODE), now_ts(), as_int($params['id'])]); respond(['message' => 'Hak akses berhasil diperbarui!']); }
    if ($method === 'GET' && $path === 'users') { $rows = db_all($db, "SELECT u.id, u.name, u.username, u.email, u.is_active, u.role_id, r.name AS role FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.id DESC"); respond(array_map(static fn (array $row): array => ['id' => as_int($row['id']), 'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'], 'is_active' => (bool) as_int($row['is_active']), 'role_id' => as_int($row['role_id']), 'role' => $row['role'] ?: 'frontdesk'], $rows)); }
    if ($method === 'POST' && $path === 'users') { $payload = json_input(); require_fields($payload, ['name', 'username', 'password', 'role_id']); if (db_one($db, "SELECT id FROM users WHERE username = ?", [trim((string) $payload['username'])])) fail('Username sudah digunakan.', 422, ['username' => ['Username sudah digunakan.']]); if (trim((string) ($payload['email'] ?? '')) !== '' && db_one($db, "SELECT id FROM users WHERE email = ?", [trim((string) $payload['email'])])) fail('Email sudah digunakan.', 422, ['email' => ['Email sudah digunakan.']]); $db->prepare("INSERT INTO users (name, username, email, password, role_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)")->execute([trim((string) $payload['name']), trim((string) $payload['username']), trim((string) ($payload['email'] ?? '')) ?: null, password_hash((string) $payload['password'], PASSWORD_BCRYPT), as_int($payload['role_id']), now_ts(), now_ts()]); respond(['message' => 'Akun staf berhasil dibuat!', 'user_id' => (int) $db->lastInsertId()], 201); }
    if ($method === 'PUT' && ($params = match_route('users/{id}', $path)) !== null) { $payload = json_input(); require_fields($payload, ['name', 'username', 'role_id']); $existing = db_one($db, "SELECT id FROM users WHERE username = ? AND id != ?", [trim((string) $payload['username']), as_int($params['id'])]); if ($existing) fail('Username sudah digunakan.', 422, ['username' => ['Username sudah digunakan.']]); if (trim((string) ($payload['email'] ?? '')) !== '') { $existingEmail = db_one($db, "SELECT id FROM users WHERE email = ? AND id != ?", [trim((string) $payload['email']), as_int($params['id'])]); if ($existingEmail) fail('Email sudah digunakan.', 422, ['email' => ['Email sudah digunakan.']]); } $fields = ['name = ?', 'username = ?', 'email = ?', 'role_id = ?', 'updated_at = ?']; $values = [trim((string) $payload['name']), trim((string) $payload['username']), trim((string) ($payload['email'] ?? '')) ?: null, as_int($payload['role_id']), now_ts()]; if (array_key_exists('is_active', $payload)) { $fields[] = 'is_active = ?'; $values[] = $payload['is_active'] ? 1 : 0; } if (trim((string) ($payload['password'] ?? '')) !== '') { $fields[] = 'password = ?'; $values[] = password_hash((string) $payload['password'], PASSWORD_BCRYPT); } $values[] = as_int($params['id']); $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values); respond(['message' => 'Akun staf berhasil diperbarui!']); }
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
} catch (PDOException $e) { fail('Terjadi kesalahan database: ' . $e->getMessage(), 500); }
catch (Throwable $e) { fail('Terjadi kesalahan server: ' . $e->getMessage(), 500); }
