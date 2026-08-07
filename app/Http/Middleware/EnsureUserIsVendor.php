<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsVendor
{
    private const VENDOR_ROLES = [
        'vendor_company_admin',
        'vendor_ops_pic',
        'vendor_finance_pic',
        'vendor_viewer',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isVendor() && $user->company_id && $user->hasAnyRole(self::VENDOR_ROLES)) {
            return $next($request);
        }

        // Samarkan 403 sebagai 404 untuk mencegah enumerasi resource.
        return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
    }
}
