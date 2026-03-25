<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingAddonController extends Controller
{
    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function store(Request $request, Booking $booking): JsonResponse
    {
        $payload = $request->validate([
            'addonType' => ['required', 'string', 'in:transport,scooter,island_tour,boat_ticket'],
            'referenceId' => ['nullable', 'string', 'max:50'],
            'serviceName' => ['required', 'string', 'max:255'],
            'addonLabel' => ['required', 'string', 'max:100'],
            'serviceDate' => ['nullable', 'date'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unitPriceValue' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'in:Planned,Confirmed,Posted,Cancelled,planned,confirmed,posted,cancelled,completed'],
            'notes' => ['nullable', 'string'],
        ]);

        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $unitPrice = (float) $payload['unitPriceValue'];
        $addon = $booking->bookingAddons()->create([
            'addon_type' => $payload['addonType'],
            'reference_id' => is_numeric($payload['referenceId'] ?? null) ? (int) $payload['referenceId'] : null,
            'service_date' => $payload['serviceDate'] ?? $payload['startDate'] ?? optional($booking->check_in_at)->format('Y-m-d'),
            'start_date' => $payload['startDate'] ?? $payload['serviceDate'] ?? optional($booking->check_in_at)->format('Y-m-d'),
            'end_date' => $payload['endDate'] ?? null,
            'qty' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
            'status' => $this->normalizeStatus($payload['status']),
            'notes' => json_encode([
                'note' => $payload['notes'] ?? '',
                'serviceName' => $payload['serviceName'],
                'addonLabel' => $payload['addonLabel'],
                'itemRef' => $payload['referenceId'] ?? '',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->refreshBookingTotals($booking);
        $this->auditTrailService->record([
            'module' => 'bookings',
            'action' => 'addon_created',
            'entity_type' => 'booking_addon',
            'entity_id' => $addon->id,
            'entity_label' => $booking->booking_code,
            'description' => "Add-on {$payload['addonLabel']} ditambahkan ke booking {$booking->booking_code}.",
            'metadata' => [
                'booking_id' => $booking->id,
                'addon_type' => $addon->addon_type,
                'status' => $addon->status,
                'total_price' => (float) $addon->total_price,
            ],
        ], $request);

        return response()->json([
            'data' => app(BookingController::class)->show($booking->fresh())->getData(true)['data'],
            'message' => 'Add-on berhasil ditautkan ke reservasi.',
        ], 201);
    }

    public function update(Request $request, Booking $booking, BookingAddon $bookingAddon): JsonResponse
    {
        if ($bookingAddon->booking_id !== $booking->id) {
            abort(404);
        }

        $payload = $request->validate([
            'status' => ['required', 'string', 'in:Planned,Confirmed,Posted,Cancelled,planned,confirmed,posted,cancelled,completed'],
        ]);

        $bookingAddon->update([
            'status' => $this->normalizeStatus($payload['status']),
        ]);

        $this->refreshBookingTotals($booking);
        $this->auditTrailService->record([
            'module' => 'bookings',
            'action' => 'addon_updated',
            'entity_type' => 'booking_addon',
            'entity_id' => $bookingAddon->id,
            'entity_label' => $booking->booking_code,
            'description' => "Status add-on booking {$booking->booking_code} diperbarui menjadi {$bookingAddon->status}.",
            'metadata' => [
                'booking_id' => $booking->id,
                'addon_type' => $bookingAddon->addon_type,
                'status' => $bookingAddon->status,
            ],
        ], $request);

        return response()->json([
            'data' => app(BookingController::class)->show($booking->fresh())->getData(true)['data'],
            'message' => 'Status add-on berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, Booking $booking, BookingAddon $bookingAddon): JsonResponse
    {
        if ($bookingAddon->booking_id !== $booking->id) {
            abort(404);
        }

        $addonType = $bookingAddon->addon_type;
        $addonId = $bookingAddon->id;
        $bookingAddon->delete();
        $this->refreshBookingTotals($booking);
        $this->auditTrailService->record([
            'module' => 'bookings',
            'action' => 'addon_deleted',
            'entity_type' => 'booking_addon',
            'entity_id' => $addonId,
            'entity_label' => $booking->booking_code,
            'description' => "Add-on {$addonType} dihapus dari booking {$booking->booking_code}.",
            'metadata' => [
                'booking_id' => $booking->id,
                'addon_type' => $addonType,
            ],
        ], $request);

        return response()->json([
            'data' => app(BookingController::class)->show($booking->fresh())->getData(true)['data'],
            'message' => 'Add-on berhasil dihapus dari reservasi.',
        ]);
    }

    private function normalizeStatus(string $value): string
    {
        return match (strtolower(trim($value))) {
            'planned' => 'planned',
            'confirmed' => 'confirmed',
            'posted', 'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'planned',
        };
    }

    private function refreshBookingTotals(Booking $booking): void
    {
        app(BookingController::class)->syncBookingFinancialState($booking);
    }
}
