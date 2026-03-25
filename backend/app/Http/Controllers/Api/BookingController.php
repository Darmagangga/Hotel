<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingRoom;
use App\Models\CoaAccount;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Room;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 100);
        $search = trim((string) $request->string('search', ''));

        $bookings = Booking::query()
            ->with(['guest:id,full_name,email,phone', 'bookingRooms.room.roomType:id,name', 'bookingAddons'])
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($nested) use ($search) {
                    $nested
                        ->where('booking_code', 'like', "%{$search}%")
                        ->orWhere('source', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhereHas('guest', fn ($guest) => $guest
                            ->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('bookingRooms.room', fn ($room) => $room->where('room_code', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (Booking $booking) => $this->transformBooking($booking));

        return response()->json([
            'data' => $bookings->items(),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
            ],
        ]);
    }

    public function show(Booking $booking): JsonResponse
    {
        return response()->json([
            'data' => $this->transformBooking($booking->load(['guest:id,full_name,email,phone', 'bookingRooms.room.roomType:id,name', 'bookingAddons'])),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);

        $booking = DB::transaction(function () use ($payload) {
            $guest = $this->upsertGuest(null, $payload);
            $code = $this->generateBookingCode($payload['checkIn']);
            $roomAmount = $this->calculateRoomAmount($payload['roomDetails'], $payload['checkIn'], $payload['checkOut']);

            $booking = Booking::create([
                'booking_code' => $code,
                'guest_id' => $guest->id,
                'source' => $this->normalizeSource($payload['channel']),
                'status' => $this->normalizeStatus($payload['status']),
                'check_in_at' => $payload['checkIn'],
                'check_out_at' => $payload['checkOut'],
                'room_amount' => $roomAmount,
                'addon_amount' => 0,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'grand_total' => $roomAmount,
                'notes' => $payload['note'] ?? null,
            ]);

            \App\Models\Invoice::create([
                'booking_id' => $booking->id,
                'invoice_number' => 'INV-' . str_replace('BK-', '', $code),
                'invoice_date' => now()->toDateString(),
                'due_date' => $payload['checkOut'],
                'subtotal' => $roomAmount,
                'grand_total' => $roomAmount,
                'paid_amount' => 0,
                'balance_due' => $roomAmount,
                'status' => 'unpaid',
            ]);

            $this->syncBookingRooms($booking, $payload['roomDetails'], $payload['checkIn'], $payload['checkOut']);
            $this->syncBookingFinancialState($booking);

            return $booking->load(['guest:id,full_name,email,phone', 'bookingRooms.room.roomType:id,name', 'bookingAddons']);
        });

        $this->auditTrailService->record([
            'module' => 'bookings',
            'action' => 'created',
            'entity_type' => 'booking',
            'entity_id' => $booking->id,
            'entity_label' => $booking->booking_code,
            'description' => "Booking {$booking->booking_code} dibuat untuk {$booking->guest?->full_name}.",
            'metadata' => [
                'status' => $booking->status,
                'source' => $booking->source,
                'grand_total' => (float) $booking->grand_total,
            ],
        ], $request);

        return response()->json([
            'data' => $this->transformBooking($booking),
            'message' => 'Reservasi berhasil disimpan ke database.',
        ], 201);
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $payload = $this->validatePayload($request, $booking);

        $booking = DB::transaction(function () use ($payload, $booking) {
            $guest = $this->upsertGuest($booking->guest, $payload);
            $roomAmount = $this->calculateRoomAmount($payload['roomDetails'], $payload['checkIn'], $payload['checkOut']);

            $booking->update([
                'guest_id' => $guest->id,
                'source' => $this->normalizeSource($payload['channel']),
                'status' => $this->normalizeStatus($payload['status']),
                'check_in_at' => $payload['checkIn'],
                'check_out_at' => $payload['checkOut'],
                'room_amount' => $roomAmount,
                'grand_total' => $roomAmount + (float) $booking->addon_amount - (float) $booking->discount_amount + (float) $booking->tax_amount,
                'notes' => $payload['note'] ?? null,
            ]);

            $this->syncBookingRooms($booking, $payload['roomDetails'], $payload['checkIn'], $payload['checkOut']);
            $this->syncBookingFinancialState($booking);

            return $booking->fresh(['guest:id,full_name,email,phone', 'bookingRooms.room.roomType:id,name', 'bookingAddons']);
        });

        $this->auditTrailService->record([
            'module' => 'bookings',
            'action' => 'updated',
            'entity_type' => 'booking',
            'entity_id' => $booking->id,
            'entity_label' => $booking->booking_code,
            'description' => "Booking {$booking->booking_code} diperbarui.",
            'metadata' => [
                'status' => $booking->status,
                'source' => $booking->source,
                'grand_total' => (float) $booking->grand_total,
            ],
        ], $request);

        return response()->json([
            'data' => $this->transformBooking($booking),
            'message' => 'Reservasi berhasil diperbarui.',
        ]);
    }

    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $payload = $request->validate([
            'status' => ['required', 'string', 'in:Checked-in,Checked-out,checked_in,checked_out'],
        ]);

        $targetStatus = $this->normalizeStatus($payload['status']);
        $currentStatus = $booking->status;

        if ($targetStatus === 'checked_in' && !in_array($currentStatus, ['draft', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Hanya booking Tentative atau Confirmed yang bisa di-check-in.'],
            ]);
        }

        if ($targetStatus === 'checked_out') {
            if ($currentStatus !== 'checked_in') {
                throw ValidationException::withMessages([
                    'status' => ['Hanya booking Checked-in yang bisa di-check-out.'],
                ]);
            }

            $invoice = \App\Models\Invoice::where('booking_id', $booking->id)->first();
            if ($invoice && $invoice->balance_due > 0) {
                throw ValidationException::withMessages([
                    'status' => ['Tidak bisa Check-out. Tamu belum melunasi tagihan (Sisa: IDR ' . number_format($invoice->balance_due, 0, ',', '.') . '). Silakan lakukan pembayaran terlebih dahulu di menu Finance / Folio.'],
                ]);
            }
        }

        DB::transaction(function () use ($booking, $targetStatus, $currentStatus) {
            $booking->update([
                'status' => $targetStatus,
            ]);

            $roomStatus = match ($targetStatus) {
                'checked_in' => 'occupied',
                'checked_out' => 'dirty',
                default => null,
            };

            if ($roomStatus) {
                $roomIds = $booking->bookingRooms()->pluck('room_id');
                Room::query()
                    ->whereIn('id', $roomIds)
                    ->update(['status' => $roomStatus]);
            }

            // --- INVENTORY AUTOMATION LOGIC ---
            // Saat tamu sah Check-In, sistem otomatis menarik barang logistik (shampo, sabun)
            // mengurangi stok, dan menjurnal pengeluaran sesuai HPP barang!
            if ($targetStatus === 'checked_in' && $currentStatus !== 'checked_in') {
                $rooms = $booking->bookingRooms()->with('room.roomType')->get();
                
                foreach ($rooms as $br) {
                    $roomTypeCode = $br->room->roomType->code ?? null;
                    if ($roomTypeCode) {
                        $amenities = \App\Models\RoomTypeAmenity::where('room_type_code', $roomTypeCode)
                            ->with('inventoryItem')
                            ->get();
                            
                        foreach ($amenities as $amenity) {
                            $item = $amenity->inventoryItem;
                            if ($item && $item->track_quantity) {
                                // 1. Kurangi Stok Gudang
                                \App\Models\InventoryMovement::create([
                                    'item_id' => $item->id,
                                    'movement_type' => 'OUT',
                                    'quantity' => $amenity->quantity,
                                    'reference_id' => $booking->booking_code,
                                    'reference_type' => 'booking_checkin',
                                    'reference_desc' => "Pemakaian Amenities Check-In: Kamar {$br->room->room_number}",
                                    'movement_date' => date('Y-m-d'),
                                    'cost_per_unit' => $item->latest_cost,
                                    'source' => 'system'
                                ]);
                                
                                // 2. Posting Jurnal Laba Rugi Otomatis (HPP)
                                if ($item->expense_coa_code && $item->inventory_coa_code) {
                                    $totalCost = $amenity->quantity * $item->latest_cost;
                                    
                                    if ($totalCost > 0) {
                                        // Generate ID Manual sementara (untuk fallback bila class Journal tak ada generateNumber)
                                        $journal = \App\Models\Journal::create([
                                            'journal_number' => 'JU-' . date('Ymd-His') . '-' . rand(10,99),
                                            'journal_date' => date('Y-m-d'),
                                            'reference_no' => $booking->booking_code,
                                            'description' => "Otomatisasi HPP Perlengkapan Tamu Kamar {$br->room->room_number}",
                                        ]);
                                        
                                        // Debit: Beban Amenities (Nantinya masuk di Laporan Laba Rugi!)
                                        \App\Models\JournalLine::create([
                                            'journal_id' => $journal->id,
                                            'coa_code' => $item->expense_coa_code,
                                            'line_description' => "Biaya {$item->name} Check-In {$booking->booking_code}",
                                            'debit' => $totalCost,
                                            'credit' => 0
                                        ]);
                                        
                                        // Kredit: Persediaan Barang (Nantinya memotong di Laporan Neraca!)
                                        \App\Models\JournalLine::create([
                                            'journal_id' => $journal->id,
                                            'coa_code' => $item->inventory_coa_code,
                                            'line_description' => "Pemakaian Persediaan {$item->name}",
                                            'debit' => 0,
                                            'credit' => $totalCost
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        });

        $freshBooking = $booking->fresh(['guest:id,full_name,email,phone', 'bookingRooms.room.roomType:id,name', 'bookingAddons']);
        $this->auditTrailService->record([
            'module' => 'bookings',
            'action' => $targetStatus === 'checked_in' ? 'checked_in' : 'checked_out',
            'entity_type' => 'booking',
            'entity_id' => $booking->id,
            'entity_label' => $booking->booking_code,
            'description' => "Status booking {$booking->booking_code} diubah dari {$currentStatus} ke {$targetStatus}.",
            'metadata' => [
                'previous_status' => $currentStatus,
                'current_status' => $targetStatus,
            ],
        ], $request);

        return response()->json([
            'data' => $this->transformBooking($freshBooking),
            'message' => $targetStatus === 'checked_in'
                ? 'Tamu berhasil check-in.'
                : 'Tamu berhasil check-out.',
        ]);
    }

    private function validatePayload(Request $request, ?Booking $booking = null): array
    {
        $payload = $request->validate([
            'guest' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'checkIn' => ['required', 'date'],
            'checkOut' => ['required', 'date', 'after:checkIn'],
            'channel' => ['required', 'string', 'max:50'],
            'status' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string'],
            'roomDetails' => ['required', 'array', 'min:1'],
            'roomDetails.*.room' => ['required', 'string', 'exists:rooms,room_code'],
            'roomDetails.*.rate' => ['required', 'numeric', 'min:0'],
            'roomDetails.*.adults' => ['required', 'integer', 'min:1', 'max:8'],
            'roomDetails.*.children' => ['required', 'integer', 'min:0', 'max:6'],
        ]);

        $roomCodes = collect($payload['roomDetails'])->pluck('room');
        if ($roomCodes->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'roomDetails' => ['Satu kamar tidak boleh dipilih lebih dari sekali.'],
            ]);
        }

        $this->ensureRoomsAvailable($payload['roomDetails'], $payload['checkIn'], $payload['checkOut'], $booking);

        return $payload;
    }

    private function ensureRoomsAvailable(array $roomDetails, string $checkIn, string $checkOut, ?Booking $booking = null): void
    {
        foreach ($roomDetails as $detail) {
            $room = Room::query()->where('room_code', $detail['room'])->first();

            if (!$room) {
                throw ValidationException::withMessages([
                    'roomDetails' => ["Kamar {$detail['room']} tidak ditemukan."],
                ]);
            }

            $hasConflict = BookingRoom::query()
                ->where('room_id', $room->id)
                ->whereHas('booking', function ($bookingQuery) use ($checkIn, $checkOut, $booking) {
                    $bookingQuery
                        ->whereNotIn('status', ['cancelled', 'no_show'])
                        ->where('check_in_at', '<', $checkOut)
                        ->where('check_out_at', '>', $checkIn);

                    if ($booking) {
                        $bookingQuery->where('id', '!=', $booking->id);
                    }
                })
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'roomDetails' => ["Kamar {$detail['room']} sudah terpakai pada rentang tanggal tersebut."],
                ]);
            }
        }
    }

    private function syncBookingRooms(Booking $booking, array $roomDetails, string $checkIn, string $checkOut): void
    {
        $roomIdsByCode = Room::query()
            ->whereIn('room_code', collect($roomDetails)->pluck('room')->all())
            ->pluck('id', 'room_code');

        $booking->bookingRooms()->delete();

        foreach ($roomDetails as $detail) {
            $roomId = $roomIdsByCode[$detail['room']] ?? null;

            if (!$roomId) {
                continue;
            }

            $booking->bookingRooms()->create([
                'room_id' => $roomId,
                'adult_count' => (int) $detail['adults'],
                'child_count' => (int) $detail['children'],
                'rate' => (float) $detail['rate'],
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
            ]);
        }
    }

    private function upsertGuest(?Guest $guest, array $payload): Guest
    {
        $guest ??= new Guest();

        $guest->fill([
            'full_name' => $payload['guest'],
            'phone' => $payload['phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'notes' => $payload['note'] ?? null,
        ]);
        $guest->save();

        return $guest;
    }

    private function calculateRoomAmount(array $roomDetails, string $checkIn, string $checkOut): float
    {
        $nights = max(1, (int) floor((strtotime($checkOut) - strtotime($checkIn)) / 86400));

        return collect($roomDetails)
            ->sum(fn (array $detail) => ((float) $detail['rate']) * $nights);
    }

    private function generateBookingCode(string $checkIn): string
    {
        $datePart = date('ymd', strtotime($checkIn));
        $prefix = "BK-{$datePart}";
        $lastCode = Booking::query()
            ->where('booking_code', 'like', "{$prefix}-%")
            ->latest('id')
            ->value('booking_code');

        $lastSequence = $lastCode ? (int) substr($lastCode, -3) : 0;
        return sprintf('%s-%03d', $prefix, $lastSequence + 1);
    }

    private function normalizeSource(string $value): string
    {
        return match (strtolower(trim($value))) {
            'direct' => 'direct',
            'airbnb' => 'airbnb',
            'booking.com' => 'booking.com',
            'agoda', 'booking' => 'agoda',
            'traveloka' => 'traveloka',
            'walk-in', 'walk_in' => 'walk_in',
            default => 'other',
        };
    }

    private function sourceLabel(string $value): string
    {
        return match ($value) {
            'direct' => 'Direct',
            'airbnb' => 'Airbnb',
            'booking.com' => 'Booking.com',
            'agoda' => 'Booking',
            'traveloka' => 'Traveloka',
            'walk_in' => 'Walk-in',
            default => 'Other',
        };
    }

    private function normalizeStatus(string $value): string
    {
        return match (strtolower(trim($value))) {
            'tentative', 'draft' => 'draft',
            'confirmed' => 'confirmed',
            'checked-in', 'checked_in' => 'checked_in',
            'checked-out', 'checked_out' => 'checked_out',
            'cancelled', 'canceled' => 'cancelled',
            'no-show', 'no_show' => 'no_show',
            default => 'confirmed',
        };
    }

    private function statusLabel(string $value): string
    {
        return match ($value) {
            'draft' => 'Tentative',
            'confirmed' => 'Confirmed',
            'checked_in' => 'Checked-in',
            'checked_out' => 'Checked-out',
            'cancelled' => 'Cancelled',
            'no_show' => 'No-Show',
            default => ucfirst($value),
        };
    }

    private function transformBooking(Booking $booking): array
    {
        $roomDetails = $booking->bookingRooms
            ->sortBy('room.room_code')
            ->values()
            ->map(function (BookingRoom $bookingRoom) {
                return [
                    'room' => $bookingRoom->room?->room_code,
                    'roomType' => $bookingRoom->room?->roomType?->name,
                    'rate' => (float) $bookingRoom->rate,
                    'adults' => $bookingRoom->adult_count,
                    'children' => $bookingRoom->child_count,
                ];
            })
            ->all();

        $addons = $booking->bookingAddons
            ->sortBy('id')
            ->values()
            ->map(fn (BookingAddon $addon) => $this->transformAddon($addon))
            ->all();

        $invoice = \App\Models\Invoice::where('booking_id', $booking->id)->first();

        return [
            'code' => $booking->booking_code,
            'guest' => $booking->guest?->full_name ?? '',
            'phone' => $booking->guest?->phone ?? '',
            'email' => $booking->guest?->email ?? '',
            'checkIn' => optional($booking->check_in_at)->format('Y-m-d H:i') ?? '',
            'checkOut' => optional($booking->check_out_at)->format('Y-m-d H:i') ?? '',
            'channel' => $this->sourceLabel($booking->source),
            'status' => $this->statusLabel($booking->status),
            'note' => $booking->notes ?? '',
            'roomDetails' => $roomDetails,
            'rooms' => collect($roomDetails)->pluck('room')->filter()->values()->all(),
            'room' => collect($roomDetails)->pluck('room')->filter()->implode(', '),
            'roomType' => collect($roomDetails)->pluck('roomType')->filter()->unique()->implode(', '),
            'roomCount' => count($roomDetails),
            'adults' => collect($roomDetails)->sum('adults'),
            'children' => collect($roomDetails)->sum('children'),
            'amountValue' => (float) $booking->room_amount,
            'amount' => 'IDR ' . number_format((float) $booking->room_amount, 0, ',', '.'),
            'addons' => $addons,
            'addonsTotalValue' => (float) $booking->addon_amount,
            'addonsTotal' => 'IDR ' . number_format((float) $booking->addon_amount, 0, ',', '.'),
            'grandTotalValue' => (float) $booking->grand_total,
            'grandTotal' => 'IDR ' . number_format((float) $booking->grand_total, 0, ',', '.'),
            'invoiceNo' => $invoice?->invoice_number ?? 'INV-' . str_replace('BK-', '', $booking->booking_code),
            'issueDate' => optional($invoice?->invoice_date)->format('Y-m-d') ?? optional($booking->check_in_at)->format('Y-m-d') ?? '',
            'dueDate' => optional($invoice?->due_date)->format('Y-m-d') ?? optional($booking->check_in_at)->format('Y-m-d') ?? '',
            'paidAmountValue' => $invoice ? (float) $invoice->paid_amount : 0,
            'paidAmount' => 'IDR ' . number_format($invoice ? (float) $invoice->paid_amount : 0, 0, ',', '.'),
            'balanceValue' => $invoice ? (float) $invoice->balance_due : (float) $booking->grand_total,
            'balanceAmount' => 'IDR ' . number_format($invoice ? (float) $invoice->balance_due : (float) $booking->grand_total, 0, ',', '.'),
            'invoiceStatus' => $invoice ? ucfirst($invoice->status) : 'Draft',
        ];
    }

    private function transformAddon(BookingAddon $addon): array
    {
        $meta = json_decode((string) $addon->notes, true);
        $meta = is_array($meta) ? $meta : ['note' => (string) $addon->notes];

        $status = match ($addon->status) {
            'planned' => 'Planned',
            'confirmed' => 'Confirmed',
            'posted', 'completed' => 'Posted',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $addon->status)),
        };

        $serviceDate = optional($addon->service_date)->format('Y-m-d') ?? optional($addon->start_date)->format('Y-m-d');
        $startDate = optional($addon->start_date)->format('Y-m-d');
        $endDate = optional($addon->end_date)->format('Y-m-d');

        return [
            'id' => $addon->id,
            'addonType' => $addon->addon_type,
            'addonLabel' => $meta['addonLabel'] ?? ucfirst(str_replace('_', ' ', $addon->addon_type)),
            'serviceName' => $meta['serviceName'] ?? 'Add-on service',
            'itemRef' => $meta['itemRef'] ?? '',
            'serviceDate' => $serviceDate,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'serviceDateLabel' => $addon->addon_type === 'scooter' && $startDate && $endDate
                ? "{$startDate} to {$endDate}"
                : $serviceDate,
            'quantity' => (int) $addon->qty,
            'unitPriceValue' => (float) $addon->unit_price,
            'unitPrice' => 'IDR ' . number_format((float) $addon->unit_price, 0, ',', '.'),
            'totalPriceValue' => (float) $addon->total_price,
            'totalPrice' => 'IDR ' . number_format((float) $addon->total_price, 0, ',', '.'),
            'status' => $status,
            'notes' => $meta['note'] ?? '',
        ];
    }

    public function syncBookingFinancialState(Booking $booking): void
    {
        $booking = $booking->fresh(['guest:id,full_name', 'bookingRooms.room.roomType:id,name', 'bookingAddons']);
        $activeAddonTotal = (float) $booking->bookingAddons()
            ->where('status', '!=', 'cancelled')
            ->sum('total_price');

        $booking->update([
            'addon_amount' => $activeAddonTotal,
            'grand_total' => (float) $booking->room_amount + $activeAddonTotal - (float) $booking->discount_amount + (float) $booking->tax_amount,
        ]);

        $invoice = Invoice::query()->firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'invoice_number' => 'INV-' . str_replace('BK-', '', $booking->booking_code),
                'invoice_date' => now()->toDateString(),
                'due_date' => optional($booking->check_out_at)->format('Y-m-d'),
                'subtotal' => (float) $booking->room_amount,
                'grand_total' => (float) $booking->grand_total,
                'paid_amount' => 0,
                'balance_due' => (float) $booking->grand_total,
                'status' => 'unpaid',
            ],
        );

        $invoice->update([
            'due_date' => optional($booking->check_out_at)->format('Y-m-d'),
            'subtotal' => (float) $booking->room_amount,
            'grand_total' => (float) $booking->grand_total,
            'balance_due' => max(0, (float) $booking->grand_total - (float) $invoice->paid_amount),
            'status' => ((float) $booking->grand_total - (float) $invoice->paid_amount <= 0)
                ? 'paid'
                : ((float) $invoice->paid_amount > 0 ? 'partial' : 'unpaid'),
        ]);

        $this->upsertBookingInvoiceJournal($booking->fresh(['guest:id,full_name']), $invoice->fresh());
    }

    private function upsertBookingInvoiceJournal(Booking $booking, Invoice $invoice): void
    {
        $roomRevenue = (float) $booking->room_amount;
        $receivableTotal = (float) $invoice->grand_total;
        $addonRevenueByType = $booking->bookingAddons()
            ->where('status', '!=', 'cancelled')
            ->selectRaw('addon_type, SUM(total_price) as total_price')
            ->groupBy('addon_type')
            ->get()
            ->map(function ($row) {
                return [
                    'addon_type' => (string) $row->addon_type,
                    'total_price' => (float) $row->total_price,
                ];
            });

        $journal = Journal::query()->firstOrNew([
            'source' => 'invoice',
            'reference_type' => 'booking',
            'reference_id' => $booking->id,
        ]);

        if (!$journal->exists) {
            $journal->journal_number = $this->generateJournalNumber(now()->toDateString());
        }

        $journal->journal_date = optional($invoice->invoice_date)->format('Y-m-d') ?? now()->toDateString();
        $journal->description = "Invoice {$invoice->invoice_number} for {$booking->booking_code}";
        $journal->posted_by = null;
        $journal->save();

        $journal->lines()->delete();

        $journal->lines()->create([
            'coa_code' => $this->resolveCoaCode('112001', 'Asset', '112'),
            'line_description' => "Accounts receivable {$invoice->invoice_number}",
            'debit' => $receivableTotal,
            'credit' => 0,
        ]);

        if ($roomRevenue > 0) {
            $journal->lines()->create([
                'coa_code' => $this->resolveRoomRevenueCoaCode($booking),
                'line_description' => "Room revenue {$booking->booking_code}",
                'debit' => 0,
                'credit' => $roomRevenue,
            ]);
        }

        foreach ($addonRevenueByType as $addonRevenue) {
            if ($addonRevenue['total_price'] <= 0) {
                continue;
            }

            $journal->lines()->create([
                'coa_code' => $this->resolveAddonRevenueCoaCode($addonRevenue['addon_type']),
                'line_description' => "Add-on revenue {$booking->booking_code} ({$addonRevenue['addon_type']})",
                'debit' => 0,
                'credit' => $addonRevenue['total_price'],
            ]);
        }
    }

    private function generateJournalNumber(string $journalDate): string
    {
        $stamp = str_replace('-', '', $journalDate);
        $dailyCount = Journal::query()
            ->whereDate('journal_date', $journalDate)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('JU-%s-%03d', $stamp, $dailyCount);
    }

    private function resolveCoaCode(string $preferredCode, string $category, string $prefix): string
    {
        if (CoaAccount::query()->where('code', $preferredCode)->exists()) {
            return $preferredCode;
        }

        $normalizedCategory = strtolower($category);

        return CoaAccount::query()
            ->whereRaw('LOWER(category) = ?', [$normalizedCategory])
            ->where('code', 'like', "{$prefix}%")
            ->orderBy('code')
            ->value('code') ?? $preferredCode;
    }

    private function resolveRoomRevenueCoaCode(Booking $booking): string
    {
        $preferredCode = match ($booking->source) {
            'airbnb' => '411018',
            'booking.com' => '411023',
            default => '411021',
        };

        return $this->resolveRevenueCoaCode([$preferredCode, '411021', '411018', '411023'], '411');
    }

    private function resolveAddonRevenueCoaCode(string $addonType): string
    {
        $preferredCode = match ($addonType) {
            'transport', 'pickup', 'dropoff', 'pickup_dropoff' => '510001',
            'scooter' => '510002',
            'manta' => '510003',
            'boat', 'boat_ticket' => '510004',
            'tour', 'island_tour' => '510005',
            default => '411021',
        };

        $preferredPrefix = str_starts_with($preferredCode, '510') ? '510' : '411';

        return $this->resolveRevenueCoaCode([$preferredCode, '411021', '510001'], $preferredPrefix);
    }

    private function resolveRevenueCoaCode(array $preferredCodes, string $prefix): string
    {
        foreach ($preferredCodes as $preferredCode) {
            if (CoaAccount::query()->where('code', $preferredCode)->exists()) {
                return $preferredCode;
            }
        }

        return CoaAccount::query()
            ->whereRaw('LOWER(category) = ?', ['revenue'])
            ->where('code', 'like', "{$prefix}%")
            ->orderBy('code')
            ->value('code')
            ?? CoaAccount::query()
                ->whereRaw('LOWER(category) = ?', ['revenue'])
                ->orderBy('code')
                ->value('code')
            ?? $preferredCodes[0];
    }
}
