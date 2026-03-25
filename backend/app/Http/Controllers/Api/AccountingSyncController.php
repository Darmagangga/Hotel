<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\PaymentAllocation;
use App\Models\Payment;
use App\Services\AuditTrailService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountingSyncController extends Controller
{
    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function reconciliationReport(): JsonResponse
    {
        $bookingRows = Booking::query()
            ->orderByDesc('id')
            ->get()
            ->map(function (Booking $booking) {
                $invoice = Invoice::query()->where('booking_id', $booking->id)->first();
                $invoiceJournal = Journal::query()
                    ->with('lines')
                    ->where('source', 'invoice')
                    ->where('reference_type', 'booking')
                    ->where('reference_id', $booking->id)
                    ->first();

                $allocatedPaymentTotal = (float) PaymentAllocation::query()
                    ->when($invoice, fn ($query) => $query->where('invoice_id', $invoice->id), fn ($query) => $query->whereRaw('1 = 0'))
                    ->sum('allocated_amount');

                $journalReceivableDebit = (float) optional($invoiceJournal)->lines?->where('debit', '>', 0)->sum('debit');
                $journalRevenueCredit = (float) optional($invoiceJournal)->lines?->where('credit', '>', 0)->sum('credit');
                $expectedBalance = $invoice ? max(0, (float) $invoice->grand_total - (float) $invoice->paid_amount) : 0;

                $issues = [];
                if (!$invoice) {
                    $issues[] = 'Missing invoice';
                }
                if (!$invoiceJournal) {
                    $issues[] = 'Missing booking journal';
                }
                if ($invoice && abs((float) $booking->grand_total - (float) $invoice->grand_total) > 0.000001) {
                    $issues[] = 'Booking total vs invoice total mismatch';
                }
                if ($invoice && abs((float) $invoice->paid_amount - $allocatedPaymentTotal) > 0.000001) {
                    $issues[] = 'Invoice paid amount vs payment allocations mismatch';
                }
                if ($invoice && abs((float) $invoice->balance_due - $expectedBalance) > 0.000001) {
                    $issues[] = 'Invoice balance mismatch';
                }
                if ($invoiceJournal && abs($journalReceivableDebit - $journalRevenueCredit) > 0.000001) {
                    $issues[] = 'Booking journal unbalanced';
                }
                if ($invoiceJournal && $invoice && abs($journalReceivableDebit - (float) $invoice->grand_total) > 0.000001) {
                    $issues[] = 'Booking journal vs invoice total mismatch';
                }

                return [
                    'bookingCode' => $booking->booking_code,
                    'bookingGrandTotal' => (float) $booking->grand_total,
                    'invoiceNo' => $invoice?->invoice_number ?? '',
                    'invoiceGrandTotal' => $invoice ? (float) $invoice->grand_total : null,
                    'invoicePaidAmount' => $invoice ? (float) $invoice->paid_amount : null,
                    'invoiceBalanceDue' => $invoice ? (float) $invoice->balance_due : null,
                    'allocatedPaymentTotal' => $allocatedPaymentTotal,
                    'hasInvoiceJournal' => (bool) $invoiceJournal,
                    'journalReceivableDebit' => $journalReceivableDebit,
                    'journalRevenueCredit' => $journalRevenueCredit,
                    'issueCount' => count($issues),
                    'issues' => $issues,
                ];
            });

        $paymentRows = Payment::query()
            ->orderByDesc('id')
            ->with('paymentAllocations.invoice.booking')
            ->get()
            ->map(function (Payment $payment) {
                $paymentJournal = Journal::query()
                    ->with('lines')
                    ->where('source', 'payment')
                    ->where('reference_type', 'payment')
                    ->where('reference_id', $payment->id)
                    ->first();

                $allocatedAmount = (float) $payment->paymentAllocations->sum('allocated_amount');
                $debitTotal = (float) optional($paymentJournal)->lines?->sum('debit');
                $creditTotal = (float) optional($paymentJournal)->lines?->sum('credit');

                $issues = [];
                if ($payment->paymentAllocations->isEmpty()) {
                    $issues[] = 'Missing payment allocation';
                }
                if (!$paymentJournal) {
                    $issues[] = 'Missing payment journal';
                }
                if (abs((float) $payment->amount - $allocatedAmount) > 0.000001) {
                    $issues[] = 'Payment amount vs allocations mismatch';
                }
                if ($paymentJournal && abs($debitTotal - $creditTotal) > 0.000001) {
                    $issues[] = 'Payment journal unbalanced';
                }
                if ($paymentJournal && abs($debitTotal - (float) $payment->amount) > 0.000001) {
                    $issues[] = 'Payment journal vs payment amount mismatch';
                }

                $allocation = $payment->paymentAllocations->first();

                return [
                    'paymentNumber' => $payment->payment_number,
                    'bookingCode' => $allocation?->invoice?->booking?->booking_code ?? '',
                    'invoiceNo' => $allocation?->invoice?->invoice_number ?? '',
                    'amount' => (float) $payment->amount,
                    'allocatedAmount' => $allocatedAmount,
                    'hasPaymentJournal' => (bool) $paymentJournal,
                    'journalDebit' => $debitTotal,
                    'journalCredit' => $creditTotal,
                    'issueCount' => count($issues),
                    'issues' => $issues,
                ];
            });

        return response()->json([
            'data' => [
                'summary' => [
                    'booking_issue_count' => $bookingRows->where('issueCount', '>', 0)->count(),
                    'payment_issue_count' => $paymentRows->where('issueCount', '>', 0)->count(),
                    'bookings_checked' => $bookingRows->count(),
                    'payments_checked' => $paymentRows->count(),
                ],
                'bookingRows' => $bookingRows->take(100)->values(),
                'paymentRows' => $paymentRows->take(100)->values(),
            ],
        ]);
    }

    public function syncHistoricalData(Request $request): JsonResponse
    {
        $bookingController = app(BookingController::class);
        $paymentController = app(PaymentController::class);

        $bookingCount = 0;
        $paymentCount = 0;

        DB::transaction(function () use ($bookingController, $paymentController, &$bookingCount, &$paymentCount) {
            Booking::query()
                ->orderBy('id')
                ->chunkById(100, function ($bookings) use ($bookingController, &$bookingCount) {
                    foreach ($bookings as $booking) {
                        $bookingController->syncBookingFinancialState($booking);
                        $bookingCount++;
                    }
                });

            Payment::query()
                ->orderBy('id')
                ->chunkById(100, function ($payments) use ($paymentController, &$paymentCount) {
                    foreach ($payments as $payment) {
                        $paymentController->syncPaymentAccounting($payment);
                        $paymentCount++;
                    }
                });
        });

        $this->auditTrailService->record([
            'module' => 'finance',
            'action' => 'sync_history',
            'entity_type' => 'accounting_sync',
            'entity_id' => now()->format('YmdHis'),
            'entity_label' => 'historical-accounting-sync',
            'description' => 'Sinkronisasi accounting historis dijalankan.',
            'metadata' => [
                'bookings_synced' => $bookingCount,
                'payments_synced' => $paymentCount,
            ],
        ], $request);

        return response()->json([
            'message' => 'Sinkronisasi accounting historis berhasil dijalankan.',
            'data' => [
                'bookings_synced' => $bookingCount,
                'payments_synced' => $paymentCount,
            ],
        ]);
    }
}
