<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class ActivityCatalogController extends Controller
{
    private function ensureTables(): void
    {
        if (!Schema::hasTable('scooter_catalog')) {
            Schema::create('scooter_catalog', function (Blueprint $table) {
                $table->id();
                $table->date('start_date');
                $table->date('end_date');
                $table->string('scooter_type');
                $table->string('vendor');
                $table->decimal('price_value', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('activity_operator_catalog')) {
            Schema::create('activity_operator_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('operator');
                $table->decimal('price_value', 15, 2)->default(0);
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('island_tour_catalog')) {
            Schema::create('island_tour_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('destination');
                $table->string('driver');
                $table->decimal('cost_value', 15, 2)->default(0);
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('boat_ticket_catalog')) {
            Schema::create('boat_ticket_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('company');
                $table->string('destination');
                $table->decimal('price_value', 15, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function formatCurrency(float $amount): string
    {
        return 'IDR ' . number_format($amount, 0, ',', '.');
    }

    public function index()
    {
        $this->ensureTables();

        return response()->json([
            'data' => [
                'scooters' => DB::table('scooter_catalog')->latest()->get()->map(fn ($row) => [
                    'id' => 'SCT-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'startDate' => $row->start_date,
                    'endDate' => $row->end_date,
                    'scooterType' => $row->scooter_type,
                    'vendor' => $row->vendor,
                    'priceValue' => (float) $row->price_value,
                    'price' => $this->formatCurrency((float) $row->price_value),
                ])->values(),
                'operators' => DB::table('activity_operator_catalog')->latest()->get()->map(fn ($row) => [
                    'id' => 'OPR-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'operator' => $row->operator,
                    'priceValue' => (float) $row->price_value,
                    'price' => $this->formatCurrency((float) $row->price_value),
                    'note' => $row->note,
                ])->values(),
                'islandTours' => DB::table('island_tour_catalog')->latest()->get()->map(fn ($row) => [
                    'id' => 'TOUR-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'destination' => $row->destination,
                    'driver' => $row->driver,
                    'costValue' => (float) $row->cost_value,
                    'cost' => $this->formatCurrency((float) $row->cost_value),
                    'note' => $row->note,
                ])->values(),
                'boatTickets' => DB::table('boat_ticket_catalog')->latest()->get()->map(fn ($row) => [
                    'id' => 'BOT-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'company' => $row->company,
                    'destination' => $row->destination,
                    'priceValue' => (float) $row->price_value,
                    'price' => $this->formatCurrency((float) $row->price_value),
                ])->values(),
            ],
        ]);
    }

    public function storeScooter(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
            'scooterType' => ['required', 'string', 'max:255'],
            'vendor' => ['required', 'string', 'max:255'],
            'priceValue' => ['required', 'numeric', 'min:0'],
        ]);
        $id = DB::table('scooter_catalog')->insertGetId([
            'start_date' => $payload['startDate'],
            'end_date' => $payload['endDate'],
            'scooter_type' => $payload['scooterType'],
            'vendor' => $payload['vendor'],
            'price_value' => $payload['priceValue'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data scooter berhasil ditambahkan.', 'data' => ['id' => $id]], 201);
    }

    public function updateScooter(Request $request, int $id)
    {
        $this->storeOrUpdate('scooter_catalog', $request, $id, ['start_date' => 'startDate', 'end_date' => 'endDate', 'scooter_type' => 'scooterType', 'vendor' => 'vendor', 'price_value' => 'priceValue']);
        return response()->json(['message' => 'Data scooter berhasil diperbarui.']);
    }

    public function storeOperator(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'operator' => ['required', 'string', 'max:255'],
            'priceValue' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);
        DB::table('activity_operator_catalog')->insert([
            'operator' => $payload['operator'],
            'price_value' => $payload['priceValue'],
            'note' => $payload['note'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data operator berhasil ditambahkan.'], 201);
    }

    public function updateOperator(Request $request, int $id)
    {
        $this->storeOrUpdate('activity_operator_catalog', $request, $id, ['operator' => 'operator', 'price_value' => 'priceValue', 'note' => 'note']);
        return response()->json(['message' => 'Data operator berhasil diperbarui.']);
    }

    public function storeIslandTour(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'destination' => ['required', 'string', 'max:255'],
            'driver' => ['required', 'string', 'max:255'],
            'costValue' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);
        DB::table('island_tour_catalog')->insert([
            'destination' => $payload['destination'],
            'driver' => $payload['driver'],
            'cost_value' => $payload['costValue'],
            'note' => $payload['note'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data island tour berhasil ditambahkan.'], 201);
    }

    public function updateIslandTour(Request $request, int $id)
    {
        $this->storeOrUpdate('island_tour_catalog', $request, $id, ['destination' => 'destination', 'driver' => 'driver', 'cost_value' => 'costValue', 'note' => 'note']);
        return response()->json(['message' => 'Data island tour berhasil diperbarui.']);
    }

    public function storeBoatTicket(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'priceValue' => ['required', 'numeric', 'min:0'],
        ]);
        DB::table('boat_ticket_catalog')->insert([
            'company' => $payload['company'],
            'destination' => $payload['destination'],
            'price_value' => $payload['priceValue'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data boat ticket berhasil ditambahkan.'], 201);
    }

    public function updateBoatTicket(Request $request, int $id)
    {
        $this->storeOrUpdate('boat_ticket_catalog', $request, $id, ['company' => 'company', 'destination' => 'destination', 'price_value' => 'priceValue']);
        return response()->json(['message' => 'Data boat ticket berhasil diperbarui.']);
    }

    private function storeOrUpdate(string $table, Request $request, int $id, array $mapping): void
    {
        $this->ensureTables();
        $validated = [];
        foreach ($mapping as $column => $input) {
            $value = $request->input($input);
            if ($value !== null) {
                $validated[$column] = $value;
            }
        }
        $validated['updated_at'] = now();
        DB::table($table)->where('id', $id)->update($validated);
    }
}
