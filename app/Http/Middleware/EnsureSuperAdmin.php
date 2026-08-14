<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('super_admin')) {
            return response()->json([
                'message' => 'Akses ditolak. Hanya Super Admin yang dapat mengakses fitur ini.',
            ], 403);
        }

        return $next($request);
    }
}
