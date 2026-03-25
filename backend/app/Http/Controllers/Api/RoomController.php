<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 100);
        $search = trim((string) $request->string('search', ''));

        $rooms = Room::query()
            ->with(['roomType:id,name,base_rate', 'coaReceivable:code,account_name', 'coaRevenue:code,account_name'])
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($nested) use ($search) {
                    $nested
                        ->where('room_code', 'like', "%{$search}%")
                        ->orWhere('room_name', 'like', "%{$search}%")
                        ->orWhereHas('roomType', fn ($roomType) => $roomType->where('name', 'like', "%{$search}%"))
                        ->orWhere('coa_receivable_code', 'like', "%{$search}%")
                        ->orWhere('coa_revenue_code', 'like', "%{$search}%");
                });
            })
            ->orderBy('room_code')
            ->paginate($perPage)
            ->through(fn (Room $room) => $this->transform($room));

        return response()->json([
            'data' => $rooms->items(),
            'meta' => [
                'current_page' => $rooms->currentPage(),
                'last_page' => $rooms->lastPage(),
                'per_page' => $rooms->perPage(),
                'total' => $rooms->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:rooms,room_code'],
            'name' => ['required', 'string', 'max:150'],
            'roomTypeId' => ['required', 'integer', 'exists:room_types,id'],
            'coaReceivableCode' => ['nullable', 'string', 'exists:coa_accounts,code'],
            'coaRevenueCode' => ['nullable', 'string', 'exists:coa_accounts,code'],
            'status' => ['nullable', Rule::in(['available', 'occupied', 'dirty', 'cleaning', 'blocked', 'maintenance', 'inactive'])],
            'floor' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ]);

        $room = Room::create([
            'room_type_id' => $payload['roomTypeId'],
            'room_code' => $payload['code'],
            'room_name' => $payload['name'],
            'coa_receivable_code' => $payload['coaReceivableCode'] ?? null,
            'coa_revenue_code' => $payload['coaRevenueCode'] ?? null,
            'status' => $payload['status'] ?? 'available',
            'floor' => $payload['floor'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ])->load(['roomType:id,name,base_rate', 'coaReceivable:code,account_name', 'coaRevenue:code,account_name']);

        return response()->json([
            'data' => $this->transform($room),
            'message' => 'Master kamar berhasil ditambahkan.',
        ], 201);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'roomTypeId' => ['required', 'integer', 'exists:room_types,id'],
            'coaReceivableCode' => ['nullable', 'string', 'exists:coa_accounts,code'],
            'coaRevenueCode' => ['nullable', 'string', 'exists:coa_accounts,code'],
            'status' => ['nullable', Rule::in(['available', 'occupied', 'dirty', 'cleaning', 'blocked', 'maintenance', 'inactive'])],
            'floor' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ]);

        $room->update([
            'room_type_id' => $payload['roomTypeId'],
            'room_name' => $payload['name'],
            'coa_receivable_code' => $payload['coaReceivableCode'] ?? null,
            'coa_revenue_code' => $payload['coaRevenueCode'] ?? null,
            'status' => $payload['status'] ?? $room->status,
            'floor' => $payload['floor'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        return response()->json([
            'data' => $this->transform($room->fresh(['roomType:id,name,base_rate', 'coaReceivable:code,account_name', 'coaRevenue:code,account_name'])),
            'message' => 'Master kamar berhasil diperbarui.',
        ]);
    }

    private function transform(Room $room): array
    {
        return [
            'id' => $room->id,
            'code' => $room->room_code,
            'name' => $room->room_name,
            'roomTypeId' => $room->room_type_id,
            'type' => $room->roomType?->name ?? '',
            'rate' => (float) ($room->roomType?->base_rate ?? 0),
            'coaReceivableCode' => $room->coa_receivable_code,
            'coaReceivable' => $room->coaReceivable
                ? "{$room->coaReceivable->code} - {$room->coaReceivable->account_name}"
                : '',
            'coaRevenueCode' => $room->coa_revenue_code,
            'coaRevenue' => $room->coaRevenue
                ? "{$room->coaRevenue->code} - {$room->coaRevenue->account_name}"
                : '',
            'status' => ucfirst($room->status),
            'floor' => $room->floor,
            'note' => $room->notes ?? '',
            'hk' => $room->status === 'occupied' ? 'Guest in house' : 'Vacant clean',
            'no' => $room->room_code,
        ];
    }
}
