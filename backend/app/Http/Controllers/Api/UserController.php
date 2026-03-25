<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\AuditTrailService;

class UserController extends Controller
{
    public function __construct(private readonly AuditTrailService $auditTrailService)
    {
    }

    public function index()
    {
        // Pastikan tabel roles terisi secara otomatis jika kosong
        $this->ensureRolesExist();

        $users = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->select('users.id', 'users.name', 'users.email', 'users.is_active', 'users.role_id', 'roles.name as role')
            ->get();

        return response()->json($users);
    }

    public function roles()
    {
        $this->ensureRolesExist();
        return response()->json(DB::table('roles')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role_id' => 'required|integer|exists:roles,id'
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $request->role_id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $createdUser = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->select('users.id', 'users.name', 'users.email', 'roles.name as role')
            ->where('users.id', $userId)
            ->first();

        $this->auditTrailService->record([
            'module' => 'users',
            'action' => 'user_created',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'entity_label' => $createdUser?->email ?? $request->email,
            'description' => "Akun staf {$request->email} dibuat.",
            'metadata' => [
                'name' => $createdUser?->name ?? $request->name,
                'role' => $createdUser?->role ?? null,
            ],
        ], $request);

        return response()->json([
            'message' => 'Akun staf berhasil dibuat!',
            'user_id' => $userId
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|unique:users,email,' . $id,
            'role_id' => 'required|integer|exists:roles,id',
            'is_active' => 'sometimes|boolean'
        ]);

        $updateData = [
            'name' => $request->name,
            'email' => $request->email,
            'role_id' => $request->role_id,
            'updated_at' => now(),
        ];

        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->is_active;
        }

        if ($request->filled('password')) {
            $request->validate(['password' => 'string|min:6']);
            $updateData['password'] = Hash::make($request->password);
        }

        DB::table('users')->where('id', $id)->update($updateData);
        $updatedUser = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->select('users.id', 'users.name', 'users.email', 'users.is_active', 'roles.name as role')
            ->where('users.id', $id)
            ->first();

        $this->auditTrailService->record([
            'module' => 'users',
            'action' => 'user_updated',
            'entity_type' => 'user',
            'entity_id' => $id,
            'entity_label' => $updatedUser?->email ?? $request->email,
            'description' => "Akun staf {$request->email} diperbarui.",
            'metadata' => [
                'name' => $updatedUser?->name ?? $request->name,
                'role' => $updatedUser?->role ?? null,
                'is_active' => (bool) ($updatedUser?->is_active ?? $request->boolean('is_active', true)),
            ],
        ], $request);

        return response()->json(['message' => 'Akun staf berhasil diperbarui!']);
    }

    public function toggleStatus(Request $request, $id)
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'Tamu tidak ditemukan.'], 404);
        }

        $newStatus = $user->is_active ? 0 : 1;
        DB::table('users')->where('id', $id)->update(['is_active' => $newStatus, 'updated_at' => now()]);

        $statusText = $newStatus ? 'diaktifkan' : 'dinonaktifkan';
        $this->auditTrailService->record([
            'module' => 'users',
            'action' => 'user_status_toggled',
            'entity_type' => 'user',
            'entity_id' => $id,
            'entity_label' => $user->email,
            'description' => "Status akun staf {$user->email} {$statusText}.",
            'metadata' => [
                'is_active' => (bool) $newStatus,
            ],
        ], $request);

        return response()->json(['message' => "Akun staf berhasil $statusText!"]);
    }

    private function ensureRolesExist()
    {
        $rolesCount = DB::table('roles')->count();
        if ($rolesCount === 0) {
            DB::table('roles')->insert([
                ['name' => 'admin', 'created_at' => now()],
                ['name' => 'frontdesk', 'created_at' => now()],
                ['name' => 'housekeeping', 'created_at' => now()],
            ]);
        }
    }
}
