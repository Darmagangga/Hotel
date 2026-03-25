<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class TransportController extends Controller
{
    private function ensureTable(): void
    {
        if (Schema::hasTable('transport_rates')) {
            return;
        }

        Schema::create('transport_rates', function (Blueprint $table) {
            $table->id();
            $table->string('driver');
            $table->decimal('pickup_price_value', 15, 2)->default(0);
            $table->decimal('drop_off_price_value', 15, 2)->default(0);
            $table->string('vehicle')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    private function formatCurrency(float $amount): string
    {
        return 'IDR ' . number_format($amount, 0, ',', '.');
    }

    private function transform(object $row): array
    {
        return [
            'id' => 'TRF-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
            'dbId' => $row->id,
            'driver' => $row->driver,
            'pickupPriceValue' => (float) $row->pickup_price_value,
            'pickupPrice' => $this->formatCurrency((float) $row->pickup_price_value),
            'dropOffPriceValue' => (float) $row->drop_off_price_value,
            'dropOffPrice' => $this->formatCurrency((float) $row->drop_off_price_value),
            'vehicle' => $row->vehicle,
            'note' => $row->note,
        ];
    }

    public function index()
    {
        $this->ensureTable();

        $rows = DB::table('transport_rates')->latest()->get()->map(fn ($row) => $this->transform($row))->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $this->ensureTable();

        $payload = $request->validate([
            'driver' => ['required', 'string', 'max:255'],
            'pickupPriceValue' => ['required', 'numeric', 'min:0'],
            'dropOffPriceValue' => ['required', 'numeric', 'min:0'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $id = DB::table('transport_rates')->insertGetId([
            'driver' => $payload['driver'],
            'pickup_price_value' => $payload['pickupPriceValue'],
            'drop_off_price_value' => $payload['dropOffPriceValue'],
            'vehicle' => $payload['vehicle'] ?? null,
            'note' => $payload['note'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('transport_rates')->where('id', $id)->first();

        return response()->json([
            'message' => "Driver {$row->driver} berhasil ditambahkan.",
            'data' => $this->transform($row),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->ensureTable();

        $payload = $request->validate([
            'driver' => ['required', 'string', 'max:255'],
            'pickupPriceValue' => ['required', 'numeric', 'min:0'],
            'dropOffPriceValue' => ['required', 'numeric', 'min:0'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        DB::table('transport_rates')->where('id', $id)->update([
            'driver' => $payload['driver'],
            'pickup_price_value' => $payload['pickupPriceValue'],
            'drop_off_price_value' => $payload['dropOffPriceValue'],
            'vehicle' => $payload['vehicle'] ?? null,
            'note' => $payload['note'] ?? null,
            'updated_at' => now(),
        ]);

        $row = DB::table('transport_rates')->where('id', $id)->firstOrFail();

        return response()->json([
            'message' => "Driver {$row->driver} berhasil diperbarui.",
            'data' => $this->transform($row),
        ]);
    }
}
