<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Guest;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class SeedController extends Controller
{
    public function seedNightAudit(): JsonResponse
    {
        $today = Carbon::today();
        
        $guest1 = Guest::firstOrCreate(
            ['email' => 'dummy1@example.com'],
            ['full_name' => 'Bapak Budi (In-House)', 'phone' => '0812345678', 'address' => 'Jakarta']
        );
        
        $guest2 = Guest::firstOrCreate(
            ['email' => 'dummy2@example.com'],
            ['full_name' => 'Ibu Sita (No-Show Candidate)', 'phone' => '0887654321', 'address' => 'Bali']
        );

        $room1 = Room::where('status', 'vacant')->first();
        if (!$room1) return response()->json(['error' => 'No vacant rooms available for Budi']);
        
        $room2 = Room::where('status', 'vacant')->where('id', '!=', $room1->id)->first();
        if (!$room2) return response()->json(['error' => 'No vacant rooms available for Sita']);

        // 1. IN-HOUSE GUEST (Valid untuk ditagih oleh Night Audit)
        $booking1 = Booking::create([
            'booking_code' => 'BK-' . time() . '1',
            'guest_id' => $guest1->id,
            'status' => 'checked_in',
            'check_in_at' => $today->copy()->subDay()->format('Y-m-d H:i:s'),
            'check_out_at' => $today->copy()->addDay()->format('Y-m-d H:i:s'),
            'total_guests' => 2,
            'room_amount' => 500000,
            'tax_amount' => 50000,
            'discount_amount' => 0,
            'addon_amount' => 0,
            'grand_total' => 550000,
            'payment_status' => 'unpaid',
            'channel' => 'Walk-In',
            'notes' => 'Tamu In-House, sedang tidur'
        ]);
        BookingRoom::create(['booking_id' => $booking1->id, 'room_id' => $room1->id]);
        Invoice::create([
            'invoice_no' => 'INV-' . time() . '1',
            'booking_id' => $booking1->id,
            'issue_date' => $booking1->check_in_at,
            'due_date' => $booking1->check_out_at,
            'subtotal' => 500000,
            'tax_total' => 50000,
            'discount_total' => 0,
            'grand_total' => 550000,
            'paid_amount' => 0,
            'balance_due' => 550000,
            'status' => 'unpaid',
            'notes' => json_encode(['original' => 'System Generate'])
        ]);
        $room1->update(['status' => 'occupied']);

        // 2. UNRESOLVED ARRIVAL (Gagal Check-In, Akan Di-babat Night Audit)
        $booking2 = Booking::create([
            'booking_code' => 'BK-' . time() . '2',
            'guest_id' => $guest2->id,
            'status' => 'confirmed',
            'check_in_at' => $today->format('Y-m-d H:i:s'),
            'check_out_at' => $today->copy()->addDays(2)->format('Y-m-d H:i:s'),
            'total_guests' => 1,
            'room_amount' => 750000,
            'tax_amount' => 75000,
            'discount_amount' => 0,
            'addon_amount' => 0,
            'grand_total' => 825000,
            'payment_status' => 'unpaid',
            'channel' => 'OTA',
            'notes' => 'Seharusnya tiba hari ini tapi belum muncul'
        ]);
        BookingRoom::create(['booking_id' => $booking2->id, 'room_id' => $room2->id]);
        Invoice::create([
            'invoice_no' => 'INV-' . time() . '2',
            'booking_id' => $booking2->id,
            'issue_date' => $booking2->check_in_at,
            'due_date' => $booking2->check_out_at,
            'subtotal' => 750000,
            'tax_total' => 75000,
            'discount_total' => 0,
            'grand_total' => 825000,
            'paid_amount' => 0,
            'balance_due' => 825000,
            'status' => 'unpaid',
            'notes' => json_encode(['original' => 'System Generate'])
        ]);
        // Kamar tetap vacant karena belum check-in
        
        return response()->json([
            'message' => 'Dummy Data Berhasil Disuntikkan!',
            'data' => [
                'inHouse' => 'Bapak Budi (Room ' . $room1->room_number . ') tidur malam ini.',
                'noShow' => 'Ibu Sita (Room ' . $room2->room_number . ') mendaftar hari ini tapi ga datang.'
            ]
        ]);
    }
}
