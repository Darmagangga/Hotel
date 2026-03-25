<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditTrailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class RoleController extends Controller
{
    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function index()
    {
        $this->ensurePermissionsColumn();

        $roles = DB::table('roles')->get()->map(function ($r) {
            $r->permissions = $r->permissions ? json_decode($r->permissions, true) : [];
            return $r;
        });

        return response()->json($roles);
    }

    public function updatePermissions(Request $request, $id)
    {
        $this->ensurePermissionsColumn();

        $request->validate([
            'permissions' => 'required|array'
        ]);

        DB::table('roles')->where('id', $id)->update([
            'permissions' => json_encode($request->permissions),
            'updated_at' => now(),
        ]);

        $role = DB::table('roles')->where('id', $id)->first();
        $this->auditTrailService->record([
            'module' => 'roles',
            'action' => 'permissions_updated',
            'entity_type' => 'role',
            'entity_id' => $id,
            'entity_label' => $role?->name ?? (string) $id,
            'description' => "Hak akses role " . ($role?->name ?? $id) . ' diperbarui.',
            'metadata' => [
                'permissions' => $request->permissions,
            ],
        ], $request);

        return response()->json(['message' => 'Hak akses berhasil diperbarui!']);
    }

    private function ensurePermissionsColumn()
    {
        try {
            DB::table('roles')->select('permissions')->take(1)->get();
        } catch (\Exception $e) {
            DB::statement('ALTER TABLE roles ADD COLUMN permissions LONGTEXT NULL AFTER name');
            
            DB::table('roles')->where('name', 'admin')->update(['permissions' => json_encode(['dashboard', 'bookings', 'rooms', 'finance', 'journals', 'coa', 'inventory', 'transport', 'activities', 'reports', 'users', 'roles'])]);
            DB::table('roles')->where('name', 'frontdesk')->update(['permissions' => json_encode(['dashboard', 'bookings', 'rooms', 'activities'])]);
            DB::table('roles')->where('name', 'housekeeping')->update(['permissions' => json_encode(['rooms', 'inventory'])]);
        }
    }
}
