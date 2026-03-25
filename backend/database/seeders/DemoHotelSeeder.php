<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoHotelSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $roles = $this->seedRoles();
            $users = $this->seedUsers($roles);
            $this->seedCoa();
            $roomTypes = $this->seedRoomTypes();
            $rooms = $this->seedRooms($roomTypes);
            $guests = $this->seedGuests();
            $catalogs = $this->seedCatalogs();
            $this->seedInventory();
            $bookings = $this->seedBookings($guests, $rooms, $users);
            $this->seedAddons($bookings, $catalogs);
            $this->refreshBookingTotals($bookings);
            $invoices = $this->seedInvoices($bookings);
            $payments = $this->seedPayments($guests);
            $this->seedAllocations($payments, $invoices);
            $this->seedJournals($bookings, $payments);
        });
    }

    private function seedRoles(): array
    {
        $rows = [
            'admin' => ['dashboard', 'bookings', 'rooms', 'finance', 'journals', 'coa', 'inventory', 'transport', 'activities', 'reports', 'users', 'roles'],
            'frontdesk' => ['dashboard', 'bookings', 'rooms', 'activities'],
            'housekeeping' => ['rooms', 'inventory'],
        ];

        foreach ($rows as $name => $permissions) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                ['permissions' => json_encode($permissions, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return DB::table('roles')->pluck('id', 'name')->all();
    }

    private function seedUsers(array $roles): array
    {
        $rows = [
            'admin@sagarabay.com' => ['General Manager', 'admin123', $roles['admin'] ?? null],
            'fo@sagarabay.com' => ['Front Office Demo', 'fo123', $roles['frontdesk'] ?? null],
            'hk@sagarabay.com' => ['Housekeeping Demo', 'hk123', $roles['housekeeping'] ?? null],
        ];

        foreach ($rows as $email => [$name, $password, $roleId]) {
            DB::table('users')->updateOrInsert(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make($password), 'role_id' => $roleId, 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return DB::table('users')->pluck('id', 'email')->all();
    }

    private function seedCoa(): void
    {
        foreach ([
            ['111001', 'Kas Utama', 'asset', 'debit'],
            ['111005', 'Bank Transfer BCA', 'asset', 'debit'],
            ['112001', 'Piutang Dagang', 'asset', 'debit'],
            ['115001', 'Persediaan Barang', 'asset', 'debit'],
            ['411018', 'Pendapatan Airbnb', 'revenue', 'credit'],
            ['411021', 'Pendapatan Kamar Direct', 'revenue', 'credit'],
            ['411023', 'Pendapatan Booking.com', 'revenue', 'credit'],
            ['510001', 'Pendapatan Transport', 'revenue', 'credit'],
            ['510002', 'Pendapatan Scooter', 'revenue', 'credit'],
            ['510004', 'Pendapatan Boat Ticket', 'revenue', 'credit'],
            ['510005', 'Pendapatan Island Tour', 'revenue', 'credit'],
            ['610011', 'Biaya Amenities', 'expense', 'debit'],
            ['610019', 'Biaya Perlengkapan Kamar', 'expense', 'debit'],
        ] as [$code, $name, $category, $balance]) {
            DB::table('coa_accounts')->updateOrInsert(
                ['code' => $code],
                ['account_name' => $name, 'category' => $category, 'normal_balance' => $balance, 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function seedRoomTypes(): array
    {
        $rows = [
            'DLX-GDN' => ['Deluxe Garden', 2, 850000],
            'DLX-POOL' => ['Deluxe Pool View', 2, 975000],
            'FAM-SUI' => ['Family Suite', 4, 1450000],
        ];

        foreach ($rows as $code => [$name, $capacity, $rate]) {
            DB::table('room_types')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'capacity' => $capacity, 'base_rate' => $rate, 'description' => 'Seeded demo room type.', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return DB::table('room_types')->pluck('id', 'code')->all();
    }

    private function seedRooms(array $roomTypes): array
    {
        $rows = [
            '101' => ['DLX-GDN', 'Deluxe Garden 101', '1', 'occupied'],
            '102' => ['DLX-GDN', 'Deluxe Garden 102', '1', 'available'],
            '103' => ['DLX-GDN', 'Deluxe Garden 103', '1', 'dirty'],
            '201' => ['DLX-POOL', 'Deluxe Pool 201', '2', 'available'],
            '202' => ['DLX-POOL', 'Deluxe Pool 202', '2', 'available'],
            '301' => ['FAM-SUI', 'Family Suite 301', '3', 'available'],
            '302' => ['FAM-SUI', 'Family Suite 302', '3', 'blocked'],
        ];

        foreach ($rows as $code => [$type, $name, $floor, $status]) {
            DB::table('rooms')->updateOrInsert(
                ['room_code' => $code],
                [
                    'room_type_id' => $roomTypes[$type] ?? null,
                    'room_name' => $name,
                    'floor' => $floor,
                    'status' => $status,
                    'coa_receivable_code' => '112001',
                    'coa_revenue_code' => '411021',
                    'notes' => 'Seeded demo room.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return DB::table('rooms')->pluck('id', 'room_code')->all();
    }

    private function seedGuests(): array
    {
        $rows = [
            'GST-DEMO-001' => ['Alexander Wijaya', 'alex.demo@guest.test', '081234560001', 'Indonesia'],
            'GST-DEMO-002' => ['Maria Lestari', 'maria.demo@guest.test', '081234560002', 'Indonesia'],
            'GST-DEMO-003' => ['Kenji Nakamura', 'kenji.demo@guest.test', '+81-90-555-0199', 'Japan'],
            'GST-DEMO-004' => ['Siti Rahma', 'siti.demo@guest.test', '081234560004', 'Indonesia'],
        ];

        foreach ($rows as $code => [$name, $email, $phone, $nationality]) {
            DB::table('guests')->updateOrInsert(
                ['guest_code' => $code],
                [
                    'full_name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'identity_type' => 'KTP',
                    'identity_number' => $code,
                    'nationality' => $nationality,
                    'address' => 'Seeded demo guest',
                    'notes' => 'Seeded for demo presentation.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return DB::table('guests')->pluck('id', 'guest_code')->all();
    }

    private function seedCatalogs(): array
    {
        DB::table('transport_rates')->updateOrInsert(
            ['driver' => 'Made Airport Transfer'],
            ['pickup_price_value' => 250000, 'drop_off_price_value' => 225000, 'vehicle' => 'Toyota Avanza', 'note' => 'Airport transfer.', 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('activity_operator_catalog')->updateOrInsert(
            ['operator' => 'Blue Lagoon Adventures'],
            ['price_value' => 150000, 'note' => 'Main activity partner.', 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('activity_operators')->updateOrInsert(
            ['operator_name' => 'Blue Lagoon Adventures'],
            ['default_price' => 150000, 'notes' => 'Main activity partner.', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('transport_drivers')->updateOrInsert(
            ['driver_name' => 'Wayan Tour Driver'],
            ['vehicle_name' => 'Toyota Avanza', 'pickup_price' => 250000, 'dropoff_price' => 225000, 'notes' => 'Driver untuk transfer dan tur.', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('boat_companies')->updateOrInsert(
            ['company_name' => 'Gili Fast Boat'],
            ['notes' => 'Partner boat transfer.', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );

        $operatorId = DB::table('activity_operators')->where('operator_name', 'Blue Lagoon Adventures')->value('id');
        $driverId = DB::table('transport_drivers')->where('driver_name', 'Wayan Tour Driver')->value('id');
        $companyId = DB::table('boat_companies')->where('company_name', 'Gili Fast Boat')->value('id');

        DB::table('scooter_catalog')->updateOrInsert(
            ['scooter_type' => 'Honda Scoopy'],
            ['operator_id' => $operatorId, 'daily_price' => 110000, 'notes' => 'Helmet included.', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('island_tours')->updateOrInsert(
            ['destination' => 'Kelingking Beach'],
            ['driver_id' => $driverId, 'price' => 650000, 'notes' => 'Full day trip.', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('boat_tickets')->updateOrInsert(
            ['destination' => 'Sanur Harbour'],
            ['company_id' => $companyId, 'ticket_price' => 275000, 'notes' => 'Fast boat demo ticket.', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('island_tour_catalog')->updateOrInsert(
            ['destination' => 'Kelingking Beach'],
            ['driver' => 'Wayan Tour Driver', 'cost_value' => 650000, 'note' => 'Full day trip.', 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('boat_ticket_catalog')->updateOrInsert(
            ['company' => 'Gili Fast Boat', 'destination' => 'Sanur Harbour'],
            ['price_value' => 275000, 'updated_at' => now(), 'created_at' => now()],
        );

        return [
            'transport' => DB::table('transport_rates')->where('driver', 'Made Airport Transfer')->value('id'),
            'scooter' => DB::table('scooter_catalog')->where('scooter_type', 'Honda Scoopy')->value('id'),
            'tour' => DB::table('island_tour_catalog')->where('destination', 'Kelingking Beach')->value('id'),
            'boat' => DB::table('boat_ticket_catalog')->where('destination', 'Sanur Harbour')->value('id'),
        ];
    }

    private function seedInventory(): void
    {
        $items = [
            'INV-AMN-001' => ['Shampoo Sachet', 'consumable', 'pcs', 3500, 40, '610011'],
            'INV-AMN-002' => ['Bath Soap', 'consumable', 'pcs', 3000, 40, '610011'],
            'INV-LIN-001' => ['Bath Towel', 'linen', 'pcs', 45000, 12, '610019'],
        ];

        foreach ($items as $sku => [$name, $category, $unit, $cost, $min, $expense]) {
            DB::table('inventory_items')->updateOrInsert(
                ['sku' => $sku],
                [
                    'item_name' => $name,
                    'category' => $category,
                    'unit' => $unit,
                    'standard_cost' => $cost,
                    'min_stock' => $min,
                    'inventory_coa_code' => '115001',
                    'expense_coa_code' => $expense,
                    'notes' => 'Seeded demo inventory item.',
                    'is_active' => 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $itemId = DB::table('inventory_items')->where('sku', 'INV-AMN-001')->value('id');
        DB::table('inventory_movements')->updateOrInsert(
            ['item_id' => $itemId, 'movement_date' => '2026-03-18 09:00:00', 'movement_type' => 'purchase'],
            ['qty_in' => 120, 'qty_out' => 0, 'unit_cost' => 3500, 'reference_type' => 'purchase', 'reference_id' => 1, 'notes' => 'Seeded stock purchase.', 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('inventory_movements')->updateOrInsert(
            ['item_id' => $itemId, 'movement_date' => '2026-03-22 15:10:00', 'movement_type' => 'issue_room'],
            ['qty_in' => 0, 'qty_out' => 4, 'unit_cost' => 3500, 'reference_type' => 'room', 'reference_id' => 101, 'notes' => 'Seeded room issue.', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function seedBookings(array $guests, array $rooms, array $users): array
    {
        $creator = $users['fo@sagarabay.com'] ?? null;
        $rows = [
            'BK-DEMO-260323-001' => ['GST-DEMO-001', 'direct', 'checked_in', '2026-03-22 14:00:00', '2026-03-25 12:00:00', 2550000, 0, '101', 2, 0, 850000, 'VIP in-house guest.'],
            'BK-DEMO-260323-002' => ['GST-DEMO-002', 'booking.com', 'confirmed', '2026-03-23 15:00:00', '2026-03-26 12:00:00', 2925000, 0, '201', 2, 0, 975000, 'Arrival today with planned tour.'],
            'BK-DEMO-260323-003' => ['GST-DEMO-003', 'airbnb', 'checked_out', '2026-03-20 14:00:00', '2026-03-22 12:00:00', 1700000, 0, '103', 2, 0, 850000, 'Completed stay.'],
            'BK-DEMO-260323-004' => ['GST-DEMO-004', 'traveloka', 'draft', '2026-03-28 14:00:00', '2026-03-30 12:00:00', 2900000, 150000, '301', 2, 2, 1450000, 'Tentative family booking.'],
        ];

        foreach ($rows as $code => [$guestCode, $source, $status, $checkIn, $checkOut, $roomAmount, $discount, $roomCode, $adults, $children, $rate, $notes]) {
            DB::table('bookings')->updateOrInsert(
                ['booking_code' => $code],
                [
                    'guest_id' => $guests[$guestCode] ?? null,
                    'source' => $source,
                    'status' => $status,
                    'check_in_at' => $checkIn,
                    'check_out_at' => $checkOut,
                    'room_amount' => $roomAmount,
                    'addon_amount' => 0,
                    'discount_amount' => $discount,
                    'tax_amount' => 0,
                    'grand_total' => $roomAmount - $discount,
                    'notes' => $notes,
                    'created_by' => $creator,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $bookingId = DB::table('bookings')->where('booking_code', $code)->value('id');
            DB::table('booking_rooms')->updateOrInsert(
                ['booking_id' => $bookingId, 'room_id' => $rooms[$roomCode] ?? null],
                ['adult_count' => $adults, 'child_count' => $children, 'rate' => $rate, 'check_in_at' => $checkIn, 'check_out_at' => $checkOut, 'notes' => 'Seeded room allocation.', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return DB::table('bookings')->pluck('id', 'booking_code')->all();
    }

    private function seedAddons(array $bookings, array $catalogs): void
    {
        $rows = [
            ['BK-DEMO-260323-001', 'transport', $catalogs['transport'], '2026-03-22', null, null, 1, 250000, 250000, 'completed', ['serviceName' => 'Airport Pickup']],
            ['BK-DEMO-260323-001', 'scooter', $catalogs['scooter'], null, '2026-03-23', '2026-03-24', 2, 112500, 225000, 'confirmed', ['serviceName' => 'Honda Scoopy']],
            ['BK-DEMO-260323-002', 'island_tour', $catalogs['tour'], '2026-03-24', null, null, 1, 650000, 650000, 'planned', ['serviceName' => 'Kelingking Beach']],
            ['BK-DEMO-260323-003', 'boat_ticket', $catalogs['boat'], '2026-03-22', null, null, 1, 275000, 275000, 'completed', ['serviceName' => 'Sanur Harbour']],
        ];

        foreach ($rows as [$bookingCode, $type, $referenceId, $serviceDate, $startDate, $endDate, $qty, $unitPrice, $total, $status, $notes]) {
            DB::table('booking_addons')->updateOrInsert(
                ['booking_id' => $bookings[$bookingCode] ?? null, 'addon_type' => $type, 'reference_id' => $referenceId, 'service_date' => $serviceDate, 'start_date' => $startDate, 'end_date' => $endDate],
                ['qty' => $qty, 'unit_price' => $unitPrice, 'total_price' => $total, 'status' => $status, 'notes' => json_encode($notes, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function refreshBookingTotals(array $bookings): void
    {
        foreach ($bookings as $id) {
            $booking = DB::table('bookings')->where('id', $id)->first();
            $addon = (float) DB::table('booking_addons')->where('booking_id', $id)->sum('total_price');
            DB::table('bookings')->where('id', $id)->update([
                'addon_amount' => $addon,
                'grand_total' => (float) $booking->room_amount + $addon - (float) $booking->discount_amount + (float) $booking->tax_amount,
                'updated_at' => now(),
            ]);
        }
    }

    private function seedInvoices(array $bookings): array
    {
        $rows = [
            'INV-DEMO-260323-001' => ['BK-DEMO-260323-001', '2026-03-22', '2026-03-25', 'partial', 1500000],
            'INV-DEMO-260323-002' => ['BK-DEMO-260323-002', '2026-03-23', '2026-03-26', 'partial', 500000],
            'INV-DEMO-260323-003' => ['BK-DEMO-260323-003', '2026-03-20', '2026-03-22', 'paid', null],
            'INV-DEMO-260323-004' => ['BK-DEMO-260323-004', '2026-03-23', '2026-03-28', 'draft', 0],
        ];

        foreach ($rows as $invoiceNumber => [$bookingCode, $date, $due, $status, $paid]) {
            $booking = DB::table('bookings')->where('id', $bookings[$bookingCode] ?? 0)->first();
            if (!$booking) {
                continue;
            }

            $paidAmount = $paid ?? (float) $booking->grand_total;
            DB::table('invoices')->updateOrInsert(
                ['invoice_number' => $invoiceNumber],
                [
                    'booking_id' => $booking->id,
                    'invoice_date' => $date,
                    'due_date' => $due,
                    'subtotal' => $booking->room_amount,
                    'discount_amount' => $booking->discount_amount,
                    'tax_amount' => $booking->tax_amount,
                    'grand_total' => $booking->grand_total,
                    'paid_amount' => $paidAmount,
                    'balance_due' => max(0, (float) $booking->grand_total - $paidAmount),
                    'status' => $status,
                    'notes' => 'Seeded demo invoice.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return DB::table('invoices')->pluck('id', 'invoice_number')->all();
    }

    private function seedPayments(array $guests): array
    {
        $rows = [
            'PAY-DEMO-260323-001' => ['GST-DEMO-001', '2026-03-22', 'bank_transfer', 1500000, '111005', 'TF-ALX-001'],
            'PAY-DEMO-260323-002' => ['GST-DEMO-002', '2026-03-23', 'qris', 500000, '111005', 'QR-MAR-002'],
            'PAY-DEMO-260323-003' => ['GST-DEMO-003', '2026-03-22', 'cash', 1975000, '111001', 'CSH-KNJ-003'],
        ];

        foreach ($rows as $number => [$guestCode, $date, $method, $amount, $coa, $ref]) {
            DB::table('payments')->updateOrInsert(
                ['payment_number' => $number],
                ['guest_id' => $guests[$guestCode] ?? null, 'payment_date' => $date, 'payment_method' => $method, 'amount' => $amount, 'cash_bank_coa_code' => $coa, 'reference_number' => $ref, 'notes' => 'Seeded demo payment.', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return DB::table('payments')->pluck('id', 'payment_number')->all();
    }

    private function seedAllocations(array $payments, array $invoices): void
    {
        $rows = [
            ['PAY-DEMO-260323-001', 'INV-DEMO-260323-001', 1500000],
            ['PAY-DEMO-260323-002', 'INV-DEMO-260323-002', 500000],
            ['PAY-DEMO-260323-003', 'INV-DEMO-260323-003', 1975000],
        ];

        foreach ($rows as [$payment, $invoice, $amount]) {
            DB::table('payment_allocations')->updateOrInsert(
                ['payment_id' => $payments[$payment] ?? null, 'invoice_id' => $invoices[$invoice] ?? null],
                ['allocated_amount' => $amount, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function seedJournals(array $bookings, array $payments): void
    {
        $journals = [
            'JU-DEMO-260322-001' => ['2026-03-22', 'booking', $bookings['BK-DEMO-260323-001'] ?? null, 'Invoice demo booking 1', 'invoice', [['112001', 3025000, 0], ['411021', 0, 2550000], ['510001', 0, 250000], ['510002', 0, 225000]]],
            'JU-DEMO-260323-002' => ['2026-03-23', 'booking', $bookings['BK-DEMO-260323-002'] ?? null, 'Invoice demo booking 2', 'invoice', [['112001', 3575000, 0], ['411023', 0, 2925000], ['510005', 0, 650000]]],
            'JU-DEMO-260320-003' => ['2026-03-20', 'booking', $bookings['BK-DEMO-260323-003'] ?? null, 'Invoice demo booking 3', 'invoice', [['112001', 1975000, 0], ['411018', 0, 1700000], ['510004', 0, 275000]]],
            'JU-DEMO-260322-101' => ['2026-03-22', 'payment', $payments['PAY-DEMO-260323-001'] ?? null, 'Payment demo 1', 'payment', [['111005', 1500000, 0], ['112001', 0, 1500000]]],
            'JU-DEMO-260323-102' => ['2026-03-23', 'payment', $payments['PAY-DEMO-260323-002'] ?? null, 'Payment demo 2', 'payment', [['111005', 500000, 0], ['112001', 0, 500000]]],
        ];

        foreach ($journals as $number => [$date, $type, $refId, $description, $source, $lines]) {
            if (!$refId) {
                continue;
            }

            DB::table('journals')->updateOrInsert(
                ['journal_number' => $number],
                ['journal_date' => $date, 'reference_type' => $type, 'reference_id' => $refId, 'description' => $description, 'source' => $source, 'posted_by' => null, 'updated_at' => now(), 'created_at' => now()],
            );

            $journalId = DB::table('journals')->where('journal_number', $number)->value('id');
            DB::table('journal_lines')->where('journal_id', $journalId)->delete();

            foreach ($lines as [$coa, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'journal_id' => $journalId,
                    'coa_code' => $coa,
                    'line_description' => "Seeded line {$coa}",
                    'debit' => $debit,
                    'credit' => $credit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
