<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditTrailController extends Controller
{
    public function index(Request $request, AuditTrailService $auditTrailService): JsonResponse
    {
        $auditTrailService->ensureTableExists();

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $search = trim((string) $request->string('search', ''));
        $module = trim((string) $request->string('module', ''));

        $logs = AuditTrail::query()
            ->when($module !== '', fn ($query) => $query->where('module', $module))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested
                        ->where('user_name', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%")
                        ->orWhere('entity_label', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $logs->through(fn (AuditTrail $log) => [
                'id' => $log->id,
                'createdAt' => optional($log->created_at)?->format('Y-m-d H:i:s'),
                'module' => $log->module,
                'action' => $log->action,
                'userName' => $log->user_name ?: 'System',
                'userEmail' => $log->user_email ?: '-',
                'userRole' => $log->user_role ?: '-',
                'entityType' => $log->entity_type ?: '-',
                'entityId' => $log->entity_id ?: '-',
                'entityLabel' => $log->entity_label ?: '-',
                'description' => $log->description,
                'metadata' => $log->metadata ?: [],
                'ipAddress' => $log->ip_address ?: '-',
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
