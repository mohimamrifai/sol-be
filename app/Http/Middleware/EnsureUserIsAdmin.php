<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    private const ADMIN_ROLES = ['super_admin', 'operations', 'finance', 'sales'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isInternal() || $user->hasAnyRole(self::ADMIN_ROLES)) {
            return $next($request);
        }

        return response()->json(['message' => 'Akses ditolak. Hanya untuk tim internal.'], 403);
    }
}
