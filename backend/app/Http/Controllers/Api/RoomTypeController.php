<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;

class RoomTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = RoomType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (RoomType $roomType) => [
                'id' => $roomType->id,
                'code' => $roomType->code,
                'name' => $roomType->name,
                'capacity' => $roomType->capacity,
                'baseRate' => (float) $roomType->base_rate,
            ]);

        return response()->json([
            'data' => $types,
        ]);
    }
}
