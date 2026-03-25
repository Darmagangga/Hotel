<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalController extends Controller
{
    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $search = trim((string) $request->string('search', ''));

        $journals = Journal::query()
            ->with(['lines'])
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($nested) use ($search) {
                    $nested
                        ->where('journal_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reference_type', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('journal_date')
            ->orderByDesc('journal_number')
            ->paginate($perPage)
            ->through(fn (Journal $journal) => $this->transform($journal));

        return response()->json([
            'data' => $journals->items(),
            'meta' => [
                'current_page' => $journals->currentPage(),
                'last_page' => $journals->lastPage(),
                'per_page' => $journals->perPage(),
                'total' => $journals->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$payload, $lines] = $this->validatePayload($request);

        $journal = DB::transaction(function () use ($payload, $lines) {
            $journalDate = $payload['journalDate'];
            $stamp = str_replace('-', '', $journalDate);
            $dailyCount = Journal::query()
                ->whereDate('journal_date', $journalDate)
                ->lockForUpdate()
                ->count() + 1;

            $journal = Journal::create([
                'journal_number' => sprintf('JU-%s-%03d', $stamp, $dailyCount),
                'journal_date' => $journalDate,
                'reference_type' => trim((string) ($payload['referenceNo'] ?? '')) ?: null,
                'description' => trim((string) $payload['description']),
                'source' => 'manual',
                'posted_by' => null,
            ]);

            foreach ($lines as $line) {
                $journal->lines()->create([
                    'coa_code' => $line['coa_code'],
                    'line_description' => $line['memo'] ?: null,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }

            return $journal->load('lines');
        });

        $this->auditTrailService->record([
            'module' => 'journals',
            'action' => 'journal_created',
            'entity_type' => 'journal',
            'entity_id' => $journal->id,
            'entity_label' => $journal->journal_number,
            'description' => "Jurnal manual {$journal->journal_number} dibuat.",
            'metadata' => [
                'journal_date' => $journal->journal_date,
                'line_count' => $journal->lines->count(),
            ],
        ], $request);

        return response()->json([
            'data' => $this->transform($journal),
            'message' => "Jurnal umum {$journal->journal_number} berhasil diposting.",
        ], 201);
    }

    public function update(Request $request, Journal $journal): JsonResponse
    {
        [$payload, $lines] = $this->validatePayload($request);

        $journal = DB::transaction(function () use ($journal, $payload, $lines) {
            $journal->update([
                'journal_date' => $payload['journalDate'],
                'reference_type' => trim((string) ($payload['referenceNo'] ?? '')) ?: null,
                'description' => trim((string) $payload['description']),
                'source' => 'manual',
            ]);

            $journal->lines()->delete();

            foreach ($lines as $line) {
                $journal->lines()->create([
                    'coa_code' => $line['coa_code'],
                    'line_description' => $line['memo'] ?: null,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }

            return $journal->fresh('lines');
        });

        $this->auditTrailService->record([
            'module' => 'journals',
            'action' => 'journal_updated',
            'entity_type' => 'journal',
            'entity_id' => $journal->id,
            'entity_label' => $journal->journal_number,
            'description' => "Jurnal manual {$journal->journal_number} diperbarui.",
            'metadata' => [
                'journal_date' => $journal->journal_date,
                'line_count' => $journal->lines->count(),
            ],
        ], $request);

        return response()->json([
            'data' => $this->transform($journal),
            'message' => "Jurnal umum {$journal->journal_number} berhasil diperbarui.",
        ]);
    }

    private function validatePayload(Request $request): array
    {
        $payload = $request->validate([
            'journalDate' => ['required', 'date'],
            'referenceNo' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account' => ['required', 'string', 'max:200'],
            'lines.*.debitValue' => ['nullable', 'numeric', 'min:0'],
            'lines.*.creditValue' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = collect($payload['lines'])
            ->map(function (array $line, int $index) {
                $account = trim((string) ($line['account'] ?? ''));
                $coaCode = trim(strtok($account, '-'));
                $debitValue = round((float) ($line['debitValue'] ?? 0), 2);
                $creditValue = round((float) ($line['creditValue'] ?? 0), 2);

                return [
                    'row' => $index + 1,
                    'account' => $account,
                    'coa_code' => $coaCode,
                    'debit' => $debitValue,
                    'credit' => $creditValue,
                    'memo' => trim((string) ($line['memo'] ?? '')),
                ];
            })
            ->filter(fn (array $line) => $line['account'] !== '' && ($line['debit'] > 0 || $line['credit'] > 0))
            ->values();

        if ($lines->count() < 2) {
            throw ValidationException::withMessages([
                'lines' => 'Minimal dua baris jurnal yang valid wajib diisi.',
            ]);
        }

        $hasInvalidLine = $lines->contains(fn (array $line) => $line['debit'] > 0 && $line['credit'] > 0);

        if ($hasInvalidLine) {
            throw ValidationException::withMessages([
                'lines' => 'Satu baris jurnal hanya boleh berisi debit atau kredit.',
            ]);
        }

        $debitTotal = round($lines->sum('debit'), 2);
        $creditTotal = round($lines->sum('credit'), 2);

        if ($debitTotal <= 0 || $creditTotal <= 0 || abs($debitTotal - $creditTotal) > 0.000001) {
            throw ValidationException::withMessages([
                'lines' => 'Total debit dan kredit harus seimbang.',
            ]);
        }

        return [$payload, $lines];
    }

    private function transform(Journal $journal): array
    {
        $debitTotal = round((float) $journal->lines->sum('debit'), 2);
        $creditTotal = round((float) $journal->lines->sum('credit'), 2);

        return [
            'id' => $journal->id,
            'journalNo' => $journal->journal_number,
            'journalDate' => $journal->journal_date,
            'referenceNo' => $journal->reference_type ?? '',
            'description' => $journal->description,
            'debitTotalValue' => $debitTotal,
            'creditTotalValue' => $creditTotal,
            'lineCount' => $journal->lines->count(),
            'lines' => $journal->lines->map(fn ($line) => [
                'id' => $line->id,
                'coaCode' => $line->coa_code,
                'debitValue' => (float) $line->debit,
                'creditValue' => (float) $line->credit,
                'memo' => $line->line_description ?? '',
            ])->values(),
        ];
    }
}
