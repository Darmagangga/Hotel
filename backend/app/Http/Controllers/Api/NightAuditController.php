<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use App\Models\Booking;

class NightAuditController extends Controller
{
    public function status(): JsonResponse
    {
        $today = \Carbon\Carbon::today();

        $pendingCheckouts = Booking::where('status', 'checked_in')
            ->whereDate('check_out_at', '<=', $today)
            ->count();

        $unresolvedArrivals = Booking::whereIn('status', ['confirmed', 'tentative'])
            ->whereDate('check_in_at', '<=', $today)
            ->count();

        $activeInHouse = Booking::where('status', 'checked_in')
            ->where('check_in_at', '<=', $today->endOfDay())
            ->where('check_out_at', '>', $today->startOfDay())
            ->count();

        return response()->json([
            'pending_checkouts' => $pendingCheckouts,
            'unresolved_arrivals' => $unresolvedArrivals,
            'active_in_house' => $activeInHouse,
            'audit_date' => $today->format('d M Y'),
        ]);
    }

    public function trigger(): JsonResponse
    {
        // ---------------------------------------------------------
        // 1. PRE-AUDIT CHECKS (Pengecekan Pra-Audit)
        // ---------------------------------------------------------
        $today = \Carbon\Carbon::today();

        // Cek 1: Tamu Overstay (Seharusnya pulang hari ini, tapi belum Check-Out)
        // INI MUTLAK MENGHALANGI NIGHT AUDIT KARENA SISTEM TIDAK BOLEH MENGUSIR TAMU TANPA PEMBAYARAN TUNTAS.
        $pendingCheckouts = Booking::where('status', 'checked_in')
            ->whereDate('check_out_at', '<=', $today)
            ->count();

        // Reservasi gantung (Belum Tiba) tidak lagi menghalangi, 
        // karena sistem pms:night-audit akan secara absolut membabat mereka menjadi NO-SHOW!

        if ($pendingCheckouts > 0) {
            return response()->json([
                'message' => 'Proses Night Audit Ditangguhkan! Selesaikan Anomali Pra-Audit ini (Overstay) terlebih dahulu:',
                'errors' => ["Terdapat $pendingCheckouts tamu overstay yang seharusnya Check-Out hari ini namun sistemnya belum tutup buku (Folio menggantung)."]
            ], 422);
        }

        // ---------------------------------------------------------
        // 2. JALANKAN PROSES NIGHT AUDIT
        // ---------------------------------------------------------
        try {
            Artisan::call('pms:night-audit');
            $output = Artisan::output();
            
            $processedCount = Booking::where('status', 'checked_in')->count();

            return response()->json([
                'message' => 'Proses Night Audit berhasil diluncurkan dan diselesaikan (Tutup Buku Harian selesai).',
                'details' => $output,
                'data' => [
                    'folios_processed' => $processedCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menjalankan Night Audit secara otomatis.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
