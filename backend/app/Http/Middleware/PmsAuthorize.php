<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class PmsAuthorize
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $payload = json_decode(Crypt::decryptString($bearerToken), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Invalid session token.'], 401);
        }

        $permissions = is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [];
        $role = (string) ($payload['role'] ?? '');

        if ($permission && $role !== 'admin' && !in_array($permission, $permissions, true)) {
            return response()->json(['message' => 'Forbidden. Anda tidak memiliki akses ke resource ini.'], 403);
        }

        $request->attributes->set('pms_user', $payload);

        return $next($request);
    }
}
