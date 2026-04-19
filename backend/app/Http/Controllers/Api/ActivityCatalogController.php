<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoaAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;

class ActivityCatalogController extends Controller
{
    private function normalizeCoaCode(?string $value): ?string
    {
        $normalized = trim((string) strtok((string) ($value ?? ''), '-'));

        return $normalized === '' ? null : $normalized;
    }

    private function validateCoaAccount(?string $value, string $expectedCategory, string $fieldLabel): ?string
    {
        $code = $this->normalizeCoaCode($value);

        if ($code === null) {
            return null;
        }

        $account = CoaAccount::query()->find($code);

        if (!$account) {
            throw ValidationException::withMessages([
                'coa' => ["{$fieldLabel} tidak ditemukan di master COA."],
            ]);
        }

        if (!$account->is_active) {
            throw ValidationException::withMessages([
                'coa' => ["{$fieldLabel} tidak aktif di master COA."],
            ]);
        }

        if (strtolower((string) $account->category) !== strtolower($expectedCategory)) {
            throw ValidationException::withMessages([
                'coa' => ["{$fieldLabel} harus menggunakan akun kategori {$expectedCategory}."],
            ]);
        }

        return (string) $account->code;
    }

    private function resolveVendorName(?int $vendorId, ?string $fallback = null): ?string
    {
        if (($vendorId ?? 0) > 0) {
            $vendorName = DB::table('vendors')->where('id', $vendorId)->value('vendor_name');

            if (is_string($vendorName) && trim($vendorName) !== '') {
                return trim($vendorName);
            }
        }

        $vendorName = trim((string) ($fallback ?? ''));

        return $vendorName === '' ? null : $vendorName;
    }

    private function ensureTables(): void
    {
        if (!Schema::hasTable('scooter_catalog')) {
            Schema::create('scooter_catalog', function (Blueprint $table) {
                $table->id();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('scooter_type');
                $table->string('vendor')->nullable();
                $table->unsignedBigInteger('vendor_id')->nullable();
                $table->decimal('price_value', 15, 2)->default(0);
                $table->decimal('vendor_price_value', 15, 2)->default(0);
                $table->decimal('customer_price_value', 15, 2)->default(0);
                $table->string('payable_coa_code', 40)->nullable();
                $table->string('fee_coa_code', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('activity_operator_catalog')) {
            Schema::create('activity_operator_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('operator');
                $table->string('vendor')->nullable();
                $table->unsignedBigInteger('vendor_id')->nullable();
                $table->decimal('price_value', 15, 2)->default(0);
                $table->decimal('vendor_price_value', 15, 2)->default(0);
                $table->decimal('customer_price_value', 15, 2)->default(0);
                $table->string('payable_coa_code', 40)->nullable();
                $table->string('fee_coa_code', 40)->nullable();
                $table->text('note')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('island_tour_catalog')) {
            Schema::create('island_tour_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('destination');
                $table->string('vendor')->nullable();
                $table->unsignedBigInteger('vendor_id')->nullable();
                $table->string('driver');
                $table->decimal('cost_value', 15, 2)->default(0);
                $table->decimal('vendor_price_value', 15, 2)->default(0);
                $table->decimal('customer_price_value', 15, 2)->default(0);
                $table->string('payable_coa_code', 40)->nullable();
                $table->string('fee_coa_code', 40)->nullable();
                $table->text('note')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('boat_ticket_catalog')) {
            Schema::create('boat_ticket_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('company');
                $table->string('vendor')->nullable();
                $table->unsignedBigInteger('vendor_id')->nullable();
                $table->string('destination');
                $table->decimal('price_value', 15, 2)->default(0);
                $table->decimal('vendor_price_value', 15, 2)->default(0);
                $table->decimal('customer_price_value', 15, 2)->default(0);
                $table->string('payable_coa_code', 40)->nullable();
                $table->string('fee_coa_code', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('scooter_catalog', function (Blueprint $table) {
            if (!Schema::hasColumn('scooter_catalog', 'start_date')) {
                $table->date('start_date')->nullable()->after('id');
            }
            if (!Schema::hasColumn('scooter_catalog', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
            if (!Schema::hasColumn('scooter_catalog', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('vendor');
            }
            if (!Schema::hasColumn('scooter_catalog', 'vendor_price_value')) {
                $table->decimal('vendor_price_value', 15, 2)->default(0)->after('price_value');
            }
            if (!Schema::hasColumn('scooter_catalog', 'customer_price_value')) {
                $table->decimal('customer_price_value', 15, 2)->default(0)->after('vendor_price_value');
            }
            if (!Schema::hasColumn('scooter_catalog', 'payable_coa_code')) {
                $table->string('payable_coa_code', 40)->nullable()->after('customer_price_value');
            }
            if (!Schema::hasColumn('scooter_catalog', 'fee_coa_code')) {
                $table->string('fee_coa_code', 40)->nullable()->after('payable_coa_code');
            }
            if (!Schema::hasColumn('scooter_catalog', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('fee_coa_code');
            }
        });

        Schema::table('activity_operator_catalog', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_operator_catalog', 'vendor')) {
                $table->string('vendor')->nullable()->after('operator');
            }
            if (!Schema::hasColumn('activity_operator_catalog', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('vendor');
            }
            if (!Schema::hasColumn('activity_operator_catalog', 'vendor_price_value')) {
                $table->decimal('vendor_price_value', 15, 2)->default(0)->after('price_value');
            }
            if (!Schema::hasColumn('activity_operator_catalog', 'customer_price_value')) {
                $table->decimal('customer_price_value', 15, 2)->default(0)->after('vendor_price_value');
            }
            if (!Schema::hasColumn('activity_operator_catalog', 'payable_coa_code')) {
                $table->string('payable_coa_code', 40)->nullable()->after('customer_price_value');
            }
            if (!Schema::hasColumn('activity_operator_catalog', 'fee_coa_code')) {
                $table->string('fee_coa_code', 40)->nullable()->after('payable_coa_code');
            }
            if (!Schema::hasColumn('activity_operator_catalog', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('note');
            }
        });

        Schema::table('island_tour_catalog', function (Blueprint $table) {
            if (!Schema::hasColumn('island_tour_catalog', 'vendor')) {
                $table->string('vendor')->nullable()->after('destination');
            }
            if (!Schema::hasColumn('island_tour_catalog', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('vendor');
            }
            if (!Schema::hasColumn('island_tour_catalog', 'vendor_price_value')) {
                $table->decimal('vendor_price_value', 15, 2)->default(0)->after('cost_value');
            }
            if (!Schema::hasColumn('island_tour_catalog', 'customer_price_value')) {
                $table->decimal('customer_price_value', 15, 2)->default(0)->after('vendor_price_value');
            }
            if (!Schema::hasColumn('island_tour_catalog', 'payable_coa_code')) {
                $table->string('payable_coa_code', 40)->nullable()->after('customer_price_value');
            }
            if (!Schema::hasColumn('island_tour_catalog', 'fee_coa_code')) {
                $table->string('fee_coa_code', 40)->nullable()->after('payable_coa_code');
            }
            if (!Schema::hasColumn('island_tour_catalog', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('note');
            }
        });

        Schema::table('boat_ticket_catalog', function (Blueprint $table) {
            if (!Schema::hasColumn('boat_ticket_catalog', 'vendor')) {
                $table->string('vendor')->nullable()->after('company');
            }
            if (!Schema::hasColumn('boat_ticket_catalog', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('vendor');
            }
            if (!Schema::hasColumn('boat_ticket_catalog', 'vendor_price_value')) {
                $table->decimal('vendor_price_value', 15, 2)->default(0)->after('price_value');
            }
            if (!Schema::hasColumn('boat_ticket_catalog', 'customer_price_value')) {
                $table->decimal('customer_price_value', 15, 2)->default(0)->after('vendor_price_value');
            }
            if (!Schema::hasColumn('boat_ticket_catalog', 'payable_coa_code')) {
                $table->string('payable_coa_code', 40)->nullable()->after('customer_price_value');
            }
            if (!Schema::hasColumn('boat_ticket_catalog', 'fee_coa_code')) {
                $table->string('fee_coa_code', 40)->nullable()->after('payable_coa_code');
            }
            if (!Schema::hasColumn('boat_ticket_catalog', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('fee_coa_code');
            }
        });

        DB::table('scooter_catalog')->update([
            'vendor_price_value' => DB::raw('CASE WHEN vendor_price_value = 0 THEN price_value ELSE vendor_price_value END'),
            'customer_price_value' => DB::raw('CASE WHEN customer_price_value = 0 THEN price_value ELSE customer_price_value END'),
        ]);
        DB::table('activity_operator_catalog')->update([
            'vendor_price_value' => DB::raw('CASE WHEN vendor_price_value = 0 THEN price_value ELSE vendor_price_value END'),
            'customer_price_value' => DB::raw('CASE WHEN customer_price_value = 0 THEN price_value ELSE customer_price_value END'),
        ]);
        DB::table('island_tour_catalog')->update([
            'vendor_price_value' => DB::raw('CASE WHEN vendor_price_value = 0 THEN cost_value ELSE vendor_price_value END'),
            'customer_price_value' => DB::raw('CASE WHEN customer_price_value = 0 THEN cost_value ELSE customer_price_value END'),
        ]);
        DB::table('boat_ticket_catalog')->update([
            'vendor_price_value' => DB::raw('CASE WHEN vendor_price_value = 0 THEN price_value ELSE vendor_price_value END'),
            'customer_price_value' => DB::raw('CASE WHEN customer_price_value = 0 THEN price_value ELSE customer_price_value END'),
        ]);
    }

    private function formatCurrency(float $amount): string
    {
        return 'IDR ' . number_format($amount, 0, ',', '.');
    }

    public function index()
    {
        $this->ensureTables();

        $scooters = DB::table('scooter_catalog as s')
            ->leftJoin('vendors as v', 'v.id', '=', 's.vendor_id')
            ->select('s.*', DB::raw("COALESCE(v.vendor_name, s.vendor, '') as vendor_name"))
            ->latest('s.id')
            ->get();
        $operators = DB::table('activity_operator_catalog as o')
            ->leftJoin('vendors as v', 'v.id', '=', 'o.vendor_id')
            ->select('o.*', DB::raw("COALESCE(v.vendor_name, o.vendor, '') as vendor_name"))
            ->latest('o.id')
            ->get();
        $islandTours = DB::table('island_tour_catalog as t')
            ->leftJoin('vendors as v', 'v.id', '=', 't.vendor_id')
            ->select('t.*', DB::raw("COALESCE(v.vendor_name, t.vendor, '') as vendor_name"))
            ->latest('t.id')
            ->get();
        $boatTickets = DB::table('boat_ticket_catalog as b')
            ->leftJoin('vendors as v', 'v.id', '=', 'b.vendor_id')
            ->select('b.*', DB::raw("COALESCE(v.vendor_name, b.vendor, '') as vendor_name"))
            ->latest('b.id')
            ->get();

        return response()->json([
            'data' => [
                'scooters' => $scooters->map(fn ($row) => [
                    'id' => 'SCT-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'startDate' => $row->start_date,
                    'endDate' => $row->end_date,
                    'scooterType' => $row->scooter_type,
                    'vendor' => $row->vendor_name,
                    'vendorId' => (int) ($row->vendor_id ?? 0),
                    'vendorPriceValue' => (float) ($row->vendor_price_value ?? $row->price_value),
                    'vendorPrice' => $this->formatCurrency((float) ($row->vendor_price_value ?? $row->price_value)),
                    'customerPriceValue' => (float) ($row->customer_price_value ?? $row->price_value),
                    'customerPrice' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value)),
                    'feeValue' => (float) ($row->customer_price_value ?? $row->price_value) - (float) ($row->vendor_price_value ?? $row->price_value),
                    'fee' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value) - (float) ($row->vendor_price_value ?? $row->price_value)),
                    'priceValue' => (float) ($row->customer_price_value ?? $row->price_value),
                    'price' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value)),
                    'payableCoaCode' => (string) ($row->payable_coa_code ?? ''),
                    'feeCoaCode' => (string) ($row->fee_coa_code ?? ''),
                    'isActive' => (bool) ($row->is_active ?? true),
                ])->values(),
                'operators' => $operators->map(fn ($row) => [
                    'id' => 'OPR-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'operator' => $row->operator,
                    'vendor' => $row->vendor_name,
                    'vendorId' => (int) ($row->vendor_id ?? 0),
                    'vendorPriceValue' => (float) ($row->vendor_price_value ?? $row->price_value),
                    'vendorPrice' => $this->formatCurrency((float) ($row->vendor_price_value ?? $row->price_value)),
                    'customerPriceValue' => (float) ($row->customer_price_value ?? $row->price_value),
                    'customerPrice' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value)),
                    'feeValue' => (float) ($row->customer_price_value ?? $row->price_value) - (float) ($row->vendor_price_value ?? $row->price_value),
                    'fee' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value) - (float) ($row->vendor_price_value ?? $row->price_value)),
                    'priceValue' => (float) ($row->customer_price_value ?? $row->price_value),
                    'price' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value)),
                    'payableCoaCode' => (string) ($row->payable_coa_code ?? ''),
                    'feeCoaCode' => (string) ($row->fee_coa_code ?? ''),
                    'note' => $row->note,
                    'isActive' => (bool) ($row->is_active ?? true),
                ])->values(),
                'islandTours' => $islandTours->map(fn ($row) => [
                    'id' => 'TOUR-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'destination' => $row->destination,
                    'vendor' => $row->vendor_name,
                    'vendorId' => (int) ($row->vendor_id ?? 0),
                    'driver' => $row->driver,
                    'vendorPriceValue' => (float) ($row->vendor_price_value ?? $row->cost_value),
                    'vendorPrice' => $this->formatCurrency((float) ($row->vendor_price_value ?? $row->cost_value)),
                    'customerPriceValue' => (float) ($row->customer_price_value ?? $row->cost_value),
                    'customerPrice' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->cost_value)),
                    'feeValue' => (float) ($row->customer_price_value ?? $row->cost_value) - (float) ($row->vendor_price_value ?? $row->cost_value),
                    'fee' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->cost_value) - (float) ($row->vendor_price_value ?? $row->cost_value)),
                    'costValue' => (float) ($row->customer_price_value ?? $row->cost_value),
                    'cost' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->cost_value)),
                    'payableCoaCode' => (string) ($row->payable_coa_code ?? ''),
                    'feeCoaCode' => (string) ($row->fee_coa_code ?? ''),
                    'note' => $row->note,
                    'isActive' => (bool) ($row->is_active ?? true),
                ])->values(),
                'boatTickets' => $boatTickets->map(fn ($row) => [
                    'id' => 'BOT-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
                    'dbId' => $row->id,
                    'company' => $row->company,
                    'vendor' => $row->vendor_name,
                    'vendorId' => (int) ($row->vendor_id ?? 0),
                    'destination' => $row->destination,
                    'vendorPriceValue' => (float) ($row->vendor_price_value ?? $row->price_value),
                    'vendorPrice' => $this->formatCurrency((float) ($row->vendor_price_value ?? $row->price_value)),
                    'customerPriceValue' => (float) ($row->customer_price_value ?? $row->price_value),
                    'customerPrice' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value)),
                    'feeValue' => (float) ($row->customer_price_value ?? $row->price_value) - (float) ($row->vendor_price_value ?? $row->price_value),
                    'fee' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value) - (float) ($row->vendor_price_value ?? $row->price_value)),
                    'priceValue' => (float) ($row->customer_price_value ?? $row->price_value),
                    'price' => $this->formatCurrency((float) ($row->customer_price_value ?? $row->price_value)),
                    'payableCoaCode' => (string) ($row->payable_coa_code ?? ''),
                    'feeCoaCode' => (string) ($row->fee_coa_code ?? ''),
                    'isActive' => (bool) ($row->is_active ?? true),
                ])->values(),
            ],
        ]);
    }

    public function storeScooter(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'scooterType' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        $payableCoaCode = $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA');
        $feeCoaCode = $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA');
        $vendorName = $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor'));
        $id = DB::table('scooter_catalog')->insertGetId([
            'start_date' => $payload['startDate'] ?? null,
            'end_date' => $payload['endDate'] ?? null,
            'scooter_type' => $payload['scooterType'],
            'vendor' => $vendorName,
            'vendor_id' => (int) $payload['vendorId'],
            'price_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $payableCoaCode,
            'fee_coa_code' => $feeCoaCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data scooter berhasil ditambahkan.', 'data' => ['id' => $id]], 201);
    }

    public function updateScooter(Request $request, int $id)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'scooterType' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        DB::table('scooter_catalog')->where('id', $id)->update([
            'start_date' => $payload['startDate'] ?? null,
            'end_date' => $payload['endDate'] ?? null,
            'scooter_type' => $payload['scooterType'],
            'vendor' => $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor')),
            'vendor_id' => (int) $payload['vendorId'],
            'price_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA'),
            'fee_coa_code' => $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA'),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data scooter berhasil diperbarui.']);
    }

    public function storeOperator(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'operator' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        DB::table('activity_operator_catalog')->insert([
            'operator' => $payload['operator'],
            'vendor' => $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor')),
            'vendor_id' => (int) $payload['vendorId'],
            'price_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA'),
            'fee_coa_code' => $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA'),
            'note' => $payload['note'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data operator berhasil ditambahkan.'], 201);
    }

    public function updateOperator(Request $request, int $id)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'operator' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        DB::table('activity_operator_catalog')->where('id', $id)->update([
            'operator' => $payload['operator'],
            'vendor' => $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor')),
            'vendor_id' => (int) $payload['vendorId'],
            'price_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA'),
            'fee_coa_code' => $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA'),
            'note' => $payload['note'] ?? null,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data operator berhasil diperbarui.']);
    }

    public function storeIslandTour(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'destination' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'driver' => ['required', 'string', 'max:255'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        DB::table('island_tour_catalog')->insert([
            'destination' => $payload['destination'],
            'vendor' => $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor')),
            'vendor_id' => (int) $payload['vendorId'],
            'driver' => $payload['driver'],
            'cost_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA'),
            'fee_coa_code' => $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA'),
            'note' => $payload['note'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data island tour berhasil ditambahkan.'], 201);
    }

    public function updateIslandTour(Request $request, int $id)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'destination' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'driver' => ['required', 'string', 'max:255'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        DB::table('island_tour_catalog')->where('id', $id)->update([
            'destination' => $payload['destination'],
            'vendor' => $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor')),
            'vendor_id' => (int) $payload['vendorId'],
            'driver' => $payload['driver'],
            'cost_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA'),
            'fee_coa_code' => $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA'),
            'note' => $payload['note'] ?? null,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data island tour berhasil diperbarui.']);
    }

    public function storeBoatTicket(Request $request)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'destination' => ['required', 'string', 'max:255'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        DB::table('boat_ticket_catalog')->insert([
            'company' => $payload['company'],
            'vendor' => $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor')),
            'vendor_id' => (int) $payload['vendorId'],
            'destination' => $payload['destination'],
            'price_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA'),
            'fee_coa_code' => $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data boat ticket berhasil ditambahkan.'], 201);
    }

    public function updateBoatTicket(Request $request, int $id)
    {
        $this->ensureTables();
        $payload = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'vendorId' => ['required', 'integer', 'min:1'],
            'destination' => ['required', 'string', 'max:255'],
            'vendorPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPriceValue' => ['required', 'numeric', 'min:0'],
            'payableCoaCode' => ['required', 'string', 'max:40'],
            'feeCoaCode' => ['required', 'string', 'max:40'],
        ]);
        if ((float) $payload['customerPriceValue'] < (float) $payload['vendorPriceValue']) {
            throw ValidationException::withMessages([
                'customerPriceValue' => ['Harga customer tidak boleh lebih kecil dari harga vendor.'],
            ]);
        }
        DB::table('boat_ticket_catalog')->where('id', $id)->update([
            'company' => $payload['company'],
            'vendor' => $this->resolveVendorName((int) $payload['vendorId'], $request->input('vendor')),
            'vendor_id' => (int) $payload['vendorId'],
            'destination' => $payload['destination'],
            'price_value' => $payload['customerPriceValue'],
            'vendor_price_value' => $payload['vendorPriceValue'],
            'customer_price_value' => $payload['customerPriceValue'],
            'payable_coa_code' => $this->validateCoaAccount($payload['payableCoaCode'], 'liability', 'Hutang COA'),
            'fee_coa_code' => $this->validateCoaAccount($payload['feeCoaCode'], 'revenue', 'Fee COA'),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Data boat ticket berhasil diperbarui.']);
    }
}
