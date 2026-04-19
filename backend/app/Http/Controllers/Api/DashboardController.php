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
                'bookingAddons',
            ])
            ->whereIn('status', ['draft', 'tentative', 'confirmed', 'checked_in', 'checked_out', 'completed'])
            ->get();

        $rooms = Room::query()->with('roomType:id,name')->get();

        $invoices = Invoice::query()
            ->with(['booking.guest:id,full_name', 'booking.bookingRooms.room:id,room_code,room_name'])
            ->get();
        $arrivalBookings = $bookings
            ->filter(function (Booking $booking) use ($rangeStart, $rangeEnd) {
                $checkIn = optional($booking->check_in_at);

                return $checkIn && $checkIn->betweenIncluded($rangeStart, $rangeEnd);
            })
            ->sortBy('check_in_at')
            ->values();
        $invoiceMap = $invoices->keyBy('booking_id');
        $realizedStatuses = ['checked_in', 'checked_out', 'completed'];
        $periodBookings = $bookings
            ->filter(fn (Booking $booking) => in_array((string) $booking->status, $realizedStatuses, true))
            ->filter(fn (Booking $booking) => $this->bookingOverlapsRange($booking, $rangeStart, $rangeEnd))
            ->values();

        $payments = (float) Payment::query()
            ->whereBetween('payment_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->sum('amount');

        $arrivalsInRange = $arrivalBookings->values();

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
                    && $booking->status === 'checked_in';
            })
            ->values();
        $occupiedRoomCount = $inHouseBookings
            ->flatMap(fn (Booking $booking) => $booking->bookingRooms)
            ->pluck('room_id')
            ->filter()
            ->unique()
            ->count();

        $availableRooms = $rooms->filter(function (Room $room) {
            return in_array(strtolower(trim((string) $room->status)), ['available', 'ready', 'vacant clean', 'vacant'], true);
        })->count();
        $occupancy = $rooms->count() > 0 ? round(($occupiedRoomCount / $rooms->count()) * 100) : 0;
        $roomRevenue = (float) $periodBookings->sum(fn (Booking $booking) => $this->roomRevenueForRange($booking, $rangeStart, $rangeEnd));
        $roomNightsSold = (int) $periodBookings->sum(fn (Booking $booking) => $this->roomNightsForRange($booking, $rangeStart, $rangeEnd));
        $addonRevenue = (float) $periodBookings->sum(fn (Booking $booking) => $this->addonRevenueForRange($booking, $rangeStart, $rangeEnd));
        $totalRevenue = $roomRevenue + $addonRevenue;
        // Outstanding dashboard owner hanya untuk tamu yang masih in-house.
        // Booking selain checked_in tidak ditampilkan di sini agar angka fokus
        // ke folio berjalan yang belum lunas.
        $openFolios = $invoices
            ->filter(function (Invoice $invoice) {
                $bookingStatus = (string) optional($invoice->booking)->status;

                return (float) $invoice->balance_due > 0
                    && $bookingStatus === 'checked_in';
            })
            ->values();
        $outstanding = (float) $openFolios->sum('balance_due');
        $adr = $roomNightsSold > 0 ? $roomRevenue / $roomNightsSold : 0;

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

        $outstandingBySource = $openFolios
            ->groupBy(fn (Invoice $invoice) => $this->sourceLabel((string) optional($invoice->booking)->source))
            ->map(fn ($group) => (float) $group->sum('balance_due'));

        $channelPerformance = $periodBookings
            ->groupBy(fn (Booking $booking) => $this->sourceLabel((string) $booking->source))
            ->map(function ($group, $channel) use ($rangeStart, $rangeEnd, $outstandingBySource) {
                $revenue = (float) $group->sum(function (Booking $booking) use ($rangeStart, $rangeEnd) {
                    return $this->roomRevenueForRange($booking, $rangeStart, $rangeEnd)
                        + $this->addonRevenueForRange($booking, $rangeStart, $rangeEnd);
                });
                $outstanding = (float) ($outstandingBySource->get($channel) ?? 0);

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
            foreach ($booking->bookingRooms as $bookingRoom) {
                $roomType = $bookingRoom->room?->roomType?->name ?? 'Unassigned';
                $roomRevenueContribution = $this->roomLineRevenueForRange($bookingRoom, $booking, $rangeStart, $rangeEnd);

                if ($roomRevenueContribution <= 0) {
                    continue;
                }

                if (!isset($roomTypePerformanceMap[$roomType])) {
                    $roomTypePerformanceMap[$roomType] = [
                        'roomType' => $roomType,
                        'bookingIds' => [],
                        'roomRevenueValue' => 0,
                        'addonRevenueValue' => 0,
                    ];
                }

                $roomTypePerformanceMap[$roomType]['bookingIds'][(int) $booking->id] = true;
                $roomTypePerformanceMap[$roomType]['roomRevenueValue'] += $roomRevenueContribution;
            }
        }

        $roomTypePerformance = collect($roomTypePerformanceMap)
            ->map(function (array $item) {
                $total = $item['roomRevenueValue'] + $item['addonRevenueValue'];

                return [
                    'roomType' => $item['roomType'],
                    'bookings' => count($item['bookingIds']),
                    'roomRevenueValue' => $item['roomRevenueValue'],
                    'addonRevenueValue' => $item['addonRevenueValue'],
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

        $annualRevenueSeries = collect(range(11, 0))
            ->map(function (int $monthsAgo) use ($bookings, $businessDate) {
                $monthDate = $businessDate->copy()->startOfMonth()->subMonths($monthsAgo);
                $monthStart = $monthDate->copy()->startOfMonth();
                $monthEnd = $monthDate->copy()->endOfMonth();
                $monthBookings = $bookings
                    ->filter(fn (Booking $booking) => in_array((string) $booking->status, ['checked_in', 'checked_out', 'completed'], true))
                    ->filter(fn (Booking $booking) => $this->bookingOverlapsRange($booking, $monthStart, $monthEnd))
                    ->values();

                $roomRevenueValue = (float) $monthBookings->sum(
                    fn (Booking $booking) => $this->roomRevenueForRange($booking, $monthStart, $monthEnd)
                );
                $addonRevenueValue = (float) $monthBookings->sum(
                    fn (Booking $booking) => $this->addonRevenueForRange($booking, $monthStart, $monthEnd)
                );
                $totalRevenueValue = $roomRevenueValue + $addonRevenueValue;

                return [
                    'monthKey' => $monthDate->format('Y-m'),
                    'label' => $monthDate->format('M y'),
                    'roomRevenueValue' => $roomRevenueValue,
                    'addonRevenueValue' => $addonRevenueValue,
                    'totalRevenueValue' => $totalRevenueValue,
                    'roomRevenue' => $this->toCurrency($roomRevenueValue),
                    'addonRevenue' => $this->toCurrency($addonRevenueValue),
                    'totalRevenue' => $this->toCurrency($totalRevenueValue),
                ];
            })
            ->values();

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
                        'note' => sprintf('%d room occupied | %d sellable room', $occupiedRoomCount, $availableRooms),
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
                        'note' => sprintf('Room revenue %s across %d room-night(s)', $this->toCurrency($roomRevenue), $roomNightsSold),
                    ],
                ],
                'dailyControl' => [
                    ['label' => 'Arrival in range', 'value' => $arrivalsInRange->count()],
                    ['label' => 'Departure in range', 'value' => $departuresInRange->count()],
                    ['label' => 'In house snapshot', 'value' => $occupiedRoomCount],
                    ['label' => 'Room still sellable', 'value' => $availableRooms],
                ],
                'revenueMix' => [
                    ['label' => 'Room revenue', 'value' => $this->toCurrency($roomRevenue), 'progress' => $totalRevenue > 0 ? ($roomRevenue / $totalRevenue) * 100 : 0],
                    ['label' => 'Add-on revenue', 'value' => $this->toCurrency($addonRevenue), 'progress' => $totalRevenue > 0 ? ($addonRevenue / $totalRevenue) * 100 : 0],
                    ['label' => 'Pendapatan periode (kamar + add-on)', 'value' => $this->toCurrency($totalRevenue), 'progress' => 100],
                    ['label' => 'Open folios', 'value' => sprintf('%d booking(s)', $openFolios->count()), 'progress' => min($openFolios->count() * 12, 100)],
                ],
                'arrivalWatch' => $arrivalWatch,
                'cashierQueue' => $cashierQueue,
                'channelPerformance' => $channelPerformance,
                'roomTypePerformance' => $roomTypePerformance,
                'liveMovement' => $liveMovement,
                'departmentNotes' => $departmentNotes,
                'annualRevenueSeries' => $annualRevenueSeries,
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

    private function bookingOverlapsRange(Booking $booking, Carbon $rangeStart, Carbon $rangeEnd): bool
    {
        return $this->overlapNights(
            optional($booking->check_in_at)?->copy(),
            optional($booking->check_out_at)?->copy(),
            $rangeStart,
            $rangeEnd,
        ) > 0;
    }

    private function roomNightsForRange(Booking $booking, Carbon $rangeStart, Carbon $rangeEnd): int
    {
        return (int) $booking->bookingRooms->sum(
            fn ($bookingRoom) => $this->roomLineNightsForRange($bookingRoom, $booking, $rangeStart, $rangeEnd)
        );
    }

    private function roomRevenueForRange(Booking $booking, Carbon $rangeStart, Carbon $rangeEnd): float
    {
        return (float) $booking->bookingRooms->sum(
            fn ($bookingRoom) => $this->roomLineRevenueForRange($bookingRoom, $booking, $rangeStart, $rangeEnd)
        );
    }

    private function roomLineNightsForRange($bookingRoom, Booking $booking, Carbon $rangeStart, Carbon $rangeEnd): int
    {
        $stayStart = optional($bookingRoom->check_in_at ?: $booking->check_in_at)?->copy();
        $stayEnd = optional($bookingRoom->check_out_at ?: $booking->check_out_at)?->copy();

        return $this->overlapNights($stayStart, $stayEnd, $rangeStart, $rangeEnd);
    }

    private function roomLineRevenueForRange($bookingRoom, Booking $booking, Carbon $rangeStart, Carbon $rangeEnd): float
    {
        $nights = $this->roomLineNightsForRange($bookingRoom, $booking, $rangeStart, $rangeEnd);

        return $nights > 0 ? $nights * (float) $bookingRoom->rate : 0.0;
    }

    private function addonRevenueForRange(Booking $booking, Carbon $rangeStart, Carbon $rangeEnd): float
    {
        return (float) $booking->bookingAddons
            ->where('status', '!=', 'cancelled')
            ->sum(function ($addon) use ($rangeStart, $rangeEnd) {
                if ($addon->service_date) {
                    return $addon->service_date->betweenIncluded($rangeStart, $rangeEnd)
                        ? (float) $addon->total_price
                        : 0.0;
                }

                if ($addon->start_date || $addon->end_date) {
                    $serviceStart = ($addon->start_date ?: $addon->end_date)?->copy()->startOfDay();
                    $serviceEndInclusive = ($addon->end_date ?: $addon->start_date)?->copy()->startOfDay();

                    if (!$serviceStart || !$serviceEndInclusive) {
                        return 0.0;
                    }

                    if ($serviceEndInclusive->lt($serviceStart)) {
                        [$serviceStart, $serviceEndInclusive] = [$serviceEndInclusive, $serviceStart];
                    }

                    $totalDays = max(1, $serviceStart->diffInDays($serviceEndInclusive) + 1);
                    $overlapDays = $this->overlapDaysInclusive($serviceStart, $serviceEndInclusive, $rangeStart, $rangeEnd);

                    return $overlapDays > 0
                        ? ((float) $addon->total_price / $totalDays) * $overlapDays
                        : 0.0;
                }

                return optional($booking->check_in_at)?->betweenIncluded($rangeStart, $rangeEnd)
                    ? (float) $addon->total_price
                    : 0.0;
            });
    }

    private function overlapNights(?Carbon $start, ?Carbon $end, Carbon $rangeStart, Carbon $rangeEnd): int
    {
        if (!$start || !$end) {
            return 0;
        }

        $stayStart = $start->copy()->startOfDay();
        $stayEnd = $end->copy()->startOfDay();

        if ($stayEnd->lte($stayStart)) {
            return 0;
        }

        $periodStart = $rangeStart->copy()->startOfDay();
        $periodEndExclusive = $rangeEnd->copy()->addDay()->startOfDay();
        $overlapStart = $stayStart->greaterThan($periodStart) ? $stayStart : $periodStart;
        $overlapEnd = $stayEnd->lessThan($periodEndExclusive) ? $stayEnd : $periodEndExclusive;

        return $overlapEnd->gt($overlapStart) ? $overlapStart->diffInDays($overlapEnd) : 0;
    }

    private function overlapDaysInclusive(Carbon $start, Carbon $end, Carbon $rangeStart, Carbon $rangeEnd): int
    {
        $serviceStart = $start->copy()->startOfDay();
        $serviceEndInclusive = $end->copy()->startOfDay();
        $periodStart = $rangeStart->copy()->startOfDay();
        $periodEndInclusive = $rangeEnd->copy()->startOfDay();
        $overlapStart = $serviceStart->greaterThan($periodStart) ? $serviceStart : $periodStart;
        $overlapEnd = $serviceEndInclusive->lessThan($periodEndInclusive) ? $serviceEndInclusive : $periodEndInclusive;

        return $overlapEnd->lt($overlapStart) ? 0 : $overlapStart->diffInDays($overlapEnd) + 1;
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
