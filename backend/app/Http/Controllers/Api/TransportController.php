<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoaAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;

class TransportController extends Controller
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

    private function ensureTable(): void
    {
        if (!Schema::hasTable('transport_rates')) {
            Schema::create('transport_rates', function (Blueprint $table) {
                $table->id();
                $table->string('driver');
                $table->decimal('pickup_price_value', 15, 2)->default(0);
                $table->decimal('drop_off_price_value', 15, 2)->default(0);
                $table->decimal('vendor_pickup_price_value', 15, 2)->default(0);
                $table->decimal('vendor_drop_off_price_value', 15, 2)->default(0);
                $table->decimal('customer_pickup_price_value', 15, 2)->default(0);
                $table->decimal('customer_drop_off_price_value', 15, 2)->default(0);
                $table->string('vehicle')->nullable();
                $table->unsignedBigInteger('vendor_id')->nullable();
                $table->string('fee_coa_code', 40)->nullable();
                $table->string('payable_coa_code', 40)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('transport_rates', function (Blueprint $table) {
            if (!Schema::hasColumn('transport_rates', 'vendor_pickup_price_value')) {
                $table->decimal('vendor_pickup_price_value', 15, 2)->default(0)->after('drop_off_price_value');
            }
            if (!Schema::hasColumn('transport_rates', 'vendor_drop_off_price_value')) {
                $table->decimal('vendor_drop_off_price_value', 15, 2)->default(0)->after('vendor_pickup_price_value');
            }
            if (!Schema::hasColumn('transport_rates', 'customer_pickup_price_value')) {
                $table->decimal('customer_pickup_price_value', 15, 2)->default(0)->after('vendor_drop_off_price_value');
            }
            if (!Schema::hasColumn('transport_rates', 'customer_drop_off_price_value')) {
                $table->decimal('customer_drop_off_price_value', 15, 2)->default(0)->after('customer_pickup_price_value');
            }
            if (!Schema::hasColumn('transport_rates', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('vehicle');
            }
            if (!Schema::hasColumn('transport_rates', 'fee_coa_code')) {
                $table->string('fee_coa_code', 40)->nullable()->after('vendor_id');
            }
            if (!Schema::hasColumn('transport_rates', 'payable_coa_code')) {
                $table->string('payable_coa_code', 40)->nullable()->after('fee_coa_code');
            }
        });

        DB::table('transport_rates')->update([
            'vendor_pickup_price_value' => DB::raw('CASE WHEN vendor_pickup_price_value = 0 THEN pickup_price_value ELSE vendor_pickup_price_value END'),
            'vendor_drop_off_price_value' => DB::raw('CASE WHEN vendor_drop_off_price_value = 0 THEN drop_off_price_value ELSE vendor_drop_off_price_value END'),
            'customer_pickup_price_value' => DB::raw('CASE WHEN customer_pickup_price_value = 0 THEN pickup_price_value ELSE customer_pickup_price_value END'),
            'customer_drop_off_price_value' => DB::raw('CASE WHEN customer_drop_off_price_value = 0 THEN drop_off_price_value ELSE customer_drop_off_price_value END'),
        ]);
    }

    private function formatCurrency(float $amount): string
    {
        return 'IDR ' . number_format($amount, 0, ',', '.');
    }

    private function transform(object $row): array
    {
        $vendorPickupPriceValue = (float) ($row->vendor_pickup_price_value ?? $row->pickup_price_value ?? 0);
        $vendorDropOffPriceValue = (float) ($row->vendor_drop_off_price_value ?? $row->drop_off_price_value ?? 0);
        $customerPickupPriceValue = (float) ($row->customer_pickup_price_value ?? $row->pickup_price_value ?? 0);
        $customerDropOffPriceValue = (float) ($row->customer_drop_off_price_value ?? $row->drop_off_price_value ?? 0);

        return [
            'id' => 'TRF-' . str_pad((string) $row->id, 3, '0', STR_PAD_LEFT),
            'dbId' => $row->id,
            'driver' => $row->driver,
            'vendorPickupPriceValue' => $vendorPickupPriceValue,
            'vendorPickupPrice' => $this->formatCurrency($vendorPickupPriceValue),
            'vendorDropOffPriceValue' => $vendorDropOffPriceValue,
            'vendorDropOffPrice' => $this->formatCurrency($vendorDropOffPriceValue),
            'customerPickupPriceValue' => $customerPickupPriceValue,
            'customerPickupPrice' => $this->formatCurrency($customerPickupPriceValue),
            'customerDropOffPriceValue' => $customerDropOffPriceValue,
            'customerDropOffPrice' => $this->formatCurrency($customerDropOffPriceValue),
            'pickupPriceValue' => $customerPickupPriceValue,
            'pickupPrice' => $this->formatCurrency($customerPickupPriceValue),
            'dropOffPriceValue' => $customerDropOffPriceValue,
            'dropOffPrice' => $this->formatCurrency($customerDropOffPriceValue),
            'vehicle' => $row->vehicle,
            'vendorId' => (int) ($row->vendor_id ?? 0),
            'feeCoaCode' => (string) ($row->fee_coa_code ?? ''),
            'note' => $row->note,
            'payableCoaCode' => (string) ($row->payable_coa_code ?? ''),
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
            'vendorPickupPriceValue' => ['required', 'numeric', 'min:0'],
            'vendorDropOffPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPickupPriceValue' => ['required', 'numeric', 'min:0'],
            'customerDropOffPriceValue' => ['required', 'numeric', 'min:0'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'vendorId' => ['nullable', 'integer', 'min:0'],
            'feeCoaCode' => ['nullable', 'string', 'max:40'],
            'payableCoaCode' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string'],
        ]);

        if ((float) $payload['customerPickupPriceValue'] < (float) $payload['vendorPickupPriceValue']) {
            throw ValidationException::withMessages([
                'customerPickupPriceValue' => ['Harga customer pickup tidak boleh lebih kecil dari harga vendor pickup.'],
            ]);
        }

        if ((float) $payload['customerDropOffPriceValue'] < (float) $payload['vendorDropOffPriceValue']) {
            throw ValidationException::withMessages([
                'customerDropOffPriceValue' => ['Harga customer drop off tidak boleh lebih kecil dari harga vendor drop off.'],
            ]);
        }

        $feeCoaCode = $this->validateCoaAccount($payload['feeCoaCode'] ?? null, 'revenue', 'Fee COA');
        $payableCoaCode = $this->validateCoaAccount($payload['payableCoaCode'] ?? null, 'liability', 'Hutang COA');

        $id = DB::table('transport_rates')->insertGetId([
            'driver' => $payload['driver'],
            'pickup_price_value' => $payload['customerPickupPriceValue'],
            'drop_off_price_value' => $payload['customerDropOffPriceValue'],
            'vendor_pickup_price_value' => $payload['vendorPickupPriceValue'],
            'vendor_drop_off_price_value' => $payload['vendorDropOffPriceValue'],
            'customer_pickup_price_value' => $payload['customerPickupPriceValue'],
            'customer_drop_off_price_value' => $payload['customerDropOffPriceValue'],
            'vehicle' => $payload['vehicle'] ?? null,
            'vendor_id' => ($payload['vendorId'] ?? 0) ?: null,
            'fee_coa_code' => $feeCoaCode,
            'payable_coa_code' => $payableCoaCode,
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
            'vendorPickupPriceValue' => ['required', 'numeric', 'min:0'],
            'vendorDropOffPriceValue' => ['required', 'numeric', 'min:0'],
            'customerPickupPriceValue' => ['required', 'numeric', 'min:0'],
            'customerDropOffPriceValue' => ['required', 'numeric', 'min:0'],
            'vehicle' => ['nullable', 'string', 'max:255'],
            'vendorId' => ['nullable', 'integer', 'min:0'],
            'feeCoaCode' => ['nullable', 'string', 'max:40'],
            'payableCoaCode' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string'],
        ]);

        if ((float) $payload['customerPickupPriceValue'] < (float) $payload['vendorPickupPriceValue']) {
            throw ValidationException::withMessages([
                'customerPickupPriceValue' => ['Harga customer pickup tidak boleh lebih kecil dari harga vendor pickup.'],
            ]);
        }

        if ((float) $payload['customerDropOffPriceValue'] < (float) $payload['vendorDropOffPriceValue']) {
            throw ValidationException::withMessages([
                'customerDropOffPriceValue' => ['Harga customer drop off tidak boleh lebih kecil dari harga vendor drop off.'],
            ]);
        }

        $feeCoaCode = $this->validateCoaAccount($payload['feeCoaCode'] ?? null, 'revenue', 'Fee COA');
        $payableCoaCode = $this->validateCoaAccount($payload['payableCoaCode'] ?? null, 'liability', 'Hutang COA');

        DB::table('transport_rates')->where('id', $id)->update([
            'driver' => $payload['driver'],
            'pickup_price_value' => $payload['customerPickupPriceValue'],
            'drop_off_price_value' => $payload['customerDropOffPriceValue'],
            'vendor_pickup_price_value' => $payload['vendorPickupPriceValue'],
            'vendor_drop_off_price_value' => $payload['vendorDropOffPriceValue'],
            'customer_pickup_price_value' => $payload['customerPickupPriceValue'],
            'customer_drop_off_price_value' => $payload['customerDropOffPriceValue'],
            'vehicle' => $payload['vehicle'] ?? null,
            'vendor_id' => ($payload['vendorId'] ?? 0) ?: null,
            'fee_coa_code' => $feeCoaCode,
            'payable_coa_code' => $payableCoaCode,
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
