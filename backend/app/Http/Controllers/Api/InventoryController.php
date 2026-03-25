<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Room;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    private function formatCurrency(float $amount): string
    {
        return 'IDR ' . number_format($amount, 0, ',', '.');
    }

    private function trackingType(InventoryItem $item): string
    {
        return strtolower((string) $item->category) === 'linen' ? 'Linen' : 'Consumable';
    }

    private function itemSnapshot(InventoryItem $item): array
    {
        $purchases = InventoryMovement::query()
            ->where('item_id', $item->id)
            ->sum('qty_in');
        $issues = InventoryMovement::query()
            ->where('item_id', $item->id)
            ->sum('qty_out');
        $onHand = max($purchases - $issues, 0);

        return [
            'id' => $item->id,
            'name' => $item->item_name,
            'category' => $item->category,
            'trackingType' => $this->trackingType($item),
            'unit' => $item->unit,
            'purchasedQty' => (int) $purchases,
            'issuedQty' => (int) $issues,
            'onHandQty' => (int) $onHand,
            'inventoryCoa' => $item->inventory_coa_code,
            'expenseCoa' => $item->expense_coa_code,
            'reorderLevel' => (int) ($item->min_stock ?? 0),
            'latestCostValue' => (float) ($item->standard_cost ?? 0),
            'latestCost' => $this->formatCurrency((float) ($item->standard_cost ?? 0)),
        ];
    }

    private function purchaseRow(InventoryMovement $movement): array
    {
        $context = json_decode((string) $movement->notes, true);
        $supplier = $context['supplier'] ?? $movement->reference_type ?? 'Supplier';
        $paymentAccount = $context['paymentAccount'] ?? '-';
        $note = $context['note'] ?? (string) $movement->notes;
        $item = $movement->item;
        $quantity = (float) $movement->qty_in;
        $totalCostValue = $quantity * (float) $movement->unit_cost;

        return [
            'id' => $movement->id,
            'purchaseDate' => $movement->movement_date,
            'supplier' => $supplier,
            'itemId' => $movement->item_id,
            'itemName' => $item?->item_name ?? 'Unknown item',
            'quantity' => (int) $quantity,
            'unit' => $item?->unit ?? 'pcs',
            'totalCostValue' => $totalCostValue,
            'totalCost' => $this->formatCurrency($totalCostValue),
            'paymentAccount' => $paymentAccount,
            'note' => $note,
        ];
    }

    private function issueRow(InventoryMovement $movement): array
    {
        $context = json_decode((string) $movement->notes, true);
        $item = $movement->item;
        $trackingType = $item ? $this->trackingType($item) : 'Consumable';
        $quantity = (float) $movement->qty_out;
        $totalValue = $quantity * (float) $movement->unit_cost;

        return [
            'id' => $movement->id,
            'issueDate' => $movement->movement_date,
            'roomNo' => $movement->reference_id,
            'itemId' => $movement->item_id,
            'itemName' => $item?->item_name ?? 'Unknown item',
            'quantity' => (int) $quantity,
            'unit' => $item?->unit ?? 'pcs',
            'trackingType' => $trackingType,
            'totalValueValue' => $totalValue,
            'totalValueLabel' => $this->formatCurrency($totalValue),
            'inventoryCoa' => $item?->inventory_coa_code ?? '',
            'expenseCoa' => $item?->expense_coa_code ?? '',
            'note' => $context['note'] ?? (string) $movement->notes,
        ];
    }

    private function journalRows(array $purchaseRows, array $issueRows): array
    {
        $purchaseEntries = collect($purchaseRows)->flatMap(function (array $entry) {
            return [
                [
                    'id' => 'pur-' . $entry['id'] . '-dr',
                    'entryDate' => $entry['purchaseDate'],
                    'source' => $entry['id'],
                    'transactionType' => 'Purchase',
                    'account' => 'Inventory',
                    'position' => 'Debit',
                    'amount' => $entry['totalCost'],
                    'memo' => 'Pembelian ' . $entry['itemName'],
                ],
                [
                    'id' => 'pur-' . $entry['id'] . '-cr',
                    'entryDate' => $entry['purchaseDate'],
                    'source' => $entry['id'],
                    'transactionType' => 'Purchase',
                    'account' => $entry['paymentAccount'],
                    'position' => 'Credit',
                    'amount' => $entry['totalCost'],
                    'memo' => 'Pembayaran pembelian ' . $entry['itemName'],
                ],
            ];
        });

        $issueEntries = collect($issueRows)->flatMap(function (array $entry) {
            if ($entry['trackingType'] === 'Linen') {
                return [[
                    'id' => 'iss-' . $entry['id'],
                    'entryDate' => $entry['issueDate'],
                    'source' => $entry['id'],
                    'transactionType' => 'Room issue',
                    'account' => 'Mutasi internal',
                    'position' => 'Memo',
                    'amount' => 'Mutasi internal',
                    'memo' => 'Issue linen ke kamar ' . $entry['roomNo'],
                ]];
            }

            return [
                [
                    'id' => 'iss-' . $entry['id'] . '-dr',
                    'entryDate' => $entry['issueDate'],
                    'source' => $entry['id'],
                    'transactionType' => 'Room issue',
                    'account' => $entry['expenseCoa'],
                    'position' => 'Debit',
                    'amount' => $entry['totalValueLabel'],
                    'memo' => 'Issue ' . $entry['itemName'] . ' ke kamar ' . $entry['roomNo'],
                ],
                [
                    'id' => 'iss-' . $entry['id'] . '-cr',
                    'entryDate' => $entry['issueDate'],
                    'source' => $entry['id'],
                    'transactionType' => 'Room issue',
                    'account' => $entry['inventoryCoa'],
                    'position' => 'Credit',
                    'amount' => $entry['totalValueLabel'],
                    'memo' => 'Pengurangan persediaan ' . $entry['itemName'],
                ],
            ];
        });

        return $purchaseEntries
            ->concat($issueEntries)
            ->sortByDesc('entryDate')
            ->values()
            ->all();
    }

    public function index()
    {
        $items = InventoryItem::query()->latest()->get();
        $itemRows = $items->map(fn (InventoryItem $item) => $this->itemSnapshot($item))->values()->all();

        $purchaseRows = InventoryMovement::query()
            ->with('item')
            ->where('qty_in', '>', 0)
            ->latest('movement_date')
            ->get()
            ->map(fn (InventoryMovement $movement) => $this->purchaseRow($movement))
            ->values()
            ->all();

        $issueRows = InventoryMovement::query()
            ->with('item')
            ->where('qty_out', '>', 0)
            ->latest('movement_date')
            ->get()
            ->map(fn (InventoryMovement $movement) => $this->issueRow($movement))
            ->values()
            ->all();

        $lowStockCount = collect($itemRows)->filter(fn (array $item) => $item['onHandQty'] <= $item['reorderLevel'])->count();
        $consumableIssueCount = collect($issueRows)->where('trackingType', 'Consumable')->count();
        $linenIssueCount = collect($issueRows)->where('trackingType', 'Linen')->count();

        return response()->json([
            'data' => [
                'items' => $itemRows,
                'purchases' => $purchaseRows,
                'issues' => $issueRows,
                'journalEntries' => $this->journalRows($purchaseRows, $issueRows),
                'summary' => [
                    'itemCount' => count($itemRows),
                    'lowStockCount' => $lowStockCount,
                    'consumableIssueCount' => $consumableIssueCount,
                    'linenIssueCount' => $linenIssueCount,
                ],
            ],
        ]);
    }

    public function storeItem(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'trackingType' => ['required', 'string', 'max:50'],
            'unit' => ['required', 'string', 'max:50'],
            'inventoryCoa' => ['required', 'string', 'max:255'],
            'expenseCoa' => ['required', 'string', 'max:255'],
            'reorderLevel' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = InventoryItem::create([
            'sku' => 'SKU-' . now()->format('YmdHis'),
            'item_name' => $payload['name'],
            'category' => $payload['category'],
            'unit' => $payload['unit'],
            'standard_cost' => 0,
            'min_stock' => (int) ($payload['reorderLevel'] ?? 0),
            'inventory_coa_code' => $payload['inventoryCoa'],
            'expense_coa_code' => $payload['expenseCoa'],
            'notes' => 'Created from inventory UI.',
            'is_active' => 1,
        ]);

        return response()->json([
            'message' => "Item {$item->item_name} berhasil ditambahkan ke inventory master.",
            'data' => $this->itemSnapshot($item),
        ], 201);
    }

    public function storePurchase(Request $request)
    {
        $payload = $request->validate([
            'purchaseDate' => ['required', 'date'],
            'supplier' => ['required', 'string', 'max:255'],
            'itemId' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unitCostValue' => ['required', 'numeric', 'gt:0'],
            'paymentAccount' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $item = InventoryItem::findOrFail($payload['itemId']);
        $movement = InventoryMovement::create([
            'item_id' => $item->id,
            'movement_type' => 'purchase',
            'qty_in' => (int) $payload['quantity'],
            'qty_out' => 0,
            'reference_id' => 'PUR-' . now()->format('YmdHis'),
            'reference_type' => 'purchase',
            'notes' => json_encode([
                'supplier' => $payload['supplier'],
                'paymentAccount' => $payload['paymentAccount'],
                'note' => $payload['note'] ?? '',
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $payload['purchaseDate'],
            'unit_cost' => (float) $payload['unitCostValue'],
        ]);

        $item->standard_cost = (float) $payload['unitCostValue'];
        $item->save();

        return response()->json([
            'message' => "{$movement->reference_id} berhasil diposting sebagai pembelian inventory.",
            'data' => $this->purchaseRow($movement->load('item')),
        ], 201);
    }

    public function storeIssue(Request $request)
    {
        $payload = $request->validate([
            'issueDate' => ['required', 'date'],
            'roomNo' => ['required', 'string', 'exists:rooms,room_code'],
            'itemId' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ]);

        $item = InventoryItem::findOrFail($payload['itemId']);
        $room = Room::query()->where('room_code', $payload['roomNo'])->firstOrFail();
        $snapshot = $this->itemSnapshot($item);

        if ($snapshot['onHandQty'] < (int) $payload['quantity']) {
            return response()->json(['message' => 'Stok tidak cukup untuk di-issue ke kamar.'], 422);
        }

        $movement = InventoryMovement::create([
            'item_id' => $item->id,
            'movement_type' => 'issue_room',
            'qty_in' => 0,
            'qty_out' => (int) $payload['quantity'],
            'reference_id' => $room->room_code,
            'reference_type' => 'room_issue',
            'notes' => json_encode([
                'note' => $payload['note'] ?? '',
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $payload['issueDate'],
            'unit_cost' => (float) ($item->standard_cost ?? 0),
        ]);

        return response()->json([
            'message' => "{$movement->reference_id} berhasil issue item ke kamar {$room->room_code}.",
            'data' => $this->issueRow($movement->load('item')),
        ], 201);
    }
}
