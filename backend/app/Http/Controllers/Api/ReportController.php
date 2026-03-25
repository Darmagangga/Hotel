<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\CoaAccount;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Journal;
use App\Models\Payment;

class ReportController extends Controller
{
    private function roomRevenueTotal(): float
    {
        return (float) Booking::whereNotIn('status', ['cancelled', 'no_show'])->sum('room_amount');
    }

    private function addonRevenueTotal(): float
    {
        return (float) BookingAddon::where('status', '!=', 'cancelled')->sum('total_price');
    }

    private function paymentInflowTotal(): float
    {
        return (float) Payment::sum('amount');
    }

    private function journaledPaymentTotal(): float
    {
        return (float) JournalLine::query()
            ->where('debit', '>', 0)
            ->whereHas('journal', fn ($query) => $query->where('source', 'payment'))
            ->sum('debit');
    }

    private function journaledBookingIds(): array
    {
        return Journal::query()
            ->where('source', 'invoice')
            ->whereNotNull('reference_id')
            ->pluck('reference_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function getCoaBalances(): array
    {
        $coas = CoaAccount::all();
        $balances = JournalLine::selectRaw('coa_code, sum(debit) as total_debit, sum(credit) as total_credit')
            ->groupBy('coa_code')
            ->get()
            ->keyBy('coa_code')
            ->toArray();

        // --------------------------------------------------------------------------------
        // Virtual Ledger Integration (PMS Operations)
        // --------------------------------------------------------------------------------
        $journaledBookingIds = $this->journaledBookingIds();
        $fallbackBookingQuery = Booking::query()
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->when(!empty($journaledBookingIds), fn ($query) => $query->whereNotIn('id', $journaledBookingIds));
        $roomRevenue = (float) (clone $fallbackBookingQuery)->sum('room_amount');
        $addonRevenue = (float) (clone $fallbackBookingQuery)->sum('addon_amount');
        $totalPayments = $this->paymentInflowTotal();
        $journaledPayments = $this->journaledPaymentTotal();
        $unjournaledPayments = max(0, $totalPayments - $journaledPayments);
        $totalReceivable = (float) Invoice::query()
            ->when(!empty($journaledBookingIds), fn ($query) => $query->whereNotIn('booking_id', $journaledBookingIds))
            ->sum('balance_due');

        $virtualLedger = [
            '411001' => ['dr' => 0, 'cr' => $roomRevenue],     // Pendapatan Kamar (Credit)
            '412000' => ['dr' => 0, 'cr' => $addonRevenue],    // Pendapatan F&B / Layanan (Credit)
            '111001' => ['dr' => $unjournaledPayments, 'cr' => 0], // Kas fallback untuk payment lama
            '112001' => ['dr' => $totalReceivable, 'cr' => 0],     // Piutang Tamu / AR
        ];

        foreach ($virtualLedger as $code => $v) {
            if (!isset($balances[$code])) {
                $balances[$code] = ['coa_code' => $code, 'total_debit' => 0, 'total_credit' => 0];
            }
            $balances[$code]['total_debit'] += $v['dr'];
            $balances[$code]['total_credit'] += $v['cr'];
        }
        // --------------------------------------------------------------------------------

        $result = [];
        foreach ($coas as $coa) {
            $line = $balances[$coa->code] ?? null;
            $debit = $line ? (float)$line['total_debit'] : 0;
            $credit = $line ? (float)$line['total_credit'] : 0;
            
            if ($coa->normal_balance === 'debit' || strtolower($coa->normal_balance) === 'dr') {
                $balance = $debit - $credit;
            } else {
                $balance = $credit - $debit;
            }
            
            // To make reports look alive even if journals aren't fully utilized yet, 
            // we will pass the calculated balances.
            $result[] = [
                'code' => $coa->code,
                'name' => $coa->account_name,
                'category' => $coa->category,
                'normal_balance' => $coa->normal_balance,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance
            ];
        }
        
        return $result;
    }

    public function balanceSheet(): JsonResponse
    {
        $coas = collect($this->getCoaBalances());
        
        $assets = $coas->where('category', 'Asset')->values();
        $liabilities = $coas->where('category', 'Liability')->values();
        $equities = $coas->where('category', 'Equity')->values();

        $totalAssets = $assets->sum('balance');
        $totalLiabilities = $liabilities->sum('balance');
        $totalEquities = $equities->sum('balance');

        return response()->json([
            'data' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equities' => $equities,
                'total_asset' => $totalAssets,
                'total_liability_and_equity' => $totalLiabilities + $totalEquities
            ]
        ]);
    }

    public function profitLoss(): JsonResponse
    {
        $coas = collect($this->getCoaBalances());
        
        $revenues = $coas->where('category', 'Revenue')->values();
        $expenses = $coas->where('category', 'Expense')->values();

        $roomRevenue = $this->roomRevenueTotal();
        $addonRevenue = $this->addonRevenueTotal();
        $totalRevenue = $roomRevenue + $addonRevenue;
        $totalExpense = $expenses->sum('balance');
        $netProfit = $totalRevenue - $totalExpense;

        return response()->json([
            'data' => [
                'revenue' => [
                    'room' => $roomRevenue,
                    'addon' => $addonRevenue,
                    'total' => $totalRevenue,
                ],
                'expense' => [
                    'total' => $totalExpense,
                ],
                'netProfit' => $netProfit,
                'revenues' => $revenues,
                'expenses' => $expenses,
                'total_revenue' => $totalRevenue,
                'total_expense' => $totalExpense,
                'net_profit' => $netProfit
            ]
        ]);
    }

    public function cashFlow(): JsonResponse
    {
        // Simple Cash Flow based on Asset (Cash/Bank) journal legs. 
        // Operating, Investing, Financing activities require tagging.
        // For a basic layout, we'll list transactions affecting cash.
        
        // Let's identify Cash COAs (typically Asset accounts that start with 111)
        $cashCoaCodes = CoaAccount::where('category', 'Asset')
            ->where('code', 'like', '111%') // Cash equivalents
            ->pluck('code');
            
        $inflowsQuery = JournalLine::whereIn('coa_code', $cashCoaCodes)
            ->where('debit', '>', 0)
            ->whereHas('journal', fn ($query) => $query
                ->where(function ($nested) {
                    $nested->where('source', '!=', 'payment')->orWhereNull('source');
                }));
        $trueManualCashIn = $inflowsQuery->sum('debit');
        
        $inflows = $inflowsQuery->with(['journal', 'coa'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(function($line) {
                return [
                    'date' => $line->journal->journal_date ?? '',
                    'description' => $line->line_description ?: ($line->journal->description ?? 'Cash Inflow (Manual Journal)'),
                    'amount' => (float)$line->debit,
                    'coa' => $line->coa->account_name ?? $line->coa_code
                ];
            });

        $journaledPaymentInflow = JournalLine::whereIn('coa_code', $cashCoaCodes)
            ->where('debit', '>', 0)
            ->whereHas('journal', fn ($query) => $query->where('source', 'payment'))
            ->sum('debit');

        $journaledPaymentEntries = JournalLine::whereIn('coa_code', $cashCoaCodes)
            ->where('debit', '>', 0)
            ->whereHas('journal', fn ($query) => $query->where('source', 'payment'))
            ->with(['journal', 'coa'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(function($line) {
                return [
                    'date' => $line->journal->journal_date ?? '',
                    'description' => $line->line_description ?: ($line->journal->description ?? 'Guest Payment'),
                    'amount' => (float)$line->debit,
                    'coa' => $line->coa->account_name ?? $line->coa_code
                ];
            });

        $journaledPaymentIds = Journal::query()
            ->where('source', 'payment')
            ->whereNotNull('reference_id')
            ->pluck('reference_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pmsPayments = Payment::query()
            ->when(!empty($journaledPaymentIds), fn ($query) => $query->whereNotIn('id', $journaledPaymentIds))
            ->latest()
            ->limit(50)
            ->get()
            ->map(function($line) {
            return [
                'date' => date('Y-m-d', strtotime($line->payment_date)),
                'description' => 'Guest Payment - ' . $line->payment_number,
                'amount' => (float)$line->amount,
                'coa' => 'Kas / Bank (PMS)'
            ];
        });

        $fallbackPayments = (float) $pmsPayments->sum('amount');

        // Combine and limit inflows
        $inflows = $inflows->concat($journaledPaymentEntries)->concat($pmsPayments)->sortByDesc('date')->take(50)->values();

        $outflowsQuery = JournalLine::whereIn('coa_code', $cashCoaCodes)->where('credit', '>', 0);
        $totalOutflow = $outflowsQuery->sum('credit');

        $outflows = $outflowsQuery->with(['journal', 'coa'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(function($line) {
                return [
                    'date' => $line->journal->journal_date ?? '',
                    'description' => $line->line_description ?: ($line->journal->description ?? 'Cash Outflow'),
                    'amount' => (float)$line->credit,
                    'coa' => $line->coa->account_name ?? $line->coa_code
                ];
            });

        $guestPayments = $journaledPaymentInflow + $fallbackPayments;
        $totalInflow = $trueManualCashIn + $guestPayments;
        $netCashFlow = $totalInflow - $totalOutflow;

        return response()->json([
            'data' => [
                'inflow' => [
                    'guest_payments' => $guestPayments,
                    'manual_journals' => $trueManualCashIn,
                    'total' => $totalInflow,
                ],
                'outflow' => [
                    'expenses' => $totalOutflow,
                    'total' => $totalOutflow,
                ],
                'netCashFlow' => $netCashFlow,
                'inflows' => $inflows,
                'outflows' => $outflows,
                'total_inflow' => $totalInflow,
                'total_outflow' => $totalOutflow,
                'net_cash_flow' => $netCashFlow
            ]
        ]);
    }
}
