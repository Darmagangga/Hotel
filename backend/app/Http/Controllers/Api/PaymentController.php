<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Booking;
use App\Models\CoaAccount;
use App\Models\Journal;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function index(): JsonResponse
    {
        $payments = Payment::with(['guest', 'paymentAllocations.invoice.booking'])->latest()->get();
        return response()->json(['data' => $payments->map(fn($p) => $this->transformPayment($p))]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'bookingCode' => ['required', 'string', 'exists:bookings,booking_code'],
            'amountValue' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'string'],
            'paymentDate' => ['required', 'date'],
            'referenceNo' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
        ]);

        $booking = Booking::where('booking_code', $payload['bookingCode'])->firstOrFail();
        app(BookingController::class)->syncBookingFinancialState($booking);
        $invoice = Invoice::where('booking_id', $booking->id)->first();

        if (!$invoice) {
            throw ValidationException::withMessages([
                'bookingCode' => ['Invoice untuk reservasi ini tidak ditemukan.'],
            ]);
        }

        if ($payload['amountValue'] > $invoice->balance_due) {
            throw ValidationException::withMessages([
                'amountValue' => ['Nominal pembayaran melebihi saldo terutang invoice.'],
            ]);
        }

        $payment = DB::transaction(function () use ($payload, $invoice, $booking) {
            $paymentCode = $this->generatePaymentCode();
            $cashBankCoaCode = $this->resolveCashBankCoaCode($payload['method']);
            
            $payment = Payment::create([
                'payment_number' => $paymentCode,
                'guest_id' => $booking->guest_id,
                'payment_date' => $payload['paymentDate'],
                'payment_method' => $this->normalizeMethod($payload['method']),
                'amount' => $payload['amountValue'],
                'cash_bank_coa_code' => $cashBankCoaCode,
                'reference_number' => $payload['referenceNo'] ?? null,
                'notes' => $payload['note'] ?? null,
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'allocated_amount' => $payload['amountValue'],
            ]);

            $invoice->paid_amount += $payload['amountValue'];
            $invoice->balance_due = max(0, $invoice->grand_total - $invoice->paid_amount);
            
            if ($invoice->balance_due <= 0) {
                $invoice->status = 'paid';
            } elseif ($invoice->paid_amount > 0) {
                $invoice->status = 'partial';
            }
            $invoice->save();

            $this->syncPaymentAccounting($payment);

            return $payment->load(['guest', 'paymentAllocations.invoice.booking']);
        });

        $this->auditTrailService->record([
            'module' => 'finance',
            'action' => 'payment_posted',
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'entity_label' => $payment->payment_number,
            'description' => "Payment {$payment->payment_number} diposting untuk booking {$booking->booking_code}.",
            'metadata' => [
                'booking_code' => $booking->booking_code,
                'invoice_number' => $invoice->invoice_number,
                'amount' => (float) $payment->amount,
                'method' => $payment->payment_method,
            ],
        ], $request);

        return response()->json([
            'data' => $this->transformPayment($payment),
            'message' => 'Pembayaran berhasil disimpan.',
        ], 201);
    }
    
    private function generatePaymentCode(): string
    {
        $prefix = "PAY-" . date('ymd');
        $lastPayment = Payment::where('payment_number', 'like', "{$prefix}%")
            ->latest('id')
            ->value('payment_number');

        $sequence = $lastPayment ? (int) substr($lastPayment, -3) : 0;
        return sprintf('%s-%03d', $prefix, $sequence + 1);
    }

    private function generateJournalNumber(string $journalDate): string
    {
        $stamp = str_replace('-', '', $journalDate);
        $dailyCount = Journal::query()
            ->whereDate('journal_date', $journalDate)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('JU-%s-%03d', $stamp, $dailyCount);
    }

    private function normalizeMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'cash', 'tunai' => 'cash',
            'bank transfer', 'bank_transfer', 'transfer' => 'bank_transfer',
            'credit card', 'credit_card', 'kartu kredit' => 'credit_card',
            'debit card', 'debit_card', 'kartu debit' => 'debit_card',
            'qris' => 'qris',
            default => 'other',
        };
    }

    private function resolveCashBankCoaCode(string $method): string
    {
        $normalizedMethod = $this->normalizeMethod($method);
        $preferredCode = $normalizedMethod === 'cash' ? '111001' : '111005';

        if (CoaAccount::query()->where('code', $preferredCode)->exists()) {
            return $preferredCode;
        }

        return CoaAccount::query()
            ->where('category', 'Asset')
            ->where('code', 'like', '111%')
            ->orderBy('code')
            ->value('code') ?? '111001';
    }

    private function resolveReceivableCoaCode(): string
    {
        if (CoaAccount::query()->where('code', '112001')->exists()) {
            return '112001';
        }

        return CoaAccount::query()
            ->where('category', 'Asset')
            ->where('code', 'like', '112%')
            ->orderBy('code')
            ->value('code') ?? '112001';
    }

    public function syncPaymentAccounting(Payment $payment): void
    {
        $payment = $payment->fresh(['guest', 'paymentAllocations.invoice.booking.guest']);
        $allocation = $payment->paymentAllocations->first();
        $invoice = $allocation?->invoice;
        $booking = $invoice?->booking;

        if (!$invoice || !$booking) {
            return;
        }

        $cashBankCoaCode = $payment->cash_bank_coa_code ?: $this->resolveCashBankCoaCode((string) $payment->payment_method);
        $receivableCoaCode = $this->resolveReceivableCoaCode();
        if ($payment->cash_bank_coa_code !== $cashBankCoaCode) {
            $payment->update(['cash_bank_coa_code' => $cashBankCoaCode]);
        }

        $journalDate = date('Y-m-d', strtotime((string) $payment->payment_date));
        $journal = Journal::query()->firstOrNew([
            'source' => 'payment',
            'reference_type' => 'payment',
            'reference_id' => $payment->id,
        ]);

        if (!$journal->exists) {
            $journal->journal_number = $this->generateJournalNumber($journalDate);
        }

        $journal->journal_date = $journalDate;
        $journal->description = "Guest payment {$payment->payment_number} for {$invoice->invoice_number}";
        $journal->posted_by = null;
        $journal->save();
        $journal->lines()->delete();

        $amount = (float) $payment->amount;
        $guestName = $booking->guest?->full_name ?? $booking->booking_code;

        $journal->lines()->create([
            'coa_code' => $cashBankCoaCode,
            'line_description' => "Cash receipt from {$guestName}",
            'debit' => $amount,
            'credit' => 0,
        ]);

        $journal->lines()->create([
            'coa_code' => $receivableCoaCode,
            'line_description' => "Settlement invoice {$invoice->invoice_number}",
            'debit' => 0,
            'credit' => $amount,
        ]);
    }

    private function transformPayment(Payment $payment): array
    {
        $allocation = $payment->paymentAllocations->first();
        $invoice = $allocation ? $allocation->invoice : null;
        $bookingCode = $invoice && $invoice->booking ? $invoice->booking->booking_code : '';
        $invoiceNo = $invoice ? $invoice->invoice_number : '';

        return [
            'id' => $payment->id,
            'paymentNumber' => $payment->payment_number,
            'bookingCode' => $bookingCode,
            'invoiceNo' => $invoiceNo,
            'paymentDate' => date('Y-m-d', strtotime($payment->payment_date)),
            'method' => ucfirst(str_replace('_', ' ', $payment->payment_method)),
            'referenceNo' => $payment->reference_number,
            'amountValue' => (float)$payment->amount,
            'amount' => 'IDR ' . number_format((float)$payment->amount, 0, ',', '.'),
            'note' => $payment->notes ?? '',
        ];
    }
}
