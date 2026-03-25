<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoaAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CoaAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $search = trim((string) $request->string('search', ''));
        $category = trim((string) $request->string('category', ''));

        $query = CoaAccount::query()
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($nested) use ($search) {
                    $nested
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%");
                });
            })
            ->when($category !== '', function ($builder) use ($category) {
                $builder->where('category', strtolower($category));
            })
            ->orderBy('code');

        $accounts = $query
            ->paginate($perPage)
            ->through(fn (CoaAccount $account) => [
                'code' => $account->code,
                'name' => $account->account_name,
                'category' => ucfirst($account->category),
                'normalBalance' => ucfirst($account->normal_balance),
                'note' => $account->note ?? '',
                'active' => (bool) $account->is_active,
            ]);

        return response()->json([
            'data' => $accounts->items(),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:coa_accounts,code'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'])],
            'normalBalance' => ['required', Rule::in(['Debit', 'Credit'])],
            'note' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $account = CoaAccount::create([
            'code' => $payload['code'],
            'account_name' => $payload['name'],
            'category' => strtolower($payload['category']),
            'normal_balance' => strtolower($payload['normalBalance']),
            'note' => $payload['note'] ?? null,
            'is_active' => $payload['active'] ?? true,
        ]);

        return response()->json([
            'data' => $this->transform($account),
            'message' => 'COA berhasil ditambahkan.',
        ], 201);
    }

    public function update(Request $request, CoaAccount $coaAccount): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'])],
            'normalBalance' => ['required', Rule::in(['Debit', 'Credit'])],
            'note' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $coaAccount->update([
            'account_name' => $payload['name'],
            'category' => strtolower($payload['category']),
            'normal_balance' => strtolower($payload['normalBalance']),
            'note' => $payload['note'] ?? null,
            'is_active' => $payload['active'] ?? true,
        ]);

        return response()->json([
            'data' => $this->transform($coaAccount->fresh()),
            'message' => 'COA berhasil diperbarui.',
        ]);
    }

    private function transform(CoaAccount $account): array
    {
        return [
            'code' => $account->code,
            'name' => $account->account_name,
            'category' => ucfirst($account->category),
            'normalBalance' => ucfirst($account->normal_balance),
            'note' => $account->note ?? '',
            'active' => (bool) $account->is_active,
        ];
    }
}
