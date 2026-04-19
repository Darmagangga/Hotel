<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettingsController extends Controller
{
    private const SETTINGS_CACHE_KEY = 'pms.settings.policies';

    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function policies(): JsonResponse
    {
        return response()->json([
            'data' => [
                'cancellationPolicy' => $this->cancellationPolicyPayload(),
            ],
        ]);
    }

    public function updatePolicies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cancellationPenaltyPercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $percent = round((float) $validated['cancellationPenaltyPercent'], 2);

        Cache::forever(self::SETTINGS_CACHE_KEY, [
            'cancellationPenaltyPercent' => $percent,
        ]);

        return response()->json([
            'message' => 'Booking policy settings updated successfully.',
            'data' => [
                'cancellationPolicy' => $this->cancellationPolicyPayload($percent),
            ],
        ]);
    }

    public function resetTransactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'in:RESET'],
        ]);

        DB::transaction(function () {
            $this->deleteIfExists('vendor_payment_allocations');
            $this->deleteIfExists('vendor_payments');
            $this->deleteIfExists('vendor_bills');

            $this->deleteIfExists('payment_allocations');
            $this->deleteIfExists('payments');

            $this->deleteIfExists('journal_lines');
            $this->deleteIfExists('journals');

            $this->deleteIfExists('booking_addons');
            $this->deleteIfExists('booking_rooms');
            $this->deleteIfExists('invoices');
            $this->deleteIfExists('bookings');
            $this->deleteIfExists('guests');

            $this->deleteIfExists('inventory_movements');
            $this->deleteIfExists('housekeeping_tasks');
            $this->deleteIfExists('night_audit_runs');
            $this->deleteIfExists('audit_trails');

            if (Schema::hasTable('rooms')) {
                DB::table('rooms')
                    ->whereIn('status', ['occupied', 'dirty', 'cleaning'])
                    ->update(['status' => 'available']);
            }
        });

        $this->auditTrailService->record([
            'module' => 'settings',
            'action' => 'transactions_reset',
            'entity_type' => 'system',
            'entity_id' => 'transactions',
            'entity_label' => 'All Transactions',
            'description' => 'Semua transaksi operasional direset dari halaman Settings.',
            'metadata' => [
                'confirmation' => $validated['confirmation'],
            ],
        ], $request);

        return response()->json([
            'message' => 'Semua transaksi operasional berhasil direset. Master data tetap dipertahankan.',
        ]);
    }

    private function cancellationPolicyPayload(?float $percent = null): array
    {
        $settings = Cache::get(self::SETTINGS_CACHE_KEY, []);
        $resolvedPercent = $percent ?? (float) ($settings['cancellationPenaltyPercent'] ?? 0);

        return [
            'percent' => $resolvedPercent,
            'label' => rtrim(rtrim(number_format($resolvedPercent, 2, '.', ''), '0'), '.'),
            'enabled' => $resolvedPercent > 0,
        ];
    }

    private function deleteIfExists(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->delete();
    }
}
