INSERT INTO room_types (code, name, capacity, base_rate, description, created_at, updated_at) VALUES
('DLX-GDN', 'Deluxe Garden', 2, 850000.00, 'Tipe kamar utama untuk 8 kamar operasional.', NOW(), NOW())
ON DUPLICATE KEY UPDATE
name = VALUES(name),
capacity = VALUES(capacity),
base_rate = VALUES(base_rate),
description = VALUES(description),
updated_at = VALUES(updated_at);

INSERT INTO rooms (
    room_type_id,
    room_code,
    room_name,
    floor,
    status,
    coa_receivable_code,
    coa_revenue_code,
    notes,
    created_at,
    updated_at
)
SELECT
    rt.id,
    seed.room_code,
    seed.room_name,
    seed.floor,
    seed.status,
    seed.coa_receivable_code,
    seed.coa_revenue_code,
    seed.notes,
    NOW(),
    NOW()
FROM (
    SELECT '101' AS room_code, 'Deluxe Garden 101' AS room_name, '1' AS floor, 'available' AS status, '112001' AS coa_receivable_code, '411021' AS coa_revenue_code, 'Kamar operasional lantai 1' AS notes
    UNION ALL SELECT '102', 'Deluxe Garden 102', '1', 'available', '112001', '411021', 'Kamar operasional lantai 1'
    UNION ALL SELECT '103', 'Deluxe Garden 103', '1', 'available', '112001', '411021', 'Kamar operasional lantai 1'
    UNION ALL SELECT '104', 'Deluxe Garden 104', '1', 'available', '112001', '411021', 'Kamar operasional lantai 1'
    UNION ALL SELECT '105', 'Deluxe Garden 105', '2', 'available', '112001', '411021', 'Kamar operasional lantai 2'
    UNION ALL SELECT '106', 'Deluxe Garden 106', '2', 'available', '112001', '411021', 'Kamar operasional lantai 2'
    UNION ALL SELECT '107', 'Deluxe Garden 107', '2', 'available', '112001', '411021', 'Kamar operasional lantai 2'
    UNION ALL SELECT '108', 'Deluxe Garden 108', '2', 'available', '112001', '411021', 'Kamar operasional lantai 2'
) AS seed
INNER JOIN room_types rt ON rt.code = 'DLX-GDN'
ON DUPLICATE KEY UPDATE
room_type_id = VALUES(room_type_id),
room_name = VALUES(room_name),
floor = VALUES(floor),
status = VALUES(status),
coa_receivable_code = VALUES(coa_receivable_code),
coa_revenue_code = VALUES(coa_revenue_code),
notes = VALUES(notes),
updated_at = VALUES(updated_at);
