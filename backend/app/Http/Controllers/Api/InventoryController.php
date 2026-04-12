<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private function movementContext(InventoryMovement $movement): array
    {
        $context = json_decode((string) $movement->notes, true);

        return is_array($context) ? $context : [];
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
            'code' => $item->sku,
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

    private function purchaseLineRow(InventoryMovement $movement): array
    {
        $context = $this->movementContext($movement);
        $item = $movement->item;
        $quantity = (float) $movement->qty_in;
        $unitCostValue = (float) $movement->unit_cost;
        $grossSubtotalValue = $quantity * $unitCostValue;
        $discountPercent = max(0, min(100, (float) ($context['discountPercent'] ?? 0)));
        $discountValue = (float) ($context['discountValue'] ?? round(($grossSubtotalValue * $discountPercent) / 100, 2));
        $lineTotalValue = max($grossSubtotalValue - $discountValue, 0);
        $deliveryDate = trim((string) ($context['deliveryDate'] ?? '')) ?: ($movement->movement_date instanceof \DateTimeInterface
            ? $movement->movement_date->format('Y-m-d')
            : (string) $movement->movement_date);

        return [
            'movementId' => $movement->id,
            'itemId' => $movement->item_id,
            'itemCode' => $item?->sku ?? ('ITEM-' . $movement->item_id),
            'itemName' => $item?->item_name ?? 'Unknown item',
            'deliveryDate' => $deliveryDate,
            'quantity' => (int) $quantity,
            'unit' => $item?->unit ?? 'pcs',
            'unitCostValue' => $unitCostValue,
            'unitCost' => $this->formatCurrency($unitCostValue),
            'grossSubtotalValue' => $grossSubtotalValue,
            'grossSubtotal' => $this->formatCurrency($grossSubtotalValue),
            'discountPercent' => $discountPercent,
            'discountValue' => $discountValue,
            'discountAmount' => $this->formatCurrency($discountValue),
            'lineTotalValue' => $lineTotalValue,
            'lineTotal' => $this->formatCurrency($lineTotalValue),
            'note' => trim((string) ($context['lineNote'] ?? '')),
            'costCenter' => trim((string) ($context['costCenter'] ?? '')),
            'project' => trim((string) ($context['project'] ?? '')),
        ];
    }

    private function purchaseTransactionRow($group): array
    {
        $first = $group->sortBy('id')->first();
        $context = $this->movementContext($first);
        $lines = $group
            ->sortBy('id')
            ->map(fn (InventoryMovement $movement) => $this->purchaseLineRow($movement))
            ->values()
            ->all();

        $totalQuantity = collect($lines)->sum('quantity');
        $grossTotalValue = collect($lines)->sum('grossSubtotalValue');
        $totalDiscountValue = collect($lines)->sum('discountValue');
        $subtotalValue = collect($lines)->sum('lineTotalValue');
        $extraCostPercent = max(0, (float) ($context['extraCostPercent'] ?? 0));
        $extraCostValue = (float) ($context['extraCostValue'] ?? round(($subtotalValue * $extraCostPercent) / 100, 2));
        $grandTotalValue = $subtotalValue + $extraCostValue;
        $itemSummary = collect($lines)
            ->map(fn (array $line) => sprintf('%s x%s', $line['itemName'], $line['quantity']))
            ->implode(', ');

        return [
            'id' => (string) $first->reference_id,
            'transactionNo' => (string) $first->reference_id,
            'purchaseDate' => $first->movement_date instanceof \DateTimeInterface
                ? $first->movement_date->format('Y-m-d')
                : (string) $first->movement_date,
            'deliveryDate' => trim((string) ($context['headerDeliveryDate'] ?? '')) ?: ($first->movement_date instanceof \DateTimeInterface
                ? $first->movement_date->format('Y-m-d')
                : (string) $first->movement_date),
            'supplier' => $context['supplier'] ?? $first->reference_type ?? 'Supplier',
            'status' => trim((string) ($context['status'] ?? 'Draft')) ?: 'Draft',
            'currency' => trim((string) ($context['currency'] ?? 'IDR')) ?: 'IDR',
            'exchangeRate' => (float) ($context['exchangeRate'] ?? 1),
            'location' => trim((string) ($context['location'] ?? 'Main Store')),
            'description' => trim((string) ($context['description'] ?? 'Order Pembelian')),
            'itemSummary' => $itemSummary,
            'lineCount' => count($lines),
            'totalQuantity' => (int) $totalQuantity,
            'grossTotalValue' => $grossTotalValue,
            'grossTotal' => $this->formatCurrency($grossTotalValue),
            'totalDiscountValue' => $totalDiscountValue,
            'totalDiscount' => $this->formatCurrency($totalDiscountValue),
            'subtotalValue' => $subtotalValue,
            'subtotal' => $this->formatCurrency($subtotalValue),
            'extraCostPercent' => $extraCostPercent,
            'extraCostValue' => $extraCostValue,
            'extraCost' => $this->formatCurrency($extraCostValue),
            'grandTotalValue' => $grandTotalValue,
            'totalCostValue' => $grandTotalValue,
            'grandTotal' => $this->formatCurrency($grandTotalValue),
            'totalCost' => $this->formatCurrency($grandTotalValue),
            'paymentAccount' => $context['paymentAccount'] ?? '-',
            'note' => $context['note'] ?? '',
            'lines' => $lines,
        ];
    }

    private function issueRow(InventoryMovement $movement): array
    {
        $context = $this->movementContext($movement);
        $item = $movement->item;
        $trackingType = $item ? $this->trackingType($item) : 'Consumable';
        $quantity = (float) $movement->qty_out;
        $totalValue = $quantity * (float) $movement->unit_cost;

        return [
            'id' => $movement->id,
            'issueDate' => $movement->movement_date instanceof \DateTimeInterface
                ? $movement->movement_date->format('Y-m-d')
                : (string) $movement->movement_date,
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
            $lineEntries = collect($entry['lines'] ?? [])->flatMap(function (array $line) use ($entry) {
                return [
                    [
                        'id' => 'pur-' . $entry['transactionNo'] . '-' . $line['movementId'] . '-dr',
                        'entryDate' => $entry['purchaseDate'],
                        'source' => $entry['transactionNo'],
                        'transactionType' => 'Purchase',
                        'account' => 'Inventory',
                        'position' => 'Debit',
                        'amount' => $line['lineTotal'],
                        'memo' => 'Pembelian ' . $line['itemName'],
                    ],
                    [
                        'id' => 'pur-' . $entry['transactionNo'] . '-' . $line['movementId'] . '-cr',
                        'entryDate' => $entry['purchaseDate'],
                        'source' => $entry['transactionNo'],
                        'transactionType' => 'Purchase',
                        'account' => $entry['paymentAccount'],
                        'position' => 'Credit',
                        'amount' => $line['lineTotal'],
                        'memo' => 'Pembayaran pembelian ' . $line['itemName'],
                    ],
                ];
            });

            if (($entry['extraCostValue'] ?? 0) <= 0) {
                return $lineEntries->all();
            }

            return $lineEntries->concat([
                [
                    'id' => 'pur-' . $entry['transactionNo'] . '-extra-dr',
                    'entryDate' => $entry['purchaseDate'],
                    'source' => $entry['transactionNo'],
                    'transactionType' => 'Purchase',
                    'account' => 'Inventory',
                    'position' => 'Debit',
                    'amount' => $entry['extraCost'],
                    'memo' => 'Biaya lain pembelian ' . $entry['transactionNo'],
                ],
                [
                    'id' => 'pur-' . $entry['transactionNo'] . '-extra-cr',
                    'entryDate' => $entry['purchaseDate'],
                    'source' => $entry['transactionNo'],
                    'transactionType' => 'Purchase',
                    'account' => $entry['paymentAccount'],
                    'position' => 'Credit',
                    'amount' => $entry['extraCost'],
                    'memo' => 'Pembayaran biaya lain ' . $entry['transactionNo'],
                ],
            ])->all();
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
            ->groupBy('reference_id')
            ->map(fn ($group) => $this->purchaseTransactionRow($group))
            ->sortByDesc('purchaseDate')
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
            'deliveryDate' => ['nullable', 'date'],
            'supplier' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'max:20'],
            'exchangeRate' => ['nullable', 'numeric', 'gt:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'paymentAccount' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'extraCostPercent' => ['nullable', 'numeric', 'min:0'],
            'extraCostValue' => ['nullable', 'numeric', 'min:0'],
            'itemId' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unitCostValue' => ['nullable', 'numeric', 'gt:0'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.itemId' => ['required_with:items', 'integer', 'exists:inventory_items,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unitCostValue' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.deliveryDate' => ['nullable', 'date'],
            'items.*.discountPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.note' => ['nullable', 'string'],
            'items.*.costCenter' => ['nullable', 'string', 'max:255'],
            'items.*.project' => ['nullable', 'string', 'max:255'],
        ]);

        $items = collect($payload['items'] ?? []);
        if ($items->isEmpty() && !empty($payload['itemId'])) {
            $items = collect([[
                'itemId' => (int) $payload['itemId'],
                'quantity' => (int) $payload['quantity'],
                'unitCostValue' => (float) $payload['unitCostValue'],
                'deliveryDate' => $payload['deliveryDate'] ?? $payload['purchaseDate'],
                'discountPercent' => 0,
                'note' => '',
                'costCenter' => '',
                'project' => '',
            ]]);
        }

        if ($items->isEmpty()) {
            return response()->json(['message' => 'Minimal satu item harus ditambahkan ke POS pembelian.'], 422);
        }

        $lineNetSubtotal = $items->sum(function (array $entry) {
            $gross = ((float) $entry['quantity']) * ((float) $entry['unitCostValue']);
            $discountPercent = max(0, min(100, (float) ($entry['discountPercent'] ?? 0)));
            $discountValue = ($gross * $discountPercent) / 100;

            return max($gross - $discountValue, 0);
        });

        $extraCostPercent = max(0, (float) ($payload['extraCostPercent'] ?? 0));
        $extraCostValue = isset($payload['extraCostValue'])
            ? (float) $payload['extraCostValue']
            : round(($lineNetSubtotal * $extraCostPercent) / 100, 2);

        $referenceId = 'PUR-' . now()->format('YmdHis');

        DB::transaction(function () use ($items, $payload, $referenceId, $extraCostPercent, $extraCostValue) {
            foreach ($items as $entry) {
                $item = InventoryItem::findOrFail($entry['itemId']);
                $gross = ((float) $entry['quantity']) * ((float) $entry['unitCostValue']);
                $discountPercent = max(0, min(100, (float) ($entry['discountPercent'] ?? 0)));
                $discountValue = round(($gross * $discountPercent) / 100, 2);

                InventoryMovement::create([
                    'item_id' => $item->id,
                    'movement_type' => 'purchase',
                    'qty_in' => (int) $entry['quantity'],
                    'qty_out' => 0,
                    'reference_id' => $referenceId,
                    'reference_type' => 'purchase',
                    'notes' => json_encode([
                        'supplier' => $payload['supplier'],
                        'paymentAccount' => $payload['paymentAccount'],
                        'note' => $payload['note'] ?? '',
                        'status' => $payload['status'] ?? 'Draft',
                        'currency' => $payload['currency'] ?? 'IDR',
                        'exchangeRate' => (float) ($payload['exchangeRate'] ?? 1),
                        'location' => $payload['location'] ?? 'Main Store',
                        'description' => $payload['description'] ?? 'Order Pembelian',
                        'headerDeliveryDate' => $payload['deliveryDate'] ?? $payload['purchaseDate'],
                        'extraCostPercent' => $extraCostPercent,
                        'extraCostValue' => $extraCostValue,
                        'deliveryDate' => $entry['deliveryDate'] ?? ($payload['deliveryDate'] ?? $payload['purchaseDate']),
                        'discountPercent' => $discountPercent,
                        'discountValue' => $discountValue,
                        'lineNote' => $entry['note'] ?? '',
                        'costCenter' => $entry['costCenter'] ?? '',
                        'project' => $entry['project'] ?? '',
                    ], JSON_THROW_ON_ERROR),
                    'movement_date' => $payload['purchaseDate'],
                    'unit_cost' => (float) $entry['unitCostValue'],
                ]);

                $item->standard_cost = (float) $entry['unitCostValue'];
                $item->save();
            }
        });

        $movements = InventoryMovement::query()
            ->with('item')
            ->where('reference_id', $referenceId)
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => "{$referenceId} berhasil diposting sebagai pembelian inventory.",
            'data' => $this->purchaseTransactionRow($movements),
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
