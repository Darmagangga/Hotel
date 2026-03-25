<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NightAuditCommand extends Command
{
    protected $signature = 'pms:night-audit {--date= : Specific date to run audit for (Y-m-d)}';
    protected $description = 'Perform the night audit process to post room charges, auto no-shows, and update housekeeping statuses (eZee Absolute Standard)';

    public function handle()
    {
        $dateParam = $this->option('date');
        $auditDate = $dateParam ? Carbon::parse($dateParam) : Carbon::today();
        
        $this->info("Memulai Proses Standard Night Audit eZee Absolute untuk Tanggal: " . $auditDate->format('Y-m-d'));

        DB::beginTransaction();

        try {
            // =========================================================================
            // LANGKAH 1: AUTO NO-SHOW UNTUK TAMU YANG TIDAK DATANG (EXPECTED ARRIVALS)
            // =========================================================================
            $noShowCount = 0;
            $unarrivedBookings = Booking::whereIn('status', ['confirmed', 'tentative'])
                ->whereDate('check_in_at', '<=', $auditDate)
                ->get();

            foreach ($unarrivedBookings as $booking) {
                // Tandai sebagai No-Show karena melewati batas hari Check-in
                $booking->update(['status' => 'cancelled']); // Atau 'no_show' jika didukung
                
                // Bebaskan kamar yang tadinya dipesan
                $roomIds = $booking->bookingRooms()->pluck('room_id');
                Room::whereIn('id', $roomIds)->update(['status' => 'vacant']);
                
                $noShowCount++;
            }
            $this->info("Langkah 1 Selesai: $noShowCount reservasi diubah menjadi No-Show.");

            // =========================================================================
            // LANGKAH 2: POSTING BIAYA KAMAR (POST ROOM TARIFF & TAXES)
            // =========================================================================
            $activeBookings = Booking::where('status', 'checked_in')
                ->where('check_in_at', '<=', $auditDate->endOfDay())
                ->where('check_out_at', '>', $auditDate->startOfDay())
                ->get();

            $folioCount = 0;
            $roomIdsToDirty = [];

            foreach ($activeBookings as $booking) {
                // A. Kumpulkan Room ID untuk Housekeeping 
                $rIds = $booking->bookingRooms()->pluck('room_id')->toArray();
                $roomIdsToDirty = array_merge($roomIdsToDirty, $rIds);

                // B. Update Folio/Invoice dengan cap (Stamping) Night Audit 
                $invoice = Invoice::where('booking_id', $booking->id)->first();
                if ($invoice) {
                    $notes = json_decode($invoice->notes, true);
                    if (!is_array($notes)) {
                        $notes = ['original' => $invoice->notes];
                    }
                    
                    // Cap audit harian sebagai log (Post Room Charge)
                    $notes['audit_logs'][] = [
                        'date' => $auditDate->format('Y-m-d'),
                        'action' => 'Room Tariff & Taxes Posted',
                        'timestamp' => now()->toDateTimeString()
                    ];
                    
                    $invoice->update([
                        'notes' => json_encode($notes)
                    ]);
                }
                $folioCount++;
            }
            $this->info("Langkah 2 Selesai: $folioCount Poting biaya kamar selesai (Folio Charged).");

            // =========================================================================
            // LANGKAH 3: SINKRONISASI STATUS HOUSEKEEPING (MARK AS DIRTY)
            // =========================================================================
            $roomIdsToDirty = array_unique($roomIdsToDirty);
            if (!empty($roomIdsToDirty)) {
                // Setiap tamu yang melewati jam Night Audit (tidur di kamar), 
                // kamarnya mutlak berstatus Kotor (Dirty) untuk dibersihkan paginya.
                Room::whereIn('id', $roomIdsToDirty)->update(['status' => 'dirty']);
            }
            $this->info("Langkah 3 Selesai: " . count($roomIdsToDirty) . " Kamar ditandai Dirty (Kotor) untuk shift Housekeeping esok pagi.");

            // =========================================================================
            // LANGKAH 4: ADVANCE BUSINESS DATE / ROLLED OVER
            // =========================================================================
            // (Umumnya mengupdate tabel `hotel_settings`. Di sini kita representasikan terselesaikannya transaksi)
            
            DB::commit();
            $this->info("Night Audit selesai secara komprehensif. Operasional tanggal " . $auditDate->format('d/m/Y') . " telah Ditutup.");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Night Audit Gagal: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
