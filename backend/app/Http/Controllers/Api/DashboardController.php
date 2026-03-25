<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function owner(Request $request): JsonResponse
    {
        $periodPayload = $this->resolvePeriodRange($request);
        $businessDate = $periodPayload['snapshotDate']->copy();
        $rangeStart = $periodPayload['start']->copy()->startOfDay();
        $rangeEnd = $periodPayload['end']->copy()->endOfDay();
        $period = $periodPayload['period'];

        $bookings = Booking::query()
            ->with([
                'guest:id,full_name',
                'bookingRooms.room.roomType:id,name',
            ])
            ->whereIn('status', ['draft', 'tentative', 'confirmed', 'checked_in', 'checked_out', 'completed'])
            ->get();

        $rooms = Room::query()->with('roomType:id,name')->get();

        $invoices = Invoice::query()
            ->with(['booking.guest:id,full_name', 'booking.bookingRooms.room:id,room_code,room_name'])
            ->get();
        $bookingIdsInRange = $bookings
            ->filter(function (Booking $booking) use ($rangeStart, $rangeEnd) {
                $checkIn = optional($booking->check_in_at);

                return $checkIn && $checkIn->betweenIncluded($rangeStart, $rangeEnd);
            })
            ->pluck('id')
            ->all();
        $periodBookings = $bookings
            ->whereIn('id', $bookingIdsInRange)
            ->sortBy('check_in_at')
            ->values();
        $periodInvoices = $invoices
            ->whereIn('booking_id', $bookingIdsInRange)
            ->values();
        $invoiceMap = $invoices->keyBy('booking_id');

        $payments = (float) Payment::query()
            ->whereBetween('payment_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->sum('amount');

        $arrivalsInRange = $periodBookings
            ->values();

        $departuresInRange = $bookings
            ->filter(fn (Booking $booking) => optional($booking->check_out_at)?->betweenIncluded($rangeStart, $rangeEnd))
            ->values();

        $inHouseBookings = $bookings
            ->filter(function (Booking $booking) use ($businessDate) {
                $checkIn = optional($booking->check_in_at)?->toDateString();
                $checkOut = optional($booking->check_out_at)?->toDateString();

                return $checkIn
                    && $checkOut
                    && $checkIn <= $businessDate->toDateString()
                    && $checkOut > $businessDate->toDateString()
                    && $booking->status !== 'cancelled';
            })
            ->values();

        $availableRooms = $rooms->filter(function (Room $room) {
            return in_array(strtolower(trim((string) $room->status)), ['available', 'ready', 'vacant clean', 'vacant'], true);
        })->count();
        $occupancy = $rooms->count() > 0 ? round(($inHouseBookings->count() / $rooms->count()) * 100) : 0;
        $roomRevenue = (float) $periodBookings->sum('room_amount');
        $addonRevenue = (float) $periodBookings->sum('addon_amount');
        $totalRevenue = $roomRevenue + $addonRevenue;
        $openFolios = $periodInvoices->filter(fn (Invoice $invoice) => (float) $invoice->balance_due > 0)->values();
        $outstanding = (float) $openFolios->sum('balance_due');
        $adr = $inHouseBookings->count() > 0 ? $roomRevenue / $inHouseBookings->count() : 0;

        $arrivalWatch = $arrivalsInRange
            ->take(6)
            ->map(function (Booking $booking) {
                $roomCodes = $booking->bookingRooms
                    ->map(fn ($item) => $item->room?->room_code)
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'guest' => $booking->guest?->full_name ?? 'Guest',
                    'time' => optional($booking->check_in_at)?->format('H:i') ?: 'Arrival',
                    'room' => count($roomCodes) ? implode(', ', $roomCodes) : 'Unassigned room',
                    'note' => sprintf('%s | %s', $this->sourceLabel($booking->source), $this->statusLabel($booking->status)),
                ];
            })
            ->values();

        $cashierQueue = $openFolios
            ->sortByDesc('balance_due')
            ->take(6)
            ->map(function (Invoice $invoice) {
                $roomCodes = $invoice->booking?->bookingRooms
                    ? $invoice->booking->bookingRooms->map(fn ($item) => $item->room?->room_code)->filter()->values()->all()
                    : [];

                return [
                    'guest' => $invoice->booking?->guest?->full_name ?? 'Guest',
                    'balance' => $this->toCurrency((float) $invoice->balance_due),
                    'item' => sprintf('%s | %s', count($roomCodes) ? implode(', ', $roomCodes) : 'Unassigned room', ucfirst((string) $invoice->status)),
                    'due' => optional($invoice->due_date)?->format('Y-m-d') ?? optional($invoice->invoice_date)?->format('Y-m-d') ?? '',
                ];
            })
            ->values();

        $channelPerformance = $periodInvoices
            ->groupBy(fn (Invoice $invoice) => $this->sourceLabel((string) optional($invoice->booking)->source))
            ->map(function ($group, $channel) {
                $revenue = (float) $group->sum('grand_total');
                $outstanding = (float) $group->sum('balance_due');

                return [
                    'channel' => $channel,
                    'bookings' => $group->count(),
                    'revenueValue' => $revenue,
                    'revenue' => $this->toCurrency($revenue),
                    'outstandingValue' => $outstanding,
                    'outstanding' => $this->toCurrency($outstanding),
                ];
            })
            ->sortByDesc('revenueValue')
            ->take(5)
            ->values();

        $roomTypePerformanceMap = [];
        foreach ($periodBookings as $booking) {
            $roomTypes = $booking->bookingRooms
                ->map(fn ($item) => $item->room?->roomType?->name)
                ->filter()
                ->unique()
                ->values();

            $targets = $roomTypes->count() ? $roomTypes->all() : ['Unassigned'];

            foreach ($targets as $roomType) {
                if (!isset($roomTypePerformanceMap[$roomType])) {
                    $roomTypePerformanceMap[$roomType] = [
                        'roomType' => $roomType,
                        'bookings' => 0,
                        'roomRevenueValue' => 0,
                        'addonRevenueValue' => 0,
                    ];
                }

                $roomTypePerformanceMap[$roomType]['bookings'] += 1;
                $roomTypePerformanceMap[$roomType]['roomRevenueValue'] += (float) $booking->room_amount;
                $roomTypePerformanceMap[$roomType]['addonRevenueValue'] += (float) $booking->addon_amount;
            }
        }

        $roomTypePerformance = collect($roomTypePerformanceMap)
            ->map(function (array $item) {
                $total = $item['roomRevenueValue'] + $item['addonRevenueValue'];

                return [
                    ...$item,
                    'roomRevenue' => $this->toCurrency($item['roomRevenueValue']),
                    'addonRevenue' => $this->toCurrency($item['addonRevenueValue']),
                    'totalRevenue' => $this->toCurrency($total),
                    'totalRevenueValue' => $total,
                ];
            })
            ->sortByDesc('totalRevenueValue')
            ->take(5)
            ->values();

        $liveMovement = $inHouseBookings
            ->take(8)
            ->map(function (Booking $booking) use ($businessDate, $invoiceMap) {
                $roomCodes = $booking->bookingRooms
                    ->map(fn ($item) => $item->room?->room_code)
                    ->filter()
                    ->values()
                    ->all();

                $checkInAt = $booking->check_in_at;
                $checkOutAt = $booking->check_out_at;
                $nights = 1;

                if ($checkInAt && $checkOutAt) {
                    $nights = max(1, $checkInAt->diffInDays($checkOutAt));
                }

                $invoice = $invoiceMap->get($booking->id);
                $nextAction = (float) optional($invoice)->balance_due > 0
                    ? 'Follow-up settlement before departure'
                    : 'Guest folio settled';

                if (optional($booking->check_out_at)?->toDateString() === $businessDate->toDateString()) {
                    $nextAction = 'Departure due today';
                } elseif (optional($booking->check_in_at)?->toDateString() === $businessDate->toDateString()) {
                    $nextAction = 'Arrival today, prepare room release';
                }

                return [
                    'room' => count($roomCodes) ? implode(', ', $roomCodes) : 'Unassigned',
                    'guest' => $booking->guest?->full_name ?? 'Guest',
                    'stay' => sprintf('%d night(s)', $nights),
                    'status' => $this->statusLabel($booking->status),
                    'eta' => $nextAction,
                ];
            })
            ->values();

        $departmentNotes = [
            [
                'department' => 'Front office',
                'note' => sprintf('%d arrival dalam periode ini perlu room assignment dan pre-arrival check.', $arrivalsInRange->count()),
            ],
            [
                'department' => 'Housekeeping',
                'note' => sprintf('%d kamar masih sellable dan %d departure dalam periode ini perlu turnover.', $availableRooms, $departuresInRange->count()),
            ],
            [
                'department' => 'Cashier',
                'note' => sprintf('%d folio outstanding senilai %s perlu ditagihkan.', $openFolios->count(), $this->toCurrency($outstanding)),
            ],
            [
                'department' => 'Owner',
                'note' => sprintf('ADR %s dengan revenue total %s dan cash in %s.', $this->toCurrency($adr), $this->toCurrency($totalRevenue), $this->toCurrency((float) $payments)),
            ],
        ];

        return response()->json([
            'data' => [
                'period' => $period,
                'periodLabel' => $periodPayload['periodLabel'],
                'rangeLabel' => $periodPayload['rangeLabel'],
                'businessDate' => $businessDate->toDateString(),
                'currentDateLabel' => $businessDate->translatedFormat('d F Y'),
                'generatedAt' => now()->translatedFormat('d M Y H:i'),
                'overview' => [
                    [
                        'label' => 'Occupancy',
                        'value' => sprintf('%d%%', $occupancy),
                        'note' => sprintf('%d room occupied | %d sellable room', $inHouseBookings->count(), $availableRooms),
                    ],
                    [
                        'label' => 'Arrivals',
                        'value' => (string) $arrivalsInRange->count(),
                        'note' => sprintf('%d departure in %s', $departuresInRange->count(), strtolower($periodPayload['periodLabel'])),
                    ],
                    [
                        'label' => 'Outstanding',
                        'value' => $this->toCurrency($outstanding),
                        'note' => sprintf('%d folio still open', $openFolios->count()),
                    ],
                    [
                        'label' => 'ADR',
                        'value' => $this->toCurrency($adr),
                        'note' => sprintf('Room revenue %s', $this->toCurrency($roomRevenue)),
                    ],
                ],
                'dailyControl' => [
                    ['label' => 'Arrival in range', 'value' => $arrivalsInRange->count()],
                    ['label' => 'Departure in range', 'value' => $departuresInRange->count()],
                    ['label' => 'In house snapshot', 'value' => $inHouseBookings->count()],
                    ['label' => 'Room still sellable', 'value' => $availableRooms],
                ],
                'revenueMix' => [
                    ['label' => 'Room revenue', 'value' => $this->toCurrency($roomRevenue), 'progress' => $totalRevenue > 0 ? ($roomRevenue / $totalRevenue) * 100 : 0],
                    ['label' => 'Add-on revenue', 'value' => $this->toCurrency($addonRevenue), 'progress' => $totalRevenue > 0 ? ($addonRevenue / $totalRevenue) * 100 : 0],
                    ['label' => 'Total revenue', 'value' => $this->toCurrency($totalRevenue), 'progress' => 100],
                    ['label' => 'Open folios', 'value' => sprintf('%d booking(s)', $openFolios->count()), 'progress' => min($openFolios->count() * 12, 100)],
                ],
                'arrivalWatch' => $arrivalWatch,
                'cashierQueue' => $cashierQueue,
                'channelPerformance' => $channelPerformance,
                'roomTypePerformance' => $roomTypePerformance,
                'liveMovement' => $liveMovement,
                'departmentNotes' => $departmentNotes,
            ],
        ]);
    }

    private function resolvePeriodRange(Request $request): array
    {
        $period = strtolower((string) $request->query('period', 'today'));
        $today = Carbon::today();

        if ($period === 'custom') {
            $start = Carbon::parse((string) $request->query('start_date', $today->toDateString()))->startOfDay();
            $end = Carbon::parse((string) $request->query('end_date', $start->toDateString()))->endOfDay();

            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            return [
                'period' => 'custom',
                'periodLabel' => 'Custom Range',
                'start' => $start,
                'end' => $end,
                'snapshotDate' => $end->copy(),
                'rangeLabel' => sprintf('%s - %s', $start->format('d M Y'), $end->format('d M Y')),
            ];
        }

        if ($period === 'week') {
            $start = $today->copy()->startOfWeek();
            $end = $today->copy()->endOfWeek();

            return [
                'period' => 'week',
                'periodLabel' => 'This Week',
                'start' => $start,
                'end' => $end,
                'snapshotDate' => $today->copy(),
                'rangeLabel' => sprintf('%s - %s', $start->format('d M Y'), $end->format('d M Y')),
            ];
        }

        if ($period === 'month') {
            $start = $today->copy()->startOfMonth();
            $end = $today->copy()->endOfMonth();

            return [
                'period' => 'month',
                'periodLabel' => 'This Month',
                'start' => $start,
                'end' => $end,
                'snapshotDate' => $today->copy(),
                'rangeLabel' => sprintf('%s - %s', $start->format('d M Y'), $end->format('d M Y')),
            ];
        }

        return [
            'period' => 'today',
            'periodLabel' => 'Today',
            'start' => $today->copy()->startOfDay(),
            'end' => $today->copy()->endOfDay(),
            'snapshotDate' => $today->copy(),
            'rangeLabel' => $today->format('d M Y'),
        ];
    }

    private function sourceLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'direct' => 'Direct',
            'walkin', 'walk-in', 'walk_in' => 'Walk-in',
            'booking', 'booking.com' => 'Booking.com',
            'airbnb' => 'Airbnb',
            'agoda' => 'Agoda',
            default => ucfirst((string) $value ?: 'Unknown'),
        };
    }

    private function statusLabel(?string $value): string
    {
        return match ((string) $value) {
            'tentative' => 'Tentative',
            'confirmed' => 'Confirmed',
            'checked_in' => 'Checked-in',
            'checked_out', 'completed' => 'Checked-out',
            'cancelled' => 'Cancelled',
            'no_show' => 'No-Show',
            default => ucfirst(str_replace('_', ' ', (string) $value)),
        };
    }

    private function toCurrency(float $amount): string
    {
        return 'IDR ' . number_format($amount, 0, ',', '.');
    }
}
